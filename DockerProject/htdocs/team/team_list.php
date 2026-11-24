<?php
require_once __DIR__ . '/../../lib/team_lib.php';

$user_id = GAME_USER_ID;

$stmt = $pdo->prepare("SELECT id, name FROM user_teams WHERE user_id = ? ORDER BY id");
$stmt->execute([$user_id]);
$teams = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <?php render_game_head('チーム編成'); ?>
</head>
<body>
    <?php render_game_header($pdo, $user_id); ?>
    <h1>チーム編成</h1>

    <?php if (isset($_GET['saved'])): ?>
        <p class="notice">チーム編成を保存しました。</p>
    <?php endif; ?>

    <?php foreach ($teams as $team): ?>
        <?php
        $members = fetch_team_members($pdo, (int)$team['id']);
        $power = array_sum(array_column($members, 'power'));
        ?>
        <section class="panel">
            <h2><?= htmlspecialchars($team['name']) ?> <span class="muted">戦力 <?= number_format($power) ?></span></h2>
            <div class="team-row">
                <?php for ($pos = 1; $pos <= TEAM_SIZE; $pos++): ?>
                    <?php if (isset($members[$pos])): ?>
                        <?php $m = $members[$pos]; ?>
                        <a class="mini-card rarity-<?= (int)$m['rarity'] ?>" href="/card/card_detail.php?id=<?= (int)$m['user_card_id'] ?>">
                            <img src="<?= htmlspecialchars($m['image_url']) ?>" alt="">
                            <span class="mini-name"><?= htmlspecialchars($m['name']) ?></span>
                            <span class="mini-sub"><?= attribute_badge($m['attribute']) ?> <?= htmlspecialchars(role_label($m['role'])) ?></span>
                            <span class="mini-sub">Lv<?= (int)$m['level'] ?> / 戦力 <?= number_format($m['power']) ?></span>
                        </a>
                    <?php else: ?>
                        <div class="mini-card empty">（空き）</div>
                    <?php endif; ?>
                <?php endfor; ?>
            </div>
            <p class="links">
                <a href="team_edit.php?team_id=<?= (int)$team['id'] ?>">編成する</a>
                <?php if ($members): ?>
                    <a href="/game/stages.php?team_id=<?= (int)$team['id'] ?>">このチームでステージへ</a>
                <?php endif; ?>
            </p>
        </section>
    <?php endforeach; ?>

    <p><a href="/game/">ゲームのホームに戻る</a></p>
</body>
</html>
