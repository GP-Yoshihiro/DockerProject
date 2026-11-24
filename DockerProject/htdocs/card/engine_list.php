<?php
// 所持音動機の一覧（図鑑）
require_once __DIR__ . '/../../lib/engine_lib.php';

$user_id = GAME_USER_ID;
$owned = array_column(fetch_user_engine_list($pdo, $user_id), null, 'w_engine_id');
$all = $pdo->query("
    SELECT we.*, c.name AS signature_card_name FROM w_engines we LEFT JOIN cards c ON c.id = we.signature_card_id
    ORDER BY we.rarity DESC, we.id
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <?php render_game_head('音動機'); ?>
</head>
<body>
    <?php render_game_header($pdo, $user_id); ?>
    <h1>音動機</h1>
    <p>所持: <?= count($owned) ?> / <?= count($all) ?> 種類。音動機はキャラに1つ装備でき、ステータスと効果でキャラを強化します。
        <span class="muted">キャラ専用の音動機は、そのキャラが装備するとスキルがさらに強くなります。</span></p>

    <div class="collection">
        <?php foreach ($all as $w): ?>
            <?php $o = $owned[$w['id']] ?? null; ?>
            <?php if ($o): ?>
                <a class="collection-card rarity-<?= (int)$w['rarity'] ?><?= (int)$o['limit_break'] >= MAX_ENGINE_LIMIT_BREAK ? ' maxed' : '' ?>" href="engine_detail.php?id=<?= (int)$o['user_engine_id'] ?>">
                    <img src="<?= htmlspecialchars($o['image_url']) ?>" alt="">
                    <span class="mini-name"><span class="rarity-badge rarity-<?= (int)$w['rarity'] ?>"><?= rarity_label((int)$w['rarity']) ?></span> <?= htmlspecialchars($w['name']) ?></span>
                    <span class="mini-sub">Lv<?= (int)$o['level'] ?>/<?= (int)$o['level_cap'] ?> <?= (int)$o['limit_break'] ?>凸</span>
                    <span class="mini-sub"><?= htmlspecialchars($o['effect_text']) ?></span>
                    <?php if ($w['signature_card_name']): ?><span class="mini-sub sig"><?= htmlspecialchars($w['signature_card_name']) ?>専用</span><?php endif; ?>
                    <span class="mini-sub"><?= $o['equipped_card_name'] ? '装備: ' . htmlspecialchars($o['equipped_card_name']) : '未装備' ?></span>
                </a>
            <?php else: ?>
                <div class="collection-card not-owned">
                    <img src="<?= htmlspecialchars(engine_image_url($w['image'])) ?>" alt="">
                    <span class="mini-name"><span class="rarity-badge rarity-<?= (int)$w['rarity'] ?>"><?= rarity_label((int)$w['rarity']) ?></span> <?= htmlspecialchars($w['name']) ?></span>
                    <span class="mini-sub"><?= htmlspecialchars(engine_effect_description($w)) ?></span>
                    <?php if ($w['signature_card_name']): ?><span class="mini-sub sig"><?= htmlspecialchars($w['signature_card_name']) ?>専用</span><?php endif; ?>
                    <span class="mini-sub">未所持（ガチャで入手）</span>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <p><a href="/game/">ゲームのホームに戻る</a></p>
</body>
</html>
