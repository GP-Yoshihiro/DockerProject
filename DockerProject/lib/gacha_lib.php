<?php
// ガチャの共通処理（htdocs/gacha/ と htdocs/box_gacha/ の画面から使う）
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/card_lib.php';

date_default_timezone_set('Asia/Tokyo');

const GACHA_DRAW_COUNT = 10; // 10連

/** 画面にそのまま表示してよいガチャのエラー（期間外など） */
class GachaException extends Exception {}

/**
 * ログ出力
 * コンテナの標準エラー出力に書き出すので `docker compose logs web` で確認できる。
 * （error_log() だと Apache が日本語を \xe3\x82... のようにエスケープしてしまうため）
 */
function gacha_log(string $message, array $context = []): void
{
    $line = sprintf('[%s] [gacha] %s', date('Y-m-d H:i:s'), $message);
    if ($context) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
    }
    file_put_contents('php://stderr', $line . PHP_EOL);
}

/**
 * 開催期間中のガチャを1件取得する（期間外・存在しない場合は null）
 */
function find_open_gacha(PDO $pdo, int $gacha_id, string $now): ?array
{
    $stmt = $pdo->prepare("
        SELECT id, name, type, start_at, end_at
        FROM gachas
        WHERE id = ?
          AND start_at <= ?
          AND (end_at IS NULL OR end_at >= ?)
    ");
    $stmt->execute([$gacha_id, $now, $now]);
    $gacha = $stmt->fetch();
    return $gacha ?: null;
}

/**
 * ガチャのラインナップ（カード名・重み付き）を取得する
 */
function fetch_gacha_items(PDO $pdo, int $gacha_id): array
{
    $stmt = $pdo->prepare("
        SELECT gi.card_id, c.name, c.image, gi.weight
        FROM gacha_items gi
        JOIN cards c ON gi.card_id = c.id
        WHERE gi.gacha_id = ?
        ORDER BY gi.card_id
    ");
    $stmt->execute([$gacha_id]);
    return $stmt->fetchAll();
}

/**
 * 重み付き抽選を1回行い、当選したアイテム（行）を返す
 *
 * 例: 重み [1, 4, 15, 30, 50]（合計100）のとき
 *     1〜100 の乱数を引き、重みを順に足していって
 *     乱数以下になった最初のアイテムが当たり。
 */
function draw_one(array $items): array
{
    $total = 0;
    foreach ($items as $item) {
        $total += (int)$item['weight'];
    }
    if ($total <= 0) {
        throw new RuntimeException('ガチャの重みの合計が0です。');
    }

    $rand = random_int(1, $total);
    $cumulative = 0;
    foreach ($items as $item) {
        $cumulative += (int)$item['weight'];
        if ($rand <= $cumulative) {
            return $item;
        }
    }
    // ここには来ない（$rand <= $total のため）
    throw new LogicException('抽選に失敗しました。');
}

/**
 * 抽選結果を保存する（呼び出し側でトランザクションを開始しておくこと）
 *   - gacha_histories: ガチャの履歴
 *   - user_cards:      プレイヤーの所持カード（$grant_cards = true のとき）
 * 戻り値は 1回の実行（10連など）を識別する draw_id
 */
function save_draw_results(PDO $pdo, int $user_id, int $gacha_id, array $results, string $now, bool $grant_cards = true): string
{
    $draw_id = bin2hex(random_bytes(16));

    $history = $pdo->prepare("
        INSERT INTO gacha_histories (draw_id, user_id, gacha_id, card_id, created_at)
        VALUES (?, ?, ?, ?, ?)
    ");
    $card = $pdo->prepare("INSERT INTO user_cards (user_id, card_id, created_at) VALUES (?, ?, ?)");

    foreach ($results as $item) {
        $history->execute([$draw_id, $user_id, $gacha_id, $item['card_id'], $now]);
        if ($grant_cards) {
            $card->execute([$user_id, $item['card_id'], $now]);
        }
    }
    return $draw_id;
}

/**
 * 開催中のガチャを取得し、種類（normal / box）も確認する
 */
function require_open_gacha(PDO $pdo, int $user_id, int $gacha_id, string $type, string $now): array
{
    $gacha = find_open_gacha($pdo, $gacha_id, $now);
    if (!$gacha) {
        gacha_log('期間外または存在しないガチャ', ['user_id' => $user_id, 'gacha_id' => $gacha_id]);
        throw new GachaException('このガチャは開催期間外か、存在しません。');
    }
    if ($gacha['type'] !== $type) {
        throw new GachaException($type === 'box'
            ? 'このガチャはボックスガチャではありません。'
            : 'ボックスガチャはボックスガチャの画面から引いてください。');
    }
    return $gacha;
}

/**
 * 通常ガチャを $count 回引いて履歴と所持カードを保存し、結果（当選アイテムの配列）を返す
 *
 * $grant_cards = false にすると履歴だけ保存する（排出率を調べるための大量試行用）
 */
function execute_gacha(PDO $pdo, int $user_id, int $gacha_id, int $count = GACHA_DRAW_COUNT, bool $grant_cards = true): array
{
    $now = date('Y-m-d H:i:s');
    require_open_gacha($pdo, $user_id, $gacha_id, 'normal', $now);

    $items = fetch_gacha_items($pdo, $gacha_id);
    if (!$items) {
        throw new GachaException('ガチャのラインナップが登録されていません。');
    }

    $results = [];
    for ($i = 0; $i < $count; $i++) {
        $results[] = draw_one($items);
    }

    $pdo->beginTransaction();
    try {
        $draw_id = save_draw_results($pdo, $user_id, $gacha_id, $results, $now, $grant_cards);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        gacha_log('履歴の保存に失敗', ['user_id' => $user_id, 'gacha_id' => $gacha_id, 'error' => $e->getMessage()]);
        throw $e;
    }

    gacha_log('ガチャ実行', [
        'user_id'     => $user_id,
        'gacha_id'    => $gacha_id,
        'draw_id'     => $draw_id,
        'count'       => $count,
        'grant_cards' => $grant_cards,
        'card_ids'    => array_column($results, 'card_id'),
    ]);

    return $results;
}
