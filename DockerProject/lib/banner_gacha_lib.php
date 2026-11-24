<?php
// ピックアップガチャ（限定キャラ / 限定音動機 / 常設）: 天井・すり抜け・10回目のA級以上確定
//
//   限定キャラ:   S級 0.6%・90連目で確定・PU 50%（すり抜け後は次のS級が PU 確定）→ 最大180連で PU
//   限定音動機:   S級 1.0%・80連目で確定・PU 75%（すり抜け後は次のS級が PU 確定）→ 最大160連で PU
//   常設:         S級 0.6%・90連目で確定（S級キャラ・S級音動機が半々）
//   A級: キャラ・常設 9.4% / 音動機 15%。10回目は必ずA級以上（キャラガチャはA級以上のキャラ、音動機ガチャはA級以上の音動機）
//   それ以外は B級音動機
//
// 天井のカウントはガチャの種類（char / engine / standard）ごとに持つので、ピックアップを変えても引き継がれる
require_once __DIR__ . '/engine_lib.php';
require_once __DIR__ . '/gacha_lib.php';

/** 確率は 1万分率（60 = 0.6%） */
const BANNER_RATES = [
    'char'     => ['s' => 60,  'a' => 940,  'hard' => 90, 'pu' => 50],
    'engine'   => ['s' => 100, 'a' => 1500, 'hard' => 80, 'pu' => 75],
    'standard' => ['s' => 60,  'a' => 940,  'hard' => 90, 'pu' => null],
];
/** この回数目で必ずA級以上 */
const A_GUARANTEE = 10;

const BANNER_TYPE_LABELS = ['char' => '限定キャラ', 'engine' => '限定音動機', 'standard' => '常設'];

/** キャラのレア度（cards.rarity）: S級 = 5 / A級 = 3。音動機（w_engines.rarity）: S級 = 4 / A級 = 2 / B級 = 1 */
const CARD_RARITY_S = 5;
const CARD_RARITY_A = 3;
const ENGINE_RARITY_S = 4;
const ENGINE_RARITY_A = 2;
const ENGINE_RARITY_B = 1;

// ---------------------------------------------------------------
// 抽選（DB を使わない計算。シミュレーションでも使う）
// ---------------------------------------------------------------

/**
 * 1回抽選する。$pity（s_count / a_count / guaranteed）を書き換える
 *
 * $pools = [
 *   's_char' => [...], 's_engine' => [...],      // 常設の S級（すり抜け・常設ガチャで出る）
 *   'a_char' => [...], 'a_engine' => [...], 'b_engine' => [...],
 *   'pickup' => キャラ or 音動機（無ければ null）,
 * ]
 * 各要素は ['kind' => 'card' | 'engine', 'id' => .., 'tier' => ..] を含む配列
 * 戻り値: ['item' => 当たり, 'is_pickup' => bool, 'pull_no' => 何連目で出たか（S級のとき）, 'forced' => 10回目の確定で出たか]
 */
function banner_roll(string $type, array &$pity, array $pools, ?callable $rng = null): array
{
    $rng ??= 'random_int';
    $rate = BANNER_RATES[$type];

    $pity['s_count']++;
    $pity['a_count']++;
    $pull_no = $pity['s_count'];
    $r = $rng(1, 10000);

    if ($pity['s_count'] >= $rate['hard'] || $r <= $rate['s']) {
        // ---- S級 ----
        $pity['s_count'] = 0;
        $pity['a_count'] = 0;
        $pickup = $pools['pickup'] ?? null;

        if ($type === 'standard' || $pickup === null) {
            $pool = $type === 'engine' ? $pools['s_engine'] : ($type === 'char' ? $pools['s_char'] : null);
            if ($pool === null) {
                // 常設: S級キャラと S級音動機が半々
                $pool = $rng(1, 2) === 1 ? $pools['s_char'] : $pools['s_engine'];
                $pool = $pool ?: ($pools['s_char'] ?: $pools['s_engine']);
            }
            return ['item' => banner_pick($pool, $rng), 'is_pickup' => false, 'pull_no' => $pull_no, 'forced' => false];
        }

        // ピックアップ: 確定（前回すり抜け）か、PU 率で当たり
        $others = array_values(array_filter(
            $type === 'char' ? $pools['s_char'] : $pools['s_engine'],
            fn ($i) => $i['id'] !== $pickup['id']
        ));
        if ($pity['guaranteed'] || $rng(1, 100) <= $rate['pu'] || !$others) {
            $pity['guaranteed'] = false;
            return ['item' => $pickup, 'is_pickup' => true, 'pull_no' => $pull_no, 'forced' => false];
        }
        // すり抜け → 次の S級は PU 確定
        $pity['guaranteed'] = true;
        return ['item' => banner_pick($others, $rng), 'is_pickup' => false, 'pull_no' => $pull_no, 'forced' => false];
    }

    if ($pity['a_count'] >= A_GUARANTEE || $r <= $rate['s'] + $rate['a']) {
        // ---- A級 ----
        $forced = $r > $rate['s'] + $rate['a'];
        $pity['a_count'] = 0;
        if ($type === 'char') {
            // 10回目の確定はキャラ。普通に当たったときはキャラと音動機が半々
            $pool = $forced || $rng(1, 2) === 1 ? $pools['a_char'] : $pools['a_engine'];
        } elseif ($type === 'engine') {
            $pool = $pools['a_engine'];
        } else {
            $pool = $rng(1, 2) === 1 ? $pools['a_char'] : $pools['a_engine'];
        }
        $pool = $pool ?: ($pools['a_char'] ?: $pools['a_engine']);
        return ['item' => banner_pick($pool, $rng), 'is_pickup' => false, 'pull_no' => $pull_no, 'forced' => $forced];
    }

    // ---- B級音動機 ----
    $pool = $pools['b_engine'] ?: $pools['a_engine'];
    return ['item' => banner_pick($pool, $rng), 'is_pickup' => false, 'pull_no' => $pull_no, 'forced' => false];
}

