<?php
// 所持アイテム一覧
require_once __DIR__ . '/../../lib/game_lib.php';

$user_id = GAME_USER_ID;
$items = fetch_user_items($pdo, $user_id);
$master = fetch_item_master($pdo);
$categories = ['currency' => '通貨', 'evolution' => 'キャラの進化素材', 'exp' => 'キャラの経験値アイテム',
               'engine_exp' => '音動機の強化アイテム', 'engine_evolution' => '音動機の進化素材'];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <?php render_game_head('アイテム'); ?>
</head>
<body>
    <?php render_game_header($pdo, $user_id); ?>
    <h1>アイテム</h1>
    <?php foreach ($categories as $category => $label): ?>
        <h2><?= $label ?></h2>
        <table>
            <tr><th>アイテム</th><th>所持数</th><th>説明</th></tr>
            <?php foreach ($master as $id => $item): ?>
                <?php if ($item['category'] !== $category) { continue; } ?>
                <tr>
                    <td><?= $item['attribute'] ? attribute_badge($item['attribute']) . ' ' : '' ?><?= htmlspecialchars($item['name']) ?></td>
                    <td class="num"><?= number_format($items[$id] ?? 0) ?></td>
                    <td><?= htmlspecialchars($item['description']) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endforeach; ?>
    <p class="muted">経験値アイテムは、カード詳細の画面で使えます（<a href="/card/card_list.php">所持カード</a>）。</p>
</body>
</html>
