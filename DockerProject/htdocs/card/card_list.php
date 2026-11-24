<?php
// 所持カード一覧（図鑑）: 持っていないカードも暗く表示する
require_once __DIR__ . '/../../lib/card_growth_lib.php';

$user_id = GAME_USER_ID;

$owned = array_column(fetch_user_card_list($pdo, $user_id), null, 'card_id');
$all = $pdo->query("SELECT id, name, rarity, attribute, role, image FROM cards ORDER BY rarity DESC, id")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <?php render_game_head('所持カード'); ?>
</head>
<body>
    <?php render_game_header($pdo, $user_id); ?>
    <h1>所持カード</h1>
    <p>所持: <?= count($owned) ?> / <?= count($all) ?> 種類。カードを選ぶと、レベルアップ（経験値アイテム）と進化ができます。</p>

    <div class="collection">
        <?php foreach ($all as $c): ?>
            <?php $o = $owned[$c['id']] ?? null; ?>
            <?php if ($o): ?>
                <a class="collection-card rarity-<?= (int)$c['rarity'] ?><?= (int)$o['limit_break'] >= MAX_LIMIT_BREAK ? ' maxed' : '' ?>" href="card_detail.php?id=<?= (int)$o['user_card_id'] ?>">
                    <img src="<?= htmlspecialchars($o['image_url']) ?>" alt="">
                    <span class="mini-name"><span class="rarity-badge rarity-<?= (int)$c['rarity'] ?>"><?= rarity_label((int)$c['rarity']) ?></span> <?= htmlspecialchars($c['name']) ?></span>
                    <span class="mini-sub"><?= attribute_badge($c['attribute']) ?> <?= htmlspecialchars(role_label($c['role'])) ?></span>
                    <span class="mini-sub">Lv<?= (int)$o['level'] ?>/<?= (int)$o['level_cap'] ?> <span class="lb-marks" title="<?= (int)$o['limit_break'] ?>凸"><?= limit_break_marks((int)$o['limit_break']) ?></span></span>
                    <span class="mini-sub">戦力 <?= number_format($o['power']) ?></span>
                </a>
            <?php else: ?>
                <div class="collection-card not-owned">
                    <img src="<?= htmlspecialchars(card_image_url($c['image'])) ?>" alt="">
                    <span class="mini-name"><span class="rarity-badge rarity-<?= (int)$c['rarity'] ?>"><?= rarity_label((int)$c['rarity']) ?></span> <?= htmlspecialchars($c['name']) ?></span>
                    <span class="mini-sub"><?= attribute_badge($c['attribute']) ?> <?= htmlspecialchars(role_label($c['role'])) ?></span>
                    <span class="mini-sub">未所持（ガチャで入手）</span>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <p><a href="/game/">ゲームのホームに戻る</a></p>
</body>
</html>
