<?php
// カード詳細: ステータス・スキル・凸効果、経験値アイテムの使用、進化
require_once __DIR__ . '/../../lib/card_growth_lib.php';

$user_id = GAME_USER_ID;
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$card = $id ? fetch_user_card($pdo, $user_id, $id) : null;
if (!$card) {
    header('Location: card_list.php');
    exit;
}

// POST → 処理 → このページを GET で開き直す（リロードで二重に実行されないように）
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        if ($action === 'exp') {
            $item_id = filter_input(INPUT_POST, 'item_id', FILTER_VALIDATE_INT) ?: 0;
            $quantity = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT) ?: 0;
            $r = use_exp_item($pdo, $user_id, $id, $item_id, $quantity);
            $msg = "経験値 {$r['gained']} を獲得しました（Lv{$r['level_before']} → Lv{$r['level_after']}）。";
            if ($r['wasted'] > 0) {
                $msg .= "レベル上限のため、経験値 {$r['wasted']} は使われませんでした。";
            }
            flash_set('notice', $msg);
        } elseif ($action === 'evolve') {
            $r = evolve_card($pdo, $user_id, $id);
            flash_set('notice', "{$r['name']} が進化しました！ レベル上限が {$r['level_cap']} になりました。");
        }
    } catch (GameException $e) {
        flash_set('error', $e->getMessage());
    }
    header('Location: card_detail.php?id=' . $id);
    exit;
}

$items = fetch_user_items($pdo, $user_id);
$master = fetch_item_master($pdo);
$exp_items = array_filter($master, fn ($i) => $i['category'] === 'exp');
$evo = evolution_requirement($card);
$lb = (int)$card['limit_break'];
$at_cap = (int)$card['level'] >= $card['level_cap'];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <?php render_game_head($card['name']); ?>
</head>
<body>
    <?php render_game_header($pdo, $user_id); ?>
    <h1><?= htmlspecialchars($card['name']) ?></h1>
    <?php render_flash(); ?>

    <div class="detail">
        <div class="detail-image rarity-<?= (int)$card['rarity'] ?><?= $lb >= MAX_LIMIT_BREAK ? ' maxed' : '' ?>">
            <img src="<?= htmlspecialchars($card['image_url']) ?>" alt="">
        </div>
        <div class="detail-body">
            <p>
                <span class="rarity-badge rarity-<?= (int)$card['rarity'] ?>"><?= rarity_label((int)$card['rarity']) ?></span>
                <?= attribute_badge($card['attribute']) ?>
                <span class="role-badge"><?= htmlspecialchars(role_label($card['role'])) ?></span>
                <span class="lb-marks"><?= limit_break_marks($lb) ?></span> <?= $lb ?>凸<?= $lb >= MAX_LIMIT_BREAK ? '（完凸）' : '' ?>
            </p>
            <p class="level-line">
                <strong>Lv<?= (int)$card['level'] ?></strong> / 上限 <?= (int)$card['level_cap'] ?>
                （進化 <?= (int)$card['evolution'] ?>/<?= MAX_EVOLUTION ?>）
            </p>
            <?php if (!$at_cap): ?>
                <div class="exp-bar"><span style="width: <?= min(100, (int)$card['exp'] / $card['exp_next'] * 100) ?>%"></span></div>
                <p class="muted">次のレベルまで: <?= (int)$card['exp_next'] - (int)$card['exp'] ?>（<?= (int)$card['exp'] ?> / <?= (int)$card['exp_next'] ?>）</p>
            <?php else: ?>
                <p class="muted">レベル上限です。<?= $evo ? '進化するとレベル上限が上がります。' : ($lb < 5 ? '3凸・5凸でレベル上限が上がります。' : '') ?></p>
            <?php endif; ?>

            <table class="stats">
                <tr><th>HP</th><td><?= number_format($card['stats']['hp']) ?></td><th>攻撃</th><td><?= number_format($card['stats']['atk']) ?></td></tr>
                <tr><th>防御</th><td><?= number_format($card['stats']['def']) ?></td><th>素早さ</th><td><?= number_format($card['stats']['spd']) ?></td></tr>
                <tr><th>戦力</th><td colspan="3"><?= number_format($card['power']) ?></td></tr>
            </table>

            <h2>スキル: <?= htmlspecialchars($card['skill']['name']) ?></h2>
            <p><?= htmlspecialchars(skill_description($card['skill'])) ?></p>
        </div>
    </div>

    <section class="panel">
        <h2>レベルアップ（経験値アイテム）</h2>
        <?php if ($at_cap): ?>
            <p class="muted">レベル上限のため使えません。</p>
        <?php else: ?>
            <form method="post" class="inline-form">
                <input type="hidden" name="id" value="<?= (int)$id ?>">
                <input type="hidden" name="action" value="exp">
                <select name="item_id">
                    <?php foreach ($exp_items as $item_id => $item): ?>
                        <option value="<?= $item_id ?>" <?= empty($items[$item_id]) ? 'disabled' : '' ?>>
                            <?= htmlspecialchars($item['name']) ?>（経験値 <?= (int)$item['exp_value'] ?> / 所持 <?= $items[$item_id] ?? 0 ?>）
                        </option>
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
            <p>最終進化済みです。<?= $lb < 5 ? '3凸・5凸でレベル上限がさらに上がります（最大60）。' : '' ?></p>
        <?php else: ?>
            <?php $crystals = $items[$evo['crystal_item_id']] ?? 0; $gold = $items[ITEM_GOLD] ?? 0; ?>
            <p>進化<?= $evo['next'] ?>: レベル上限 <?= (int)$card['level_cap'] ?> → <?= $evo['new_cap'] ?></p>
            <ul class="requirements">
                <li class="<?= $evo['ready'] ? 'ok' : 'ng' ?>">レベル <?= $evo['need_level'] ?>（今 Lv<?= (int)$card['level'] ?>）</li>
                <li class="<?= $crystals >= $evo['crystal'] ? 'ok' : 'ng' ?>"><?= htmlspecialchars(item_name($pdo, $evo['crystal_item_id'])) ?> ×<?= $evo['crystal'] ?>（所持 <?= $crystals ?>）</li>
                <li class="<?= $gold >= $evo['gold'] ? 'ok' : 'ng' ?>">ゴールド <?= number_format($evo['gold']) ?>（所持 <?= number_format($gold) ?>）</li>
            </ul>
            <form method="post">
                <input type="hidden" name="id" value="<?= (int)$id ?>">
                <input type="hidden" name="action" value="evolve">
                <button type="submit" <?= $evo['ready'] && $crystals >= $evo['crystal'] && $gold >= $evo['gold'] ? '' : 'disabled' ?>>進化する</button>
            </form>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>凸の効果（<?= htmlspecialchars(role_label($card['role'])) ?>）</h2>
        <p class="muted">ガチャで同じカードが出ると自動で凸します。</p>
        <table class="lb-table">
            <?php foreach (limit_break_effects($card['role']) as $n => $text): ?>
                <tr class="<?= $lb >= $n ? 'done' : '' ?>">
                    <th><?= $n ?>凸</th>
                    <td><?= htmlspecialchars($text) ?></td>
                    <td><?= $lb >= $n ? '達成' : '' ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </section>

    <p class="links"><a href="card_list.php">所持カード一覧に戻る</a> <a href="/team/team_list.php">チーム編成</a></p>
    <script src="/js/submit-once.js" defer></script>
</body>
</html>
