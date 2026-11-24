<?php
// 音動機の詳細: ステータス・効果、強化アイテム（チューニングパーツ）でレベルアップ、進化、装備
require_once __DIR__ . '/../../lib/engine_lib.php';

$user_id = GAME_USER_ID;
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$engine = $id ? fetch_user_engine($pdo, $user_id, $id) : null;
if (!$engine) {
    header('Location: engine_list.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        if ($action === 'exp') {
            $r = use_engine_exp_item($pdo, $user_id, $id,
                filter_input(INPUT_POST, 'item_id', FILTER_VALIDATE_INT) ?: 0,
                filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT) ?: 0);
            $msg = "経験値 {$r['gained']} を獲得しました（Lv{$r['level_before']} → Lv{$r['level_after']}）。";
            if ($r['wasted'] > 0) {
                $msg .= "レベル上限のため、経験値 {$r['wasted']} は使われませんでした。";
            }
            flash_set('notice', $msg);
        } elseif ($action === 'evolve') {
            $r = evolve_engine($pdo, $user_id, $id);
            flash_set('notice', "{$r['name']} が進化しました！ レベル上限が {$r['level_cap']} になりました。");
        } elseif ($action === 'equip') {
            $card_id = filter_input(INPUT_POST, 'user_card_id', FILTER_VALIDATE_INT) ?: null;
            equip_engine($pdo, $user_id, $id, $card_id);
            flash_set('notice', $card_id ? '装備しました。' : '装備を外しました。');
        }
    } catch (GameException $e) {
        flash_set('error', $e->getMessage());
    }
    header('Location: engine_detail.php?id=' . $id);
    exit;
}