function banner_pick(array $pool, callable $rng): array
{
    if (!$pool) {
        throw new GachaException('ガチャから出るキャラ・音動機が登録されていません（登録画面で設定してください）。');
    }
    return $pool[$rng(0, count($pool) - 1)];
}

// ---------------------------------------------------------------
// DB
// ---------------------------------------------------------------

function fetch_banners(PDO $pdo): array
{
    $rows = $pdo->query("SELECT * FROM banners ORDER BY id")->fetchAll();
    return array_column($rows, null, 'id');
}

/**
 * ガチャから出るもの（ガチャで有効なキャラ・音動機）
 */
function fetch_banner_pools(PDO $pdo, array $banner): array
{
    $cards = $pdo->query("SELECT * FROM cards WHERE is_active = TRUE ORDER BY id")->fetchAll();
    $engines = $pdo->query("SELECT * FROM w_engines WHERE is_active = TRUE ORDER BY id")->fetchAll();

    $pools = ['s_char' => [], 's_engine' => [], 'a_char' => [], 'a_engine' => [], 'b_engine' => [], 'pickup' => null];
    foreach ($cards as $c) {
        $item = banner_card_item($c);
        if ((int)$c['rarity'] === CARD_RARITY_S && $c['pool'] === 'standard') {
            $pools['s_char'][] = $item;
        } elseif ((int)$c['rarity'] === CARD_RARITY_A && $c['pool'] === 'standard') {
            $pools['a_char'][] = $item;
        }
    }
    foreach ($engines as $e) {
        $item = banner_engine_item($e);
        $key = [ENGINE_RARITY_S => 's_engine', ENGINE_RARITY_A => 'a_engine', ENGINE_RARITY_B => 'b_engine'][(int)$e['rarity']] ?? null;
        if ($key !== null && ($e['pool'] === 'standard' || $key === 'b_engine')) {
            $pools[$key][] = $item;
        }
    }

    // ピックアップ（限定でも出る。ガチャで無効にしていても、ピックアップに設定していれば出る）
    if ($banner['type'] === 'char' && $banner['pickup_card_id']) {
        $stmt = $pdo->prepare("SELECT * FROM cards WHERE id = ?");
        $stmt->execute([$banner['pickup_card_id']]);
        $row = $stmt->fetch();
        $pools['pickup'] = $row ? banner_card_item($row) : null;
    } elseif ($banner['type'] === 'engine' && $banner['pickup_engine_id']) {
        $stmt = $pdo->prepare("SELECT * FROM w_engines WHERE id = ?");
        $stmt->execute([$banner['pickup_engine_id']]);
        $row = $stmt->fetch();
        $pools['pickup'] = $row ? banner_engine_item($row) : null;
    }
    return $pools;
}

function banner_card_item(array $c): array
{
    $stand = card_stand_url($c);
    return [
        'kind' => 'card', 'id' => (int)$c['id'], 'tier' => (int)$c['rarity'], 'name' => $c['name'],
        'image_url' => $stand, 'icon_url' => card_icon_url($c),
        // S級キャラは、カードがめくれる前に立ち絵でカットイン演出
        'cutin_url' => (int)$c['rarity'] === CARD_RARITY_S && !str_ends_with($stand, CARD_NO_IMAGE) ? $stand : null,
    ];
}

