<?php
// ショップ: ショップメダル（完凸したカードがガチャで出ると手に入る）でアイテムと交換
require_once __DIR__ . '/../../lib/shop_lib.php';

$user_id = GAME_USER_ID;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $shop_item_id = filter_input(INPUT_POST, 'shop_item_id', FILTER_VALIDATE_INT) ?: 0;
    $count = filter_input(INPUT_POST, 'count', FILTER_VALIDATE_INT) ?: 0;
    try {
        $r = buy_shop_item($pdo, $user_id, $shop_item_id, $count);
        flash_set('notice', "{$r['name']} ×" . number_format($r['quantity']) . " と交換しました（メダル -{$r['price']}）。");
    } catch (GameException $e) {
        flash_set('error', $e->getMessage());
    }
    header('Location: shop.php');
    exit;
}

$shop = fetch_shop_items($pdo);
$medals = get_item_quantity($pdo, $user_id, ITEM_MEDAL);
$owned = fetch_user_items($pdo, $user_id);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <?php render_game_head('ショップ'); ?>
</head>
<body>
    <?php render_game_header($pdo, $user_id); ?>
    <h1>ショップ</h1>
    <?php render_flash(); ?>
    <p>ショップメダル: <strong><?= number_format($medals) ?></strong>
        <span class="muted">（完凸したカードがガチャで出ると、UR 50 / SSR 20 / SR 8 / R 3 / N 1 枚もらえます）</span></p>

    <table class="shop">
        <tr><th>商品</th><th>所持数</th><th>価格（メダル）</th><th></th></tr>
        <?php foreach ($shop as $s): ?>
            <tr>
                <td><?= htmlspecialchars($s['name']) ?> ×<?= number_format($s['quantity']) ?><br><span class="muted"><?= htmlspecialchars($s['description']) ?></span></td>
                <td class="num"><?= number_format($owned[$s['item_id']] ?? 0) ?></td>
                <td class="num"><?= number_format($s['price']) ?></td>
                <td>
                    <form method="post" class="inline-form">
                        <input type="hidden" name="shop_item_id" value="<?= (int)$s['id'] ?>">
                        <input type="number" name="count" value="1" min="1" max="99">
                        <button type="submit" <?= $medals < (int)$s['price'] ? 'disabled' : '' ?>>交換</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
    <script src="/js/submit-once.js" defer></script>
</body>
</html>
