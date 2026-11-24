<?php
// ピックアップガチャの結果
require_once __DIR__ . '/../../lib/banner_gacha_lib.php';

$user_id = GAME_USER_ID;

// ガチャは DB を更新する処理なので POST のみ受け付ける（リロードや直リンクで回らないように）
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: gacha_form.php');
    exit;
}
$banner_id = filter_input(INPUT_POST, 'banner_id', FILTER_VALIDATE_INT) ?: 0;
$count = filter_input(INPUT_POST, 'count', FILTER_VALIDATE_INT) ?: 0;

try {
    $draw = execute_banner_gacha($pdo, $user_id, $banner_id, $count);
} catch (GameException $e) {
    flash_set('error', $e->getMessage());
    header('Location: gacha_form.php');
    exit;
}
$banner = $draw['banner'];
$pity = $draw['pity'];
$rate = BANNER_RATES[$banner['type']];
$stones = get_item_quantity($pdo, $user_id, ITEM_STONE);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <?php render_game_head('ガチャ結果'); ?>
    <link rel="stylesheet" href="/css/card.css">
    <?php render_reveal_head(); ?>
</head>
<body>
    <?php render_game_header($pdo, $user_id); ?>
    <h1><?= htmlspecialchars($banner['name']) ?>（<?= $count ?>回）</h1>
    <?php render_card_results($draw['results']); ?>

    <p class="muted">
        S級まであと <?= $rate['hard'] - $pity['s_count'] ?> 連 ／ A級以上まであと <?= A_GUARANTEE - $pity['a_count'] ?> 連
        <?= $pity['guaranteed'] ? '／ <strong>次のS級はピックアップ確定</strong>' : '' ?>
    </p>

    <form class="retry" action="banner_result.php" method="post">
        <input type="hidden" name="banner_id" value="<?= (int)$banner['id'] ?>">
        <button type="submit" name="count" value="1" class="btn-retry" <?= $stones < gacha_cost(1) ? 'disabled' : '' ?>>もう1回（石 <?= number_format(gacha_cost(1)) ?>）</button>
        <button type="submit" name="count" value="<?= GACHA_DRAW_COUNT ?>" class="btn-retry btn-retry-main" <?= $stones < gacha_cost(GACHA_DRAW_COUNT) ? 'disabled' : '' ?>>もう<?= GACHA_DRAW_COUNT ?>回（石 <?= number_format(gacha_cost(GACHA_DRAW_COUNT)) ?>）</button>
    </form>
    <p class="links">
        <a href="gacha_form.php">ガチャ一覧</a>
        <a href="/card/card_list.php">所持キャラ</a>
        <a href="/card/engine_list.php">所持音動機</a>
    </p>
    <script src="/js/submit-once.js" defer></script>
</body>
</html>