function banner_engine_item(array $e): array
{
    return [
        'kind' => 'engine', 'id' => (int)$e['id'], 'tier' => (int)$e['rarity'], 'name' => $e['name'],
        'image_url' => engine_image_url($e['image']), 'icon_url' => engine_image_url($e['image']), 'cutin_url' => null,
    ];
}

/**
 * 天井の状態（ガチャの種類ごと）
 */
function fetch_user_pity(PDO $pdo, int $user_id): array
{
    $pity = [];
    foreach (array_keys(BANNER_RATES) as $type) {
        $pity[$type] = ['s_count' => 0, 'a_count' => 0, 'guaranteed' => false];
    }
    $stmt = $pdo->prepare("SELECT pity_type, s_count, a_count, guaranteed FROM user_gacha_pity WHERE user_id = ?");
    $stmt->execute([$user_id]);
    foreach ($stmt->fetchAll() as $row) {
        $pity[$row['pity_type']] = ['s_count' => (int)$row['s_count'], 'a_count' => (int)$row['a_count'], 'guaranteed' => (bool)$row['guaranteed']];
    }
    return $pity;
}

/**
 * ピックアップガチャを $count 回引く。ガチャ石を消費し、キャラ・音動機を付与して、結果を返す
 */
function execute_banner_gacha(PDO $pdo, int $user_id, int $banner_id, int $count): array
{
    $banner = fetch_banners($pdo)[$banner_id] ?? null;
    if (!$banner) {
        throw new GachaException('ガチャが見つかりません。');
    }
    if (!in_array($count, [1, GACHA_DRAW_COUNT], true)) {
        throw new GachaException('引く回数が正しくありません。');
    }
    $type = $banner['type'];
    $pools = fetch_banner_pools($pdo, $banner);
    $now = date('Y-m-d H:i:s');

    $pdo->beginTransaction();
    try {
        lock_user($pdo, $user_id);
        consume_item($pdo, $user_id, ITEM_STONE, gacha_cost($count));

        $stmt = $pdo->prepare("INSERT IGNORE INTO user_gacha_pity (user_id, pity_type) VALUES (?, ?)");
        $stmt->execute([$user_id, $type]);
        $stmt = $pdo->prepare("SELECT s_count, a_count, guaranteed FROM user_gacha_pity WHERE user_id = ? AND pity_type = ? FOR UPDATE");
        $stmt->execute([$user_id, $type]);
        $row = $stmt->fetch();
        $pity = ['s_count' => (int)$row['s_count'], 'a_count' => (int)$row['a_count'], 'guaranteed' => (bool)$row['guaranteed']];

        $history = $pdo->prepare("
            INSERT INTO banner_histories (user_id, banner_id, kind, item_id, tier, is_pickup, pull_no, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $results = [];
        for ($i = 0; $i < $count; $i++) {
            $roll = banner_roll($type, $pity, $pools);
            $item = $roll['item'];
            $grant = $item['kind'] === 'card'
                ? grant_card($pdo, $user_id, $item['id'], $item['tier'])
                : grant_engine($pdo, $user_id, $item['id'], $item['tier']);
            $history->execute([$user_id, $banner_id, $item['kind'], $item['id'], $item['tier'], (int)$roll['is_pickup'], $roll['pull_no'], $now]);

            $results[] = [
                'card_id'    => $item['kind'] === 'card' ? $item['id'] : null,
                'name'       => $item['name'],
                'rarity'     => $item['tier'],
                'image'      => null,
                'image_url'  => $item['image_url'],
                'cutin_url'  => $item['cutin_url'],
                'kind_label' => $item['kind'] === 'card' ? 'キャラ' : '音動機',
                'is_pickup'  => $roll['is_pickup'],
                'pull_no'    => $roll['pull_no'],
                'grant'      => $grant,
            ];
        }

        $stmt = $pdo->prepare("UPDATE user_gacha_pity SET s_count = ?, a_count = ?, guaranteed = ? WHERE user_id = ? AND pity_type = ?");
        $stmt->execute([$pity['s_count'], $pity['a_count'], (int)$pity['guaranteed'], $user_id, $type]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    gacha_log('ピックアップガチャ実行', [
        'user_id' => $user_id, 'banner_id' => $banner_id, 'count' => $count,
        'items' => array_map(fn ($r) => "{$r['kind_label']}:{$r['name']}", $results), 'pity' => $pity,
    ]);
    return ['banner' => $banner, 'results' => $results, 'pity' => $pity];
}
