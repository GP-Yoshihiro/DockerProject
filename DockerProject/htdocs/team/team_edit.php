<?php
require_once __DIR__ . '/../../lib/team_lib.php';

$user_id = 1;

$team_id = filter_input(INPUT_GET, 'team_id', FILTER_VALIDATE_INT)
    ?: filter_input(INPUT_POST, 'team_id', FILTER_VALIDATE_INT);
$team = $team_id ? find_user_team($pdo, $user_id, $team_id) : null;
if (!$team) {
    header('Location: team_list.php');
    exit;
}

$error = null;
$slots = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 送られてきた枠ごとのカードID（未選択は null）
    $posted = $_POST['slots'] ?? [];
    if (!is_array($posted)) {
        $posted = [];
    }
    for ($pos = 1; $pos <= TEAM_SIZE; $pos++) {
        $value = $posted[$pos] ?? '';
        $slots[$pos] = $value === '' ? null : filter_var($value, FILTER_VALIDATE_INT);
    }
    try {
        if (in_array(false, $slots, true)) {
            throw new TeamException('不正なカードが指定されました。');
        }
        save_team($pdo, $user_id, $team_id, $slots);
        header('Location: team_list.php?saved=1');
        exit;
    } catch (TeamException $e) {
        $error = $e->getMessage();
    }
} else {
    foreach (fetch_team_members($pdo, $team_id) as $pos => $m) {
        $slots[$pos] = (int)$m['user_card_id'];
    }
}

// カードの種類ごとにまとめて選択肢を作る
$options = [];
foreach (fetch_user_cards($pdo, $user_id) as $card) {
    $options[$card['name']][] = $card;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>チーム編成</title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
    <h1>チーム編成: <?= htmlspecialchars($team['name']) ?></h1>

    <?php if ($error !== null): ?>
        <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="team_id" value="<?= htmlspecialchars($team_id) ?>">
        <table border="1">
            <tr>
                <th>枠</th>
                <th>カード</th>
            </tr>
            <?php for ($pos = 1; $pos <= TEAM_SIZE; $pos++): ?>
            <tr>
                <td><?= $pos ?></td>
                <td>
                    <select name="slots[<?= $pos ?>]">
                        <option value="">（空き）</option>
                        <?php foreach ($options as $name => $cards): ?>
                            <optgroup label="<?= htmlspecialchars($name) ?>">
                                <?php foreach ($cards as $card): ?>
                                    <option value="<?= htmlspecialchars($card['id']) ?>" <?= ($slots[$pos] ?? null) === (int)$card['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($card['name']) ?>（ID: <?= htmlspecialchars($card['id']) ?> / HP <?= htmlspecialchars($card['hp']) ?> / ATK <?= htmlspecialchars($card['atk']) ?> / DEF <?= htmlspecialchars($card['def']) ?>）
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <?php endfor; ?>
        </table>
        <p><button type="submit">保存する</button></p>
    </form>

    <p><a href="team_list.php">チーム一覧に戻る</a></p>
</body>
</html>
