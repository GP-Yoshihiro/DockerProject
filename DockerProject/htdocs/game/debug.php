<?php
// デバッグ: 動作確認用にアイテム（ガチャ石など）を付与する
// ※ 本番のゲームでは置かない画面
require_once __DIR__ . '/../../lib/shop_lib.php';

$user_id = GAME_USER_ID;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $item_id = filter_input(INPUT_POST, 'item_id', FILTER_VALIDATE_INT) ?: 0;
    $quantity = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT) ?: 0;
    try {
        debug_grant_item($pdo, $user_id, $item_id, $quantity);
        flash_set('notice', item_name($pdo, $item_id) . ' を ' . number_format($quantity) . ' 付与しました。');
    } catch (GameException $e) {
        flash_set('error', $e->getMessage());
    }
    header('Location: debug.php');
    exit;
}

$master = fetch_item_master($pdo);
$owned = fetch_user_items($pdo, $user_id);
$presets = [
    [ITEM_STONE, 10000, 'ガチャ石 +10000'],
    [ITEM_GOLD, 50000, 'ゴールド +50000'],
    [ITEM_MEDAL, 100, 'メダル +100'],
    [22, 10, '経験値ポーション（中） +10'],
    [23, 10, '経験値ポーション（大） +10'],
    [33, 10, 'チューニングパーツ（大） +10'],
    [35, 20, 'チューニングコア +20'],
];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <?php render_game_head('デバッグ'); ?>
</head>
<body>
    <?php render_game_header($pdo, $user_id); ?>
    <h1>デバッグ（アイテム付与）</h1>
    <?php render_flash(); ?>
    <p class="error">動作確認用の画面です。</p>

    <section class="panel">
        <h2>よく使うもの</h2>
        <div class="preset-buttons">
            <?php foreach ($presets as [$item_id, $quantity, $label]): ?>
                <form method="post">
                    <input type="hidden" name="item_id" value="<?= $item_id ?>">
                    <input type="hidden" name="quantity" value="<?= $quantity ?>">
                    <button type="submit"><?= htmlspecialchars($label) ?></button>
                </form>
            <?php endforeach; ?>
            <?php foreach (CRYSTAL_ITEM_IDS as $attr => $item_id): ?>
                <form method="post">
                    <input type="hidden" name="item_id" value="<?= $item_id ?>">
                    <input type="hidden" name="quantity" value="20">
                    <button type="submit"><?= htmlspecialchars(item_name($pdo, $item_id)) ?> +20</button>
                </form>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="panel">
        <h2>個数を指定して付与</h2>
        <form method="post" class="inline-form">
            <select name="item_id">
                <?php foreach ($master as $id => $item): ?>
                    <option value="<?= $id ?>"><?= htmlspecialchars($item['name']) ?>（所持 <?= number_format($owned[$id] ?? 0) ?>）</option>
                <?php endforeach; ?>
            </select>
            <input type="number" name="quantity" value="1" min="1" max="<?= DEBUG_GRANT_MAX ?>">
            <button type="submit">付与</button>
        </form>
    </section>
</body>
</html>
