<?php
// 対戦（PvP）: 他のユーザーのチームとオートバトル。結果は対戦履歴に入り、cron のランキングに反映される
require_once __DIR__ . '/../../lib/game_battle_lib.php';

$user_id = GAME_USER_ID;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $opponent_id = filter_input(INPUT_POST, 'opponent_id', FILTER_VALIDATE_INT) ?: 0;
    $team_id = filter_input(INPUT_POST, 'team_id', FILTER_VALIDATE_INT) ?: 0;
    try {
        $battle_id = run_pvp_battle($pdo, $user_id, $opponent_id, $team_id);
        header('Location: battle_view.php?id=' . $battle_id);
        exit;
    } catch (GameException $e) {
        flash_set('error', $e->getMessage());
        header('Location: pvp.php');
        exit;
    }
}

$opponents = fetch_pvp_opponents($pdo, $user_id);
$teams = fetch_teams_with_power($pdo, $user_id);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <?php render_game_head('対戦'); ?>
</head>
<body>
    <?php render_game_header($pdo, $user_id); ?>
    <h1>対戦</h1>
    <?php render_flash(); ?>
    <p class="muted">
        相手のチームは CPU が操作します。対戦では属性の補正はありません。30ターンで決着がつかなければ引き分け。<br>
        報酬: 勝ち ガチャ石 <?= PVP_REWARDS['win']['items'][ITEM_STONE] ?>・ゴールド <?= PVP_REWARDS['win']['items'][ITEM_GOLD] ?>・経験値 <?= PVP_REWARDS['win']['exp'] ?>
        ／ 引き分け ゴールド <?= PVP_REWARDS['draw']['items'][ITEM_GOLD] ?>・経験値 <?= PVP_REWARDS['draw']['exp'] ?>
        ／ 負け 経験値 <?= PVP_REWARDS['lose']['exp'] ?>。
        結果は<a href="/battle/ranking.php">対戦ランキング</a>に反映されます（1分ごとに更新）。
    </p>

    <?php foreach ($opponents as $o): ?>
        <section class="panel">
            <div class="stage-head">
                <h2><?= htmlspecialchars($o['name']) ?></h2>
                <span class="muted">
                    戦力 <?= number_format($o['power']) ?>
                    <?php if ($o['rank_no'] !== null): ?>
                        ／ <?= (int)$o['rank_no'] ?>位（<?= (int)$o['wins'] ?>勝 <?= (int)$o['losses'] ?>敗 <?= (int)$o['draws'] ?>分・勝率 <?= htmlspecialchars($o['win_rate']) ?>%）
                    <?php endif; ?>
                </span>
            </div>
            <div class="team-row">
                <?php foreach ($o['cards'] as $c): ?>
                    <div class="mini-card rarity-<?= (int)$c['rarity'] ?>">
                        <img src="<?= htmlspecialchars($c['image_url']) ?>" alt="">
                        <span class="mini-name"><?= htmlspecialchars($c['name']) ?></span>
                        <span class="mini-sub"><?= attribute_badge($c['attribute']) ?> <?= htmlspecialchars(role_label($c['role'])) ?></span>
                        <span class="mini-sub">Lv<?= (int)$c['level'] ?> / 戦力 <?= number_format($c['power']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <form method="post" class="inline-form">
                <input type="hidden" name="opponent_id" value="<?= (int)$o['id'] ?>">
                <select name="team_id">
                    <?php foreach ($teams as $t): ?>
                        <option value="<?= (int)$t['id'] ?>" <?= $t['cards'] ? '' : 'disabled' ?>><?= htmlspecialchars($t['name']) ?>（戦力 <?= number_format($t['power']) ?>）</option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn-battle">対戦する</button>
            </form>
        </section>
    <?php endforeach; ?>
    <script src="/js/submit-once.js" defer></script>
</body>
</html>
