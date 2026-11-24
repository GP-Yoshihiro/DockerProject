<?php
// ゲーム全体の共通処理（属性・タイプの表示名、アイテム（通貨）の増減、ヘッダー表示）
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/card_lib.php';

date_default_timezone_set('Asia/Tokyo');

// メッセージの一時保存（flash_set）にセッションを使う。画面を出力する前に開始しておく
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    session_start();
}

/** ログイン機能がないため、遊ぶユーザーは固定 */
const GAME_USER_ID = 1;

const ATTRIBUTES = [
    'ether'    => 'エーテル',
    'fire'     => '炎',
    'ice'      => '氷',
    'physical' => '物理',
    'electric' => '電気',
];

const ROLES = [
    'attacker' => 'アタッカー',
    'tank'     => 'タンク',
    'healer'   => 'ヒーラー',
    'support'  => 'サポート',
];

// よく使うアイテムのID（items テーブル）
const ITEM_STONE = 1;   // ガチャ石
const ITEM_GOLD  = 2;   // ゴールド
const ITEM_MEDAL = 3;   // ショップメダル

/** 属性 => その属性の結晶（進化素材）のアイテムID */
const CRYSTAL_ITEM_IDS = [
    'ether'    => 11,
    'fire'     => 12,
    'ice'      => 13,
    'physical' => 14,
    'electric' => 15,
];

/** 画面にそのまま表示してよいゲームのエラー（石が足りない など） */
class GameException extends Exception {}

function attribute_label(?string $attribute): string
{
    return $attribute === null ? '—' : (ATTRIBUTES[$attribute] ?? $attribute);
}

function role_label(string $role): string
{
    return ROLES[$role] ?? $role;
}

/**
 * 属性バッジの HTML
 */
function attribute_badge(?string $attribute): string
{
    if ($attribute === null) {
        return '<span class="attr-badge">—</span>';
    }
    return sprintf('<span class="attr-badge attr-%s">%s</span>', htmlspecialchars($attribute), htmlspecialchars(attribute_label($attribute)));
}

/**
 * ユーザーの行をロックする（同じユーザーの処理が同時に動いて、石を二重に使ったりしないように）
 * 呼び出し側でトランザクションを開始しておくこと
 *
 * users の行を FOR UPDATE でロックすると、同じユーザーの別リクエストは
 * このトランザクションが終わる（commit / rollBack）まで待たされる。
 * ※ ボックスガチャで、ボックスの行を直接ロックするだけだと、INSERT IGNORE（共有ロック）→ FOR UPDATE（排他ロック）の
 *   順にロックを強めるところで、同時リクエスト同士がお互いを待ってデッドロックになる。
 */
function lock_user(PDO $pdo, int $user_id): void
{
    $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? FOR UPDATE");
    $stmt->execute([$user_id]);
    if (!$stmt->fetch()) {
        throw new GameException('ユーザーが見つかりません。');
    }
}

/**
 * アイテムマスタを [id => 行] で取得する
 */
function fetch_item_master(PDO $pdo): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach ($pdo->query("SELECT id, name, category, attribute, exp_value, description FROM items ORDER BY id") as $row) {
            $cache[(int)$row['id']] = $row;
        }
    }
    return $cache;
}

function item_name(PDO $pdo, int $item_id): string
{
    return fetch_item_master($pdo)[$item_id]['name'] ?? "アイテム{$item_id}";
}

/**
 * 所持アイテムを [item_id => 個数] で取得する（持っていないアイテムは含まれない）
 */
function fetch_user_items(PDO $pdo, int $user_id): array
{
    $stmt = $pdo->prepare("SELECT item_id, quantity FROM user_items WHERE user_id = ? AND quantity > 0 ORDER BY item_id");
    $stmt->execute([$user_id]);
    return array_map('intval', array_column($stmt->fetchAll(), 'quantity', 'item_id'));
}

