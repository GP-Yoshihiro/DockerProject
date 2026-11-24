<?php
require_once __DIR__ . '/../../lib/gacha_lib.php';
require_once __DIR__ . '/../../lib/gacha_export_lib.php';

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

// 資料05: ガチャ結果を JSON 化して表示し、JSON・CSV ファイルに出力する
$json = null;          // 全項目
$client_json = null;   // クライアント用（必要な項目のみ）
$export_paths = [];
$export_error = null;
if ($error === null) {
    $json = gacha_to_json(gacha_results_to_items($results));
    $client_json = gacha_to_json(gacha_results_to_client_items($results));
    try {
        $export_paths = export_gacha_results($results);
        gacha_log('ガチャ結果をファイル出力', $export_paths);
    } catch (Throwable $e) {
        // ファイル出力に失敗してもガチャ自体は成功しているので、結果はそのまま表示する
        gacha_log('ガチャ結果のファイル出力に失敗', ['error' => $e->getMessage()]);
        $export_error = $e->getMessage();
    }
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

    <?php if ($json !== null): ?>
        <section class="json-output">
            <h2>JSONファイル表示</h2>
            <pre class="json-view"><?= htmlspecialchars($json) ?></pre>

            <h2>JSON（クライアント用・必要な項目のみ）</h2>
            <p class="json-note">ガチャ演出に使う項目だけに絞ったもの（重みは渡さず、画像はURLにしています）</p>
            <pre class="json-view"><?= htmlspecialchars($client_json) ?></pre>

            <h2>JSONファイル出力結果</h2>
            <?php if ($export_error !== null): ?>
                <p class="error">ファイルに保存できませんでした: <?= htmlspecialchars($export_error) ?></p>
            <?php else: ?>
                <p>JSON結果をファイルに保存しました: <strong><?= htmlspecialchars($export_paths['json']) ?></strong></p>
                <p>CSV結果をファイルに保存しました: <strong><?= htmlspecialchars($export_paths['csv']) ?></strong></p>
            <?php endif; ?>
        </section>
    <?php endif; ?>
    <script src="/js/submit-once.js" defer></script>
</body>
</html>
