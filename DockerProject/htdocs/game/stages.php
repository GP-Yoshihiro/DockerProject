<?php
// ステージ選択 → 挑戦（POST）→ バトル再生画面へ
require_once __DIR__ . '/../../lib/game_battle_lib.php';

$user_id = GAME_USER_ID;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stage_id = filter_input(INPUT_POST, 'stage_id', FILTER_VALIDATE_INT) ?: 0;
    $team_id = filter_input(INPUT_POST, 'team_id', FILTER_VALIDATE_INT) ?: 0;
    try {
        $battle_id = run_stage_battle($pdo, $user_id, $stage_id, $team_id);
        header('Location: battle_view.php?id=' . $battle_id);
        exit;
    } catch (GameException $e) {
        flash_set('error', $e->getMessage());
        header('Location: stages.php?team_id=' . $team_id);
        exit;
    }
}

$stages = fetch_stage_list($pdo, $user_id);
$teams = fetch_teams_with_power($pdo, $user_id);
$selected_team = filter_input(INPUT_GET, 'team_id', FILTER_VALIDATE_INT) ?: null;
if ($selected_team === null) {
    foreach ($teams as $t) {
        if ($t['cards']) {
            $selected_team = (int)$t['id'];
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <?php render_game_head('ステージ'); ?>
</head>
<body>
    <?php render_game_header($pdo, $user_id); ?>
    <h1>ステージ</h1>
    <?php render_flash(); ?>
    <p class="muted">★1 クリア ／ ★2 誰も倒れずにクリア ／ ★3 目標ターン以内にクリア。敵の弱点属性で攻撃するとダメージ1.5倍、耐性属性だと0.5倍です。</p>

    <?php foreach ($stages as $s): ?>
        <?php
        $weak = $resist = [];
        foreach ($s['waves'] as $enemies) {
            foreach ($enemies as $e) {
                if ($e['weakness']) { $weak[$e['weakness']] = true; }
                if ($e['resistance']) { $resist[$e['resistance']] = true; }
            }
        }
        ?>
        <section class="panel stage<?= $s['unlocked'] ? '' : ' locked' ?><?= $s['is_boss'] ? ' boss' : '' ?>">
            <div class="stage-head">
                <h2><?= (int)$s['id'] ?>. <?= htmlspecialchars($s['name']) ?></h2>
                <span class="stars"><?= str_repeat('★', (int)$s['best_stars']) . str_repeat('☆', 3 - (int)$s['best_stars']) ?></span>
            </div>
            <p><?= htmlspecialchars($s['description']) ?></p>
            <p class="muted">
                推奨 Lv<?= (int)$s['recommended_level'] ?> ／ ウェーブ <?= count($s['waves']) ?> ／ 目標 <?= (int)$s['target_turns'] ?>ターン ／ 経験値 <?= (int)$s['exp'] ?>
                ／ クリア <?= (int)$s['clear_count'] ?>回
            </p>
            <p>
                弱点: <?php foreach (array_keys($weak) as $a) { echo attribute_badge($a), ' '; } ?>
                <?php if ($resist): ?>／ 耐性: <?php foreach (array_keys($resist) as $a) { echo attribute_badge($a), ' '; } ?><?php endif; ?>
            </p>
            <div class="enemy-row">
                <?php foreach ($s['waves'] as $wave => $enemies): ?>
                    <div class="wave">
                        <span class="wave-label">W<?= $wave ?></span>
                        <?php foreach ($enemies as $e): ?>
                            <span class="enemy-chip" title="HP <?= (int)$e['hp'] ?> / 攻撃 <?= (int)$e['atk'] ?> / 防御 <?= (int)$e['def'] ?>">
                                <img src="<?= htmlspecialchars($e['image_url']) ?>" alt=""><?= htmlspecialchars($e['name']) ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="muted reward-line">
                <?php if ((int)$s['clear_count'] === 0): ?>
                    初回報酬: <?= htmlspecialchars(implode('・', array_map(fn ($r) => "{$r['name']}×{$r['quantity']}", array_filter($s['rewards'], fn ($r) => $r['kind'] === 'first')))) ?> ／
                <?php endif; ?>
                報酬: <?= htmlspecialchars(implode('・', array_map(fn ($r) => "{$r['name']}×{$r['quantity']}" . ((int)$r['probability'] < 100 ? "({$r['probability']}%)" : ''), array_filter($s['rewards'], fn ($r) => $r['kind'] === 'repeat')))) ?>
            </p>
            <?php if ($s['unlocked']): ?>
                <form method="post" class="inline-form">
                    <input type="hidden" name="stage_id" value="<?= (int)$s['id'] ?>">
                    <select name="team_id">
                        <?php foreach ($teams as $t): ?>
                            <option value="<?= (int)$t['id'] ?>" <?= (int)$t['id'] === $selected_team ? 'selected' : '' ?> <?= $t['cards'] ? '' : 'disabled' ?>>
                                <?= htmlspecialchars($t['name']) ?>（戦力 <?= number_format($t['power']) ?>）
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn-battle">出撃</button>
                </form>
            <?php else: ?>
                <p class="locked-text">🔒 前のステージをクリアすると解放</p>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
    <script src="/js/submit-once.js" defer></script>
</body>
</html>
