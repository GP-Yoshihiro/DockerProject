<?php
require_once __DIR__ . '/../../lib/team_lib.php';

$user_id = GAME_USER_ID;

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

$cards = fetch_user_cards($pdo, $user_id);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <?php render_game_head('チーム編成'); ?>
</head>
<body>
    <?php render_game_header($pdo, $user_id); ?>
    <h1>チーム編成: <?= htmlspecialchars($team['name']) ?></h1>

    <?php if ($error !== null): ?>
        <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <p class="muted">枠1から順に並びます。タンクは敵の攻撃を引きつけ、ヒーラーは味方を回復します。ステージの敵の弱点属性も考えて編成しましょう。</p>

    <form method="post" class="panel">
        <input type="hidden" name="team_id" value="<?= htmlspecialchars($team_id) ?>">
        <table>
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
                        <?php foreach ($cards as $card): ?>
                            <option value="<?= (int)$card['user_card_id'] ?>" <?= ($slots[$pos] ?? null) === (int)$card['user_card_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars(sprintf('%s %s（%s / %s / Lv%d / 戦力 %s）',
                                    rarity_label((int)$card['rarity']), $card['name'], attribute_label($card['attribute']),
                                    role_label($card['role']), $card['level'], number_format($card['power']))) ?>
                            </option>
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
