<?php
require_once __DIR__ . '/../../lib/box_gacha_lib.php';

$user_id = 1;

// ガチャはDBを更新する処理なので POST のみ受け付ける
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: box_gacha.php');
    exit;
}

// 引ける回数は 1回 か 10連 のみ
$count = filter_input(INPUT_POST, 'count', FILTER_VALIDATE_INT);
if (!in_array($count, [1, GACHA_DRAW_COUNT], true)) {
    header('Location: box_gacha.php');
    exit;
}

$error = null;
$results = [];
try {
    $results = execute_box_gacha($pdo, $user_id, BOX_GACHA_ID, $count);
} catch (GachaException $e) {
    $error = $e->getMessage();
} catch (Throwable $e) {
    gacha_log('予期しないエラー', ['error' => $e->getMessage()]);
    $error = 'エラーが発生しました。';
}

$box = fetch_box_for_view($pdo, $user_id, BOX_GACHA_ID);
$left = array_sum(array_column($box, 'remaining'));
$total = array_sum(array_column($box, 'initial'));
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>ボックスガチャ結果</title>
    <link rel="stylesheet" href="/css/style.css">
    <link rel="stylesheet" href="/css/card.css">
    <?php render_reveal_head(); ?>
</head>
<body>
    <?php if ($error !== null): ?>
        <h1>ボックスガチャ結果</h1>
        <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php else: ?>
        <h1>ボックスガチャ結果</h1>
        <?php render_card_results($results); ?>
        <p>引いたカードを所持カードに追加しました。（<a href="../card/card_list.php">所持カード一覧</a>）</p>
    <?php endif; ?>
    <p>ボックスの残り: <?= htmlspecialchars($left) ?> / <?= htmlspecialchars($total) ?></p>

    <!-- 続けて引く（残りが足りないボタンは押せない） -->
    <form class="retry" action="box_gacha_result.php" method="post">
        <button type="submit" name="count" value="1" class="btn-retry" <?= $left < 1 ? 'disabled' : '' ?>>もう1回（単発）</button>
        <button type="submit" name="count" value="<?= GACHA_DRAW_COUNT ?>" class="btn-retry btn-retry-main" <?= $left < GACHA_DRAW_COUNT ? 'disabled' : '' ?>>もう<?= GACHA_DRAW_COUNT ?>連</button>
    </form>

    <p><a href="box_gacha.php">戻る</a></p>
    <script src="/js/submit-once.js" defer></script>
</body>
</html>
