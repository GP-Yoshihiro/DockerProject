<?php
// 排出率の集計: 何百回も試行して、実際の排出率が設定した確率（重み）に収束しているかを確認する
require_once __DIR__ . '/gacha_lib.php';

$user_id = 1;
$gacha_id = filter_input(INPUT_GET, 'gacha_id', FILTER_VALIDATE_INT) ?: 1;

$message = null;

// 試行回数をまとめて増やす（シミュレーション用）
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $gacha_id = filter_input(INPUT_POST, 'gacha_id', FILTER_VALIDATE_INT) ?: 1;
    $times = filter_input(INPUT_POST, 'times', FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 1000],
    ]);
    if ($times) {
        try {
            for ($i = 0; $i < $times; $i++) {
                execute_gacha($pdo, $user_id, $gacha_id);
            }
            $message = "10連を {$times} 回実行しました。";
        } catch (GachaException $e) {
            $message = $e->getMessage();
        }
    }
}

$stmt = $pdo->query("SELECT id, name FROM gachas ORDER BY id");
$gachas = $stmt->fetchAll();

/*
 * 収束確認クエリ
 *   期待値 = 重み / 重みの合計
 *   実績値 = そのカードの排出数 / ガチャの総排出数
 * 試行回数が増えるほど、実績値が期待値に近づいていれば正しく抽選できている。
 * gacha_histories の (gacha_id, card_id) インデックスが集計に使われる。
 */
$stmt = $pdo->prepare("
    SELECT
        gi.card_id,
        c.name,
        gi.weight,
        ROUND(gi.weight / w.total_weight * 100, 2)            AS expected_rate,
        COALESCE(h.cnt, 0)                                    AS draw_count,
        ROUND(COALESCE(h.cnt, 0) / NULLIF(t.total_cnt, 0) * 100, 2) AS actual_rate
    FROM gacha_items gi
    JOIN cards c ON gi.card_id = c.id
    JOIN (
        SELECT gacha_id, SUM(weight) AS total_weight
        FROM gacha_items
        WHERE gacha_id = ?
        GROUP BY gacha_id
    ) w ON w.gacha_id = gi.gacha_id
    LEFT JOIN (
        SELECT card_id, COUNT(*) AS cnt
        FROM gacha_histories
        WHERE gacha_id = ?
        GROUP BY card_id
    ) h ON h.card_id = gi.card_id
    CROSS JOIN (
        SELECT COUNT(*) AS total_cnt
        FROM gacha_histories
        WHERE gacha_id = ?
    ) t
    WHERE gi.gacha_id = ?
    ORDER BY gi.card_id
");
$stmt->execute([$gacha_id, $gacha_id, $gacha_id, $gacha_id]);
$rows = $stmt->fetchAll();
$total = array_sum(array_column($rows, 'draw_count'));
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>排出率の集計</title>
</head>
<body>
    <h1>排出率の集計</h1>

    <form method="get">
        <select name="gacha_id">
            <?php foreach ($gachas as $g): ?>
                <option value="<?= htmlspecialchars($g['id']) ?>" <?= (int)$g['id'] === $gacha_id ? 'selected' : '' ?>>
                    <?= htmlspecialchars($g['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit">表示</button>
    </form>

    <?php if ($message !== null): ?>
        <p><?= htmlspecialchars($message) ?></p>
    <?php endif; ?>

    <p>総排出数: <?= htmlspecialchars($total) ?></p>
    <table border="1">
        <tr>
            <th>カードID</th>
            <th>カード名</th>
            <th>重み</th>
            <th>期待値(%)</th>
            <th>排出数</th>
            <th>実績値(%)</th>
        </tr>
        <?php foreach ($rows as $row): ?>
        <tr>
            <td><?= htmlspecialchars($row['card_id']) ?></td>
            <td><?= htmlspecialchars($row['name']) ?></td>
            <td><?= htmlspecialchars($row['weight']) ?></td>
            <td><?= htmlspecialchars($row['expected_rate']) ?></td>
            <td><?= htmlspecialchars($row['draw_count']) ?></td>
            <td><?= htmlspecialchars($row['actual_rate'] ?? '-') ?></td>
        </tr>
        <?php endforeach; ?>
    </table>

    <form method="post">
        <input type="hidden" name="gacha_id" value="<?= htmlspecialchars($gacha_id) ?>">
        <p>
            10連を
            <input type="number" name="times" value="100" min="1" max="1000">
            回まとめて実行
            <button type="submit">試行する</button>
        </p>
    </form>

    <a href="gacha_form.html">ガチャ画面に戻る</a>
</body>
</html>
