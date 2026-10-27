<?php
require_once 'db.php';
$user_id = 1;
$base_id = $_GET['base_id'] ?? null;
$material_id = $_GET['material_id'] ?? null;

if (!$base_id || !$material_id) {
    header('Location: forge_entrance.php');
    exit;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT uc.id, uc.card_id, c.name, c.next_card_id FROM user_cards uc JOIN cards c ON uc.card_id = c.id WHERE uc.id = ? AND uc.user_id = ?");
    $stmt->execute([$base_id, $user_id]);
    $base = $stmt->fetch();

    $stmt = $pdo->prepare("SELECT uc.id, uc.card_id, c.name FROM user_cards uc JOIN cards c ON uc.card_id = c.id WHERE uc.id = ? AND uc.user_id = ?");
    $stmt->execute([$material_id, $user_id]);
    $material = $stmt->fetch();

    if (!$base || !$material) {
        throw new Exception("指定されたアイテムが存在しません。");
    }

    if ($base['card_id'] !== $material['card_id']) {
        throw new Exception("異なるカード同士は強化できません。");
    }

    if (!$base['next_card_id']) {
        throw new Exception("これ以上強化できないカードです。");
    }

    // 1. ベースカードを次のカードIDに更新
    $stmt = $pdo->prepare("UPDATE user_cards SET card_id = ? WHERE id = ?");
    $stmt->execute([$base['next_card_id'], $base_id]);

    // 2. 素材カードを削除
    $stmt = $pdo->prepare("DELETE FROM user_cards WHERE id = ?");
    $stmt->execute([$material_id]);

    $stmt = $pdo->prepare("SELECT name FROM cards WHERE id = ?");
    $stmt->execute([$base['next_card_id']]);
    $new_card = $stmt->fetch();

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    die("エラーが発生しました: " . htmlspecialchars($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>強化結果</title>
</head>
<body>
    <h1>強化成功!</h1>
    <p>以下のアイテムを消費しました:</p>
    <ul>
        <li>ベース: <?= htmlspecialchars($base['name']) ?> (インスタンスID: <?= htmlspecialchars($base['id']) ?>)</li>
        <li>素材: <?= htmlspecialchars($material['name']) ?> (インスタンスID: <?= htmlspecialchars($material['id']) ?>)</li>
    </ul>
    <p>新しいアイテムを獲得しました:</p>
    <h2><?= htmlspecialchars($new_card['name']) ?> (新しいアイテムID: <?= htmlspecialchars($base['next_card_id']) ?>)</h2>
    <br>
    <a href="forge_entrance.php">続けて強化を行う</a>
</body>
</html>