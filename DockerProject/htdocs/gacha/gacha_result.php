<?php
require_once __DIR__ . '/../../lib/gacha_lib.php';

$user_id = 1; // ログイン機能がないため固定（forge_*.php と同じ）

// ガチャはDBを更新する処理なので POST のみ受け付ける（リロードや直リンクで回らないように）
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: gacha_form.html');
    exit;
}

$gacha_id = filter_input(INPUT_POST, 'gacha_id', FILTER_VALIDATE_INT) ?: 1;

$error = null;
$results = [];
try {
    $results = execute_gacha($pdo, $user_id, $gacha_id);
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
    <title>ガチャ結果</title>
</head>
<body>
    <?php if ($error !== null): ?>
        <p><?= htmlspecialchars($error) ?></p>
    <?php else: ?>
        ガチャ結果<br>
        <?php foreach ($results as $item): ?>
            <?= htmlspecialchars($item['card_id']) ?>：<?= htmlspecialchars($item['name']) ?><br>
        <?php endforeach; ?>
        <p>引いたカードを所持カードに追加しました。（<a href="../card/card_list.php">所持カード一覧</a>）</p>
    <?php endif; ?>
    <a href="gacha_form.html">戻る</a>
</body>
</html>
