<?php
require_once __DIR__ . '/../../lib/team_lib.php';

$user_id = 1;

$cards = fetch_user_cards($pdo, $user_id);

// カードの種類ごとの枚数
$summary = [];
foreach ($cards as $card) {
    $summary[$card['card_id']] ??= ['name' => $card['name'], 'image' => $card['image'], 'count' => 0];
    $summary[$card['card_id']]['count']++;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>所持カード一覧</title>
    <link rel="stylesheet" href="/css/card.css">
</head>
<body>
    <h1>所持カード一覧</h1>
    <p>所持枚数: <?= htmlspecialchars(count($cards)) ?> 枚</p>

    <h2>種類ごとの枚数</h2>
    <table border="1">
        <tr>
            <th>カードID</th>
            <th>カード名</th>
            <th>枚数</th>
        </tr>
        <?php foreach ($summary as $card_id => $row): ?>
        <tr>
            <td><?= htmlspecialchars($card_id) ?></td>
            <td><img class="card-thumb" src="<?= htmlspecialchars(card_image_url($row['image'])) ?>" alt=""> <?= htmlspecialchars($row['name']) ?></td>
            <td><?= htmlspecialchars($row['count']) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>

    <h2>1枚ずつ</h2>
    <table border="1">
        <tr>
            <th>インスタンスID</th>
            <th>カード名</th>
            <th>HP</th>
            <th>ATK</th>
            <th>DEF</th>
            <th>取得日時</th>
        </tr>
        <?php foreach ($cards as $card): ?>
        <tr>
            <td><?= htmlspecialchars($card['id']) ?></td>
            <td><img class="card-thumb" src="<?= htmlspecialchars(card_image_url($card['image'])) ?>" alt=""> <?= htmlspecialchars($card['name']) ?></td>
            <td><?= htmlspecialchars($card['hp']) ?></td>
            <td><?= htmlspecialchars($card['atk']) ?></td>
            <td><?= htmlspecialchars($card['def']) ?></td>
            <td><?= htmlspecialchars($card['created_at']) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>

    <p><a href="../">メニューに戻る</a></p>
</body>
</html>
