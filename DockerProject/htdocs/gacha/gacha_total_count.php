<?php
// ガチャ結果 集計レポート: これまでのガチャ履歴を、アイテムごとに数えて出現率を出す
require_once __DIR__ . '/../../lib/db.php';

/*
 * GROUP BY card_id でアイテムごとの出現回数を数える。
 * gacha_histories には (gacha_id, card_id) のインデックスがあるが、
 * 先頭の gacha_id で絞り込まない集計なので、ここではテーブル全体を読むことになる。
 */
$stmt = $pdo->query("
    SELECT card_id, COUNT(*) AS count
    FROM gacha_histories
    GROUP BY card_id
    ORDER BY card_id
");
$rows = $stmt->fetchAll();
$total = array_sum(array_column($rows, 'count'));
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>ガチャ結果 集計レポート</title>
    <link rel="stylesheet" href="/css/style.css">
    <style>
        td.num { text-align: right; }
    </style>
</head>
<body>
    <h1>ガチャ結果 集計レポート</h1>
    <p>集計対象の履歴総数: <b><?= htmlspecialchars(number_format($total)) ?></b> 件</p>

    <?php if ($total === 0): ?>
        <p>まだガチャの履歴がありません。</p>
    <?php else: ?>
        <table>
            <tr>
                <th>Item ID</th>
                <th>出現回数 (count)</th>
                <th>出現率 (rate)</th>
            </tr>
            <?php foreach ($rows as $row): ?>
            <tr>
                <td><?= htmlspecialchars($row['card_id']) ?></td>
                <td class="num"><?= htmlspecialchars(number_format($row['count'])) ?> 回</td>
                <td class="num"><?= htmlspecialchars(number_format($row['count'] / $total * 100, 2)) ?> %</td>
            </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

    <p><a href="gacha_form.php">戻る</a></p>
</body>
</html>
