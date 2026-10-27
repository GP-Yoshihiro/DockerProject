<?php
require_once 'db.php';
$user_id = 1;

$stmt = $pdo->prepare("
    SELECT uc.card_id, c.name, COUNT(uc.id) as count 
    FROM user_cards uc
    JOIN cards c ON uc.card_id = c.id
    WHERE uc.user_id = ?
    GROUP BY uc.card_id, c.name
    HAVING count >= 2
");
$stmt->execute([$user_id]);
$targets = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>強化対象アイテム選択</title>
</head>
<body>
    <h1>強化対象アイテム選択</h1>
    <p>強化のベースとして利用するアイテムを選択してください。</p>
    <table border="1">
        <tr>
            <th>アイテムID</th>
            <th>アイテム名</th>
            <th>所持数</th>
            <th>選択</th>
        </tr>
        <?php foreach ($targets as $row): ?>
        <tr>
            <td><?= htmlspecialchars($row['card_id']) ?></td>
            <td><?= htmlspecialchars($row['name']) ?></td>
            <td><?= htmlspecialchars($row['count']) ?></td>
            <td><a href="forge_select.php?target_item_id=<?= $row['card_id'] ?>">強化する</a></td>
        </tr>
        <?php endforeach; ?>
    </table>
</body>
</html>