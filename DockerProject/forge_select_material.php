<?php
require_once 'db.php';
$user_id = 1;
$card_id = $_GET['target_item_type_id'] ?? null;
$base_id = $_GET['base_user_item_id'] ?? null;

if (!$card_id || !$base_id) {
    header('Location: forge_entrance.php');
    exit;
}

$stmt = $pdo->prepare("SELECT name FROM cards WHERE id = ?");
$stmt->execute([$card_id]);
$card = $stmt->fetch();

$stmt = $pdo->prepare("SELECT id, created_at FROM user_cards WHERE user_id = ? AND card_id = ? AND id != ?");
$stmt->execute([$user_id, $card_id, $base_id]);
$materials = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>素材アイテムインスタンス選択</title>
</head>
<body>
    <h1>素材アイテムインスタンス選択 (アイテムID: <?= htmlspecialchars($card_id) ?>)</h1>
    <p>ベースアイテムインスタンスID: <b><?= htmlspecialchars($base_id) ?></b></p>
    <p>強化の<b>素材</b>として利用するアイテムインスタンスを1つ選択してください。</p>
    <table border="1">
        <tr>
            <th>インスタンスID</th>
            <th>アイテム名 (素材ID: <?= htmlspecialchars($card_id) ?>)</th>
            <th>取得日時</th>
            <th>選択</th>
        </tr>
        <?php foreach ($materials as $mat): ?>
        <tr>
            <td><?= htmlspecialchars($mat['id']) ?></td>
            <td><?= htmlspecialchars($card['name']) ?></td>
            <td><?= htmlspecialchars($mat['created_at']) ?></td>
            <td><a href="forge_result.php?base_id=<?= $base_id ?>&material_id=<?= $mat['id'] ?>">素材に決定し強化開始</a></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <br>
    <a href="forge_entrance.php">最初に戻る</a>
</body>
</html>