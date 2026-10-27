<?php
require_once 'db.php';
$user_id = 1;
$card_id = $_GET['target_item_id'] ?? null;

if (!$card_id) {
    header('Location: forge_entrance.php');
    exit;
}

$stmt = $pdo->prepare("SELECT name FROM cards WHERE id = ?");
$stmt->execute([$card_id]);
$card = $stmt->fetch();

$stmt = $pdo->prepare("SELECT id, created_at FROM user_cards WHERE user_id = ? AND card_id = ?");
$stmt->execute([$user_id, $card_id]);
$instances = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>ベースアイテムインスタンス選択</title>
</head>
<body>
    <h1>ベースアイテムインスタンス選択 (アイテムID: <?= htmlspecialchars($card_id) ?>)</h1>
    <p>強化の<b>ベース (土台)</b> となるアイテムインスタンスを1つ選択してください。</p>
    <table border="1">
        <tr>
            <th>インスタンスID</th>
            <th>アイテム名</th>
            <th>取得日時</th>
            <th>ベースとして選択</th>
        </tr>
        <?php foreach ($instances as $inst): ?>
        <tr>
            <td><?= htmlspecialchars($inst['id']) ?></td>
            <td><?= htmlspecialchars($card['name']) ?></td>
            <td><?= htmlspecialchars($inst['created_at']) ?></td>
            <td><a href="forge_select_material.php?target_item_type_id=<?= $card_id ?>&base_user_item_id=<?= $inst['id'] ?>">ベースに決定</a></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <br>
    <a href="forge_entrance.php">強化対象アイテム選択に戻る</a>
</body>
</html>