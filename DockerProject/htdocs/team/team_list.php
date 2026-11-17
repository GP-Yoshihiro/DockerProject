<?php
require_once __DIR__ . '/../../lib/team_lib.php';

$user_id = 1;

$stmt = $pdo->prepare("SELECT id, name FROM user_teams WHERE user_id = ? ORDER BY id");
$stmt->execute([$user_id]);
$teams = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>チーム一覧</title>
    <link rel="stylesheet" href="/css/card.css">
</head>
<body>
    <h1>チーム一覧</h1>

    <?php if (isset($_GET['saved'])): ?>
        <p>チーム編成を保存しました。</p>
    <?php endif; ?>

    <?php foreach ($teams as $team): ?>
        <?php
        $members = fetch_team_members($pdo, (int)$team['id']);
        $total = ['hp' => 0, 'atk' => 0, 'def' => 0];
        foreach ($members as $m) {
            foreach ($total as $key => $_) {
                $total[$key] += $m[$key];
            }
        }
        ?>
        <h2><?= htmlspecialchars($team['name']) ?></h2>
        <table border="1">
            <tr>
                <th>枠</th>
                <th>カード名</th>
                <th>HP</th>
                <th>ATK</th>
                <th>DEF</th>
            </tr>
            <?php for ($pos = 1; $pos <= TEAM_SIZE; $pos++): ?>
            <tr>
                <td><?= $pos ?></td>
                <?php if (isset($members[$pos])): ?>
                    <td><img class="card-thumb" src="<?= htmlspecialchars(card_image_url($members[$pos]['image'])) ?>" alt=""> <?= htmlspecialchars($members[$pos]['name']) ?>（ID: <?= htmlspecialchars($members[$pos]['user_card_id']) ?>）</td>
                    <td><?= htmlspecialchars($members[$pos]['hp']) ?></td>
                    <td><?= htmlspecialchars($members[$pos]['atk']) ?></td>
                    <td><?= htmlspecialchars($members[$pos]['def']) ?></td>
                <?php else: ?>
                    <td colspan="4">（空き）</td>
                <?php endif; ?>
            </tr>
            <?php endfor; ?>
            <tr>
                <th colspan="2">合計</th>
                <td><?= $total['hp'] ?></td>
                <td><?= $total['atk'] ?></td>
                <td><?= $total['def'] ?></td>
            </tr>
        </table>
        <p><a href="team_edit.php?team_id=<?= htmlspecialchars($team['id']) ?>">編成する</a></p>
    <?php endforeach; ?>

    <p><a href="../">メニューに戻る</a></p>
</body>
</html>
