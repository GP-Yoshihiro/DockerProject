<?php
require_once __DIR__ . '/../../lib/gacha_lib.php';

$user_id = 1; // ログイン機能がないため固定（forge_*.php と同じ）

// ガチャはDBを更新する処理なので POST のみ受け付ける（リロードや直リンクで回らないように）
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: gacha_form.html');
    exit;
}

$gacha_id = filter_input(INPUT_POST, 'gacha_id', FILTER_VALIDATE_INT) ?: 1;

// 引く回数は 単発(1) か 10連 のみ（指定が無いときは 10連）
$count = filter_input(INPUT_POST, 'count', FILTER_VALIDATE_INT) ?? GACHA_DRAW_COUNT;
if (!in_array($count, [1, GACHA_DRAW_COUNT], true)) {
    header('Location: gacha_form.html');
    exit;
}
$count_label = $count === 1 ? '単発' : "{$count}連";

$error = null;
$results = [];
try {
    $results = execute_gacha($pdo, $user_id, $gacha_id, $count);
} catch (GachaException $e) {
    // 期間外など、ユーザーに見せてよいエラー
    $error = $e->getMessage();
} catch (Throwable $e) {
    gacha_log('予期しないエラー', ['error' => $e->getMessage()]);
    $error = 'エラーが発生しました。';
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>ガチャ結果（<?= htmlspecialchars($count_label) ?>）</title>
    <link rel="stylesheet" href="/css/style.css">
    <link rel="stylesheet" href="/css/card.css">
    <?php render_reveal_head(); ?>
</head>
<body>
    <?php if ($error !== null): ?>
        <h1>ガチャ結果</h1>
        <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php else: ?>
        <h1>ガチャ結果（<?= htmlspecialchars($count_label) ?>）</h1>
        <?php render_card_results($results); ?>

        <!-- 同じガチャをもう一度引く -->
        <form class="retry" action="gacha_result.php" method="post">
            <input type="hidden" name="gacha_id" value="<?= htmlspecialchars($gacha_id) ?>">
            <button type="submit" name="count" value="1" class="btn-retry">もう1回（単発）</button>
            <button type="submit" name="count" value="<?= GACHA_DRAW_COUNT ?>" class="btn-retry btn-retry-main">もう<?= GACHA_DRAW_COUNT ?>連</button>
        </form>

        <p>引いたカードを所持カードに追加しました。（<a href="../card/card_list.php">所持カード一覧</a>）</p>
    <?php endif; ?>
    <p><a href="gacha_form.html">戻る</a></p>
    <script src="/js/submit-once.js" defer></script>
</body>
</html>
