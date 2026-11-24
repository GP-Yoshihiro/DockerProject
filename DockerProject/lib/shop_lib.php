<?php
// ショップ（ショップメダルでアイテムと交換）とデバッグ用のアイテム付与
require_once __DIR__ . '/game_lib.php';

/** デバッグ画面で付与できる量の上限（1回あたり） */
const DEBUG_GRANT_MAX = 1000000;

function fetch_shop_items(PDO $pdo): array
{
    return $pdo->query("
        SELECT si.id, si.item_id, si.quantity, si.price, i.name, i.category, i.description
        FROM shop_items si JOIN items i ON i.id = si.item_id
        ORDER BY si.id
    ")->fetchAll();
}

/**
 * ショップで交換する（メダル price × count を払って、アイテム quantity × count をもらう）
 */
function buy_shop_item(PDO $pdo, int $user_id, int $shop_item_id, int $count): array
{
    if ($count < 1 || $count > 99) {
        throw new GameException('交換する数が正しくありません。');
    }
    $item = null;
    foreach (fetch_shop_items($pdo) as $row) {
        if ((int)$row['id'] === $shop_item_id) {
            $item = $row;
        }
    }
    if ($item === null) {
        throw new GameException('ショップの商品が見つかりません。');
    }

    $pdo->beginTransaction();
    try {
        lock_user($pdo, $user_id);
        consume_item($pdo, $user_id, ITEM_MEDAL, (int)$item['price'] * $count);
        add_item($pdo, $user_id, (int)$item['item_id'], (int)$item['quantity'] * $count);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['name' => $item['name'], 'quantity' => (int)$item['quantity'] * $count, 'price' => (int)$item['price'] * $count];
}

/**
 * デバッグ: アイテムを付与する（動作確認用）
 */
function debug_grant_item(PDO $pdo, int $user_id, int $item_id, int $quantity): void
{
    if (!isset(fetch_item_master($pdo)[$item_id])) {
        throw new GameException('アイテムが見つかりません。');
    }
    if ($quantity < 1 || $quantity > DEBUG_GRANT_MAX) {
        throw new GameException('個数が正しくありません。');
    }
    add_item($pdo, $user_id, $item_id, $quantity);
}
