<?php
// 資料06: 対戦ランキング
// ここでは集計しない。crontab のバッチ（batch/summarize_battles.php）が1分ごとに作った結果を表示するだけ
require_once __DIR__ . '/../../lib/battle_lib.php';

$ranking = fetch_battle_ranking($pdo);
$updated_at = fetch_ranking_updated_at($pdo);
$pending = count_unsummarized_battles($pdo, $updated_at);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>対戦ランキング</title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
    <h1>対戦ランキング</h1>

    <?php if ($updated_at === null): ?>
        <p class="notice">ランキングがありません（まだ集計されていないか、対戦履歴がありません）。crontab のバッチが1分ごとに集計します。</p>
    <?php else: ?>
        <p>最終更新日時: <?= htmlspecialchars($updated_at) ?></p>
        <?php if ($pending > 0): ?>
            <p class="json-note">最終更新後の対戦が <?= $pending ?> 件あります（次回の集計で反映されます）。</p>
        <?php endif; ?>

        <table class="ranking">
            <thead>
                <tr>
                    <th>順位</th>
                    <th>ユーザー名</th>
                    <th>勝ち</th>
                    <th>負け</th>
                    <th>引き分け</th>
                    <th>勝率 (%)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($ranking as $row): ?>
                    <tr class="rank-<?= (int)$row['rank_no'] ?>">
                        <td class="num"><?= (int)$row['rank_no'] ?></td>
                        <td><?= htmlspecialchars($row['name']) ?></td>
                        <td class="num"><?= (int)$row['wins'] ?></td>
                        <td class="num"><?= (int)$row['losses'] ?></td>
                        <td class="num"><?= (int)$row['draws'] ?></td>
                        <td class="num"><?= htmlspecialchars($row['win_rate']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <p class="links">
        <a href="battle.php">対戦する</a>
        <a href="/">メニューに戻る</a>
    </p>
</body>
</html>
