<?php
require_once __DIR__ . '/../../lib/box_gacha_lib.php';

$user_id = 1;

$message = null;

// リセット（POST → 処理後に GET でこのページを開き直す）
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        reset_box($pdo, $user_id, BOX_GACHA_ID);
        header('Location: box_gacha.php?reset=1');
        exit;
    } catch (GachaException $e) {
        $message = $e->getMessage();
    }
}
if (isset($_GET['reset'])) {
    $message = 'ボックスをリセットしました。';
}

$box = fetch_box_for_view($pdo, $user_id, BOX_GACHA_ID);
$left = array_sum(array_column($box, 'remaining'));
$total = array_sum(array_column($box, 'initial'));
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>ボックスガチャ</title>
    <link rel="stylesheet" href="/css/style.css">
    <link rel="stylesheet" href="/css/card.css">
</head>
<body>
    <h1>ボックスガチャ</h1>
    <p>当たったアイテムは箱から減っていきます。箱を引き切ると、中身がすべて手に入ります。</p>

    <?php if ($message !== null): ?>
        <p class="notice"><?= htmlspecialchars($message) ?></p>
    <?php endif; ?>

    <p>ボックスの残り: <?= htmlspecialchars($left) ?> / <?= htmlspecialchars($total) ?></p>
    <table border="1">
        <tr>
            <th>カードID</th>
            <th>カード名</th>
            <th>残り / 初期個数</th>
            <th>今引いたときの確率(%)</th>
        </tr>
        <?php foreach ($box as $row): ?>
        <tr>
            <td><?= htmlspecialchars($row['card_id']) ?></td>
            <td><img class="card-thumb" src="<?= htmlspecialchars(card_image_url($row['image'])) ?>" alt=""> <?= htmlspecialchars($row['name']) ?></td>
            <td><?= htmlspecialchars($row['remaining']) ?> / <?= htmlspecialchars($row['initial']) ?></td>
            <td><?= $left > 0 ? htmlspecialchars(number_format($row['remaining'] / $left * 100, 2)) : '-' ?></td>
        </tr>
        <?php endforeach; ?>
    </table>

    <form action="box_gacha_result.php" method="post">
        <p>
            <button type="submit" name="count" value="1" <?= $left < 1 ? 'disabled' : '' ?>>1回引く</button>
            <button type="submit" name="count" value="<?= GACHA_DRAW_COUNT ?>" <?= $left < GACHA_DRAW_COUNT ? 'disabled' : '' ?>>10連ガチャ実行</button>
        </p>
    </form>

    <form method="post" onsubmit="return confirm('ボックスを最初の状態に戻します。よろしいですか？');">
        <button type="submit">ボックスをリセット</button>
    </form>

    <p><a href="../">メニューに戻る</a></p>
    <script src="/js/submit-once.js" defer></script>
</body>
</html>