$items = fetch_user_items($pdo, $user_id);
$exp_items = array_filter(fetch_item_master($pdo), fn ($i) => $i['category'] === 'engine_exp');
$evo = engine_evolution_requirement($engine);
$lb = (int)$engine['limit_break'];
$at_cap = (int)$engine['level'] >= $engine['level_cap'];
$cards = fetch_user_card_list($pdo, $user_id);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <?php render_game_head($engine['name']); ?>
</head>
<body>
    <?php render_game_header($pdo, $user_id); ?>
    <h1><?= htmlspecialchars($engine['name']) ?></h1>
    <?php render_flash(); ?>

    <div class="detail">
        <div class="detail-image engine rarity-<?= (int)$engine['rarity'] ?><?= $lb >= MAX_ENGINE_LIMIT_BREAK ? ' maxed' : '' ?>">
            <img src="<?= htmlspecialchars($engine['image_url']) ?>" alt="">
        </div>
        <div class="detail-body">
            <p>
                <span class="rarity-badge rarity-<?= (int)$engine['rarity'] ?>"><?= rarity_label((int)$engine['rarity']) ?></span> 音動機
                ／ <?= $lb ?>凸<?= $lb >= MAX_ENGINE_LIMIT_BREAK ? '（完凸）' : '' ?>
            </p>
            <p class="level-line"><strong>Lv<?= (int)$engine['level'] ?></strong> / 上限 <?= (int)$engine['level_cap'] ?>（進化 <?= (int)$engine['evolution'] ?>/<?= MAX_EVOLUTION ?>）</p>
            <?php if (!$at_cap): ?>
                <div class="exp-bar"><span style="width: <?= min(100, (int)$engine['exp'] / $engine['exp_next'] * 100) ?>%"></span></div>
                <p class="muted">次のレベルまで: <?= (int)$engine['exp_next'] - (int)$engine['exp'] ?></p>
            <?php endif; ?>
            <table class="stats">
                <tr><th>攻撃力</th><td>+<?= number_format($engine['values']['atk']) ?></td></tr>
                <tr><th>サブステータス</th><td><?= htmlspecialchars($engine['sub_text']) ?></td></tr>
                <tr><th>効果</th><td><?= htmlspecialchars($engine['effect_text']) ?></td></tr>
                <?php if ($engine['signature_text']): ?>
                    <tr><th>専用効果</th><td class="sig"><?= htmlspecialchars($engine['signature_text']) ?></td></tr>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <section class="panel">
        <h2>装備</h2>
        <p>装備中: <?= $engine['equipped_card_name'] ? '<strong>' . htmlspecialchars($engine['equipped_card_name']) . '</strong>' : 'なし' ?></p>
        <form method="post" class="inline-form">
            <input type="hidden" name="id" value="<?= (int)$id ?>">
            <input type="hidden" name="action" value="equip">
            <select name="user_card_id">
                <option value="">（外す）</option>
                <?php foreach ($cards as $c): ?>
                    <option value="<?= (int)$c['user_card_id'] ?>" <?= (int)$engine['equipped_user_card_id'] === (int)$c['user_card_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['name']) ?>（Lv<?= (int)$c['level'] ?>）<?= (int)$engine['signature_card_id'] === (int)$c['card_id'] ? ' ★専用' : '' ?><?= $c['engine_name'] && (int)$c['engine_user_id'] !== (int)$id ? " ※{$c['engine_name']} と入れ替え" : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit">装備する</button>
        </form>
    </section>

    <section class="panel">
        <h2>レベルアップ（チューニングパーツ）</h2>
        <?php if ($at_cap): ?>
            <p class="muted">レベル上限のため使えません。</p>
        <?php else: ?>
            <form method="post" class="inline-form">
                <input type="hidden" name="id" value="<?= (int)$id ?>">
                <input type="hidden" name="action" value="exp">
                <select name="item_id">
                    <?php foreach ($exp_items as $item_id => $item): ?>
                        <option value="<?= $item_id ?>" <?= empty($items[$item_id]) ? 'disabled' : '' ?>><?= htmlspecialchars($item['name']) ?>（経験値 <?= (int)$item['exp_value'] ?> / 所持 <?= $items[$item_id] ?? 0 ?>）</option>
                    <?php endforeach; ?>
                </select>
                <input type="number" name="quantity" value="1" min="1" max="99">
                <button type="submit">使う</button>
            </form>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>進化</h2>
        <?php if ($evo === null): ?>
            <p>最終進化済みです。</p>
        <?php else: ?>
            <?php $cores = $items[ITEM_ENGINE_CORE] ?? 0; $gold = $items[ITEM_GOLD] ?? 0; ?>
            <p>進化<?= $evo['next'] ?>: レベル上限 <?= (int)$engine['level_cap'] ?> → <?= $evo['new_cap'] ?>（サブステータスもアップ）</p>
            <ul class="requirements">
                <li class="<?= $evo['ready'] ? 'ok' : 'ng' ?>">レベル <?= $evo['need_level'] ?>（今 Lv<?= (int)$engine['level'] ?>）</li>
                <li class="<?= $cores >= $evo['core'] ? 'ok' : 'ng' ?>">チューニングコア ×<?= $evo['core'] ?>（所持 <?= $cores ?>）</li>
                <li class="<?= $gold >= $evo['gold'] ? 'ok' : 'ng' ?>">ゴールド <?= number_format($evo['gold']) ?>（所持 <?= number_format($gold) ?>）</li>
            </ul>
            <form method="post">
                <input type="hidden" name="id" value="<?= (int)$id ?>">
                <input type="hidden" name="action" value="evolve">
                <button type="submit" <?= $evo['ready'] && $cores >= $evo['core'] && $gold >= $evo['gold'] ? '' : 'disabled' ?>>進化する</button>
            </form>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>凸の効果</h2>
        <p class="muted">ガチャで同じ音動機が出ると自動で凸します（最大<?= MAX_ENGINE_LIMIT_BREAK ?>凸）。</p>
        <table class="lb-table">
            <?php foreach (engine_limit_break_effects() as $n => $text): ?>
                <tr class="<?= $lb >= $n ? 'done' : '' ?>"><th><?= $n ?>凸</th><td><?= htmlspecialchars($text) ?></td><td><?= $lb >= $n ? '達成' : '' ?></td></tr>
            <?php endforeach; ?>
        </table>
    </section>

    <p class="links"><a href="engine_list.php">音動機一覧に戻る</a> <a href="card_list.php">所持キャラ</a></p>
    <script src="/js/submit-once.js" defer></script>
</body>
</html>