function get_item_quantity(PDO $pdo, int $user_id, int $item_id): int
{
    $stmt = $pdo->prepare("SELECT quantity FROM user_items WHERE user_id = ? AND item_id = ?");
    $stmt->execute([$user_id, $item_id]);
    return (int)$stmt->fetchColumn();
}

/**
 * アイテムを増やす
 */
function add_item(PDO $pdo, int $user_id, int $item_id, int $quantity): void
{
    if ($quantity <= 0) {
        return;
    }
    $stmt = $pdo->prepare("
        INSERT INTO user_items (user_id, item_id, quantity) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
    ");
    $stmt->execute([$user_id, $item_id, $quantity]);
}

/**
 * アイテムを減らす。足りなければ GameException
 * 「足りているときだけ減らす」を1つの UPDATE で行うので、同時に実行されてもマイナスにならない
 */
function consume_item(PDO $pdo, int $user_id, int $item_id, int $quantity): void
{
    if ($quantity <= 0) {
        return;
    }
    $stmt = $pdo->prepare("
        UPDATE user_items SET quantity = quantity - ?
        WHERE user_id = ? AND item_id = ? AND quantity >= ?
    ");
    $stmt->execute([$quantity, $user_id, $item_id, $quantity]);
    if ($stmt->rowCount() === 0) {
        $have = get_item_quantity($pdo, $user_id, $item_id);
        throw new GameException(sprintf('%sが足りません（必要 %s / 所持 %s）。',
            item_name($pdo, $item_id), number_format($quantity), number_format($have)));
    }
}

/**
 * 報酬の配列 [['item_id' => .., 'quantity' => ..], ...] をまとめて付与する
 */
function grant_items(PDO $pdo, int $user_id, array $rewards): void
{
    foreach ($rewards as $r) {
        add_item($pdo, $user_id, (int)$r['item_id'], (int)$r['quantity']);
    }
}

/**
 * ゲーム画面の上に出すヘッダー（所持ガチャ石・ゴールド・メダルとナビゲーション）
 */
function render_game_header(PDO $pdo, int $user_id): void
{
    $items = fetch_user_items($pdo, $user_id);
    $wallet = [
        ['ガチャ石', $items[ITEM_STONE] ?? 0, 'stone'],
        ['ゴールド', $items[ITEM_GOLD] ?? 0, 'gold'],
        ['メダル', $items[ITEM_MEDAL] ?? 0, 'medal'],
    ];
    $links = [
        '/game/' => 'ホーム',
        '/game/stages.php' => 'ステージ',
        '/game/pvp.php' => '対戦',
        '/gacha/gacha_form.php' => 'ガチャ',
        '/card/card_list.php' => 'カード',
        '/team/team_list.php' => '編成',
        '/game/items.php' => 'アイテム',
        '/game/shop.php' => 'ショップ',
        '/battle/ranking.php' => 'ランキング',
    ];
    ?>
    <header class="game-header">
        <nav class="game-nav">
            <?php foreach ($links as $href => $label): ?>
                <a href="<?= $href ?>"><?= $label ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="wallet">
            <?php foreach ($wallet as [$label, $value, $class]): ?>
                <span class="wallet-item wallet-<?= $class ?>"><?= $label ?> <strong><?= number_format($value) ?></strong></span>
            <?php endforeach; ?>
        </div>
    </header>
    <?php
}

/**
 * ゲーム画面の <head> に入れる共通の CSS
 */
function render_game_head(string $title): void
{
    ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title) ?></title>
    <link rel="stylesheet" href="/css/style.css">
    <link rel="stylesheet" href="/css/game.css">
    <?php
}

/**
 * POST の後にメッセージを表示するための一時保存（PRG パターン: 処理 → リダイレクト → 表示）
 */
function flash_set(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function render_flash(): void
{
    foreach ($_SESSION['flash'] ?? [] as $f) {
        printf('<p class="%s">%s</p>', $f['type'] === 'error' ? 'error' : 'notice', htmlspecialchars($f['message']));
    }
    unset($_SESSION['flash']);
}
