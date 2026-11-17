<?php
// ボックスガチャの共通処理（htdocs/box_gacha/ の画面から使う）
//
// ボックスガチャ = 箱の中に決まった数のアイテムが入っていて、引いたアイテムは箱から減っていく形式。
// 「残数を重み」として通常ガチャと同じ draw_one() で抽選し、当たったアイテムの残数を 1 減らす。
// 箱を引き切れば、最初に入れた数の通りに全部手に入る。
require_once __DIR__ . '/gacha_lib.php';

const BOX_GACHA_ID = 4; // gachas.id（type = 'box'）

/**
 * 同じユーザーのボックス操作（抽選・リセット）を1つずつ順番に実行させるためのロック
 *
 * users の行を FOR UPDATE でロックすると、同じユーザーの別リクエストは
 * このトランザクションが終わる（commit / rollBack）まで待たされる。
 * ※ ボックスの行を直接ロックするだけだと、INSERT IGNORE（共有ロック）→ FOR UPDATE（排他ロック）の
 *   順にロックを強めるところで、同時リクエスト同士がお互いを待ってデッドロックになる。
 */
function lock_user(PDO $pdo, int $user_id): void
{
    $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? FOR UPDATE");
    $stmt->execute([$user_id]);
}

/**
 * ユーザーのボックスがまだ無ければ、gacha_items.weight（初期個数）から作る
 * INSERT IGNORE なので、すでにある場合は何もしない
 */
function init_box(PDO $pdo, int $user_id, int $gacha_id): void
{
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO user_box_items (user_id, gacha_id, card_id, remaining)
        SELECT ?, gacha_id, card_id, weight
        FROM gacha_items
        WHERE gacha_id = ?
    ");
    $stmt->execute([$user_id, $gacha_id]);
}

/**
 * ボックスの中身（残数・初期個数）を取得する
 */
function fetch_box(PDO $pdo, int $user_id, int $gacha_id): array
{
    $sql = "
        SELECT ub.card_id, c.name, c.rarity, c.image, c.cutin_image, ub.remaining, gi.weight AS initial
        FROM user_box_items ub
        JOIN gacha_items gi ON gi.gacha_id = ub.gacha_id AND gi.card_id = ub.card_id
        JOIN cards c ON c.id = ub.card_id
        WHERE ub.user_id = ? AND ub.gacha_id = ?
        ORDER BY ub.card_id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id, $gacha_id]);
    return $stmt->fetchAll();
}

/**
 * 画面表示用: まだ一度も引いていない場合は、満タンの箱として中身を返す
 */
function fetch_box_for_view(PDO $pdo, int $user_id, int $gacha_id): array
{
    $box = fetch_box($pdo, $user_id, $gacha_id);
    if ($box) {
        return $box;
    }
    $stmt = $pdo->prepare("
        SELECT gi.card_id, c.name, c.rarity, c.image, c.cutin_image, gi.weight AS remaining, gi.weight AS initial
        FROM gacha_items gi
        JOIN cards c ON c.id = gi.card_id
        WHERE gi.gacha_id = ?
        ORDER BY gi.card_id
    ");
    $stmt->execute([$gacha_id]);
    return $stmt->fetchAll();
}

/**
 * ボックスガチャを $count 回引く
 * 箱の残りが $count 未満のときは引かずにエラーにする
 */
function execute_box_gacha(PDO $pdo, int $user_id, int $gacha_id, int $count): array
{
    $now = date('Y-m-d H:i:s');
    require_open_gacha($pdo, $user_id, $gacha_id, 'box', $now);

    $pdo->beginTransaction();
    try {
        lock_user($pdo, $user_id);
        init_box($pdo, $user_id, $gacha_id);
        $box = fetch_box($pdo, $user_id, $gacha_id);

        $left = array_sum(array_column($box, 'remaining'));
        if ($left < $count) {
            throw new GachaException("ボックスの残りが {$left} 個のため、{$count} 回は引けません。");
        }

        // 残数を重みにして抽選し、当たったものを1つ減らす
        $remaining = array_column($box, 'remaining', 'card_id');
        $cards = array_column($box, null, 'card_id');
        $results = [];
        for ($i = 0; $i < $count; $i++) {
            $items = [];
            foreach ($remaining as $card_id => $n) {
                $items[] = [
                    'card_id' => $card_id,
                    'name'    => $cards[$card_id]['name'],
                    'rarity'  => $cards[$card_id]['rarity'],
                    'image'   => $cards[$card_id]['image'],
                    'cutin_image' => $cards[$card_id]['cutin_image'],
                    'weight'  => $n,
                ];
            }
            $hit = draw_one($items);
            $remaining[$hit['card_id']]--;
            $results[] = $hit;
        }

        $stmt = $pdo->prepare("
            UPDATE user_box_items SET remaining = ?
            WHERE user_id = ? AND gacha_id = ? AND card_id = ?
        ");
        foreach ($remaining as $card_id => $n) {
            $stmt->execute([$n, $user_id, $gacha_id, $card_id]);
        }

        $draw_id = save_draw_results($pdo, $user_id, $gacha_id, $results, $now);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        if (!$e instanceof GachaException) {
            gacha_log('ボックスガチャの保存に失敗', ['user_id' => $user_id, 'gacha_id' => $gacha_id, 'error' => $e->getMessage()]);
        }
        throw $e;
    }

    gacha_log('ボックスガチャ実行', [
        'user_id'  => $user_id,
        'gacha_id' => $gacha_id,
        'draw_id'  => $draw_id,
        'count'    => $count,
        'card_ids' => array_column($results, 'card_id'),
        'left'     => $left - $count,
    ]);

    return $results;
}

/**
 * ボックスを最初の状態に戻す
 */
function reset_box(PDO $pdo, int $user_id, int $gacha_id): void
{
    $now = date('Y-m-d H:i:s');
    require_open_gacha($pdo, $user_id, $gacha_id, 'box', $now);

    $pdo->beginTransaction();
    try {
        lock_user($pdo, $user_id);
        $stmt = $pdo->prepare("DELETE FROM user_box_items WHERE user_id = ? AND gacha_id = ?");
        $stmt->execute([$user_id, $gacha_id]);
        init_box($pdo, $user_id, $gacha_id);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    gacha_log('ボックスリセット', ['user_id' => $user_id, 'gacha_id' => $gacha_id]);
}
