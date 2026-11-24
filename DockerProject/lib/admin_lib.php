<?php
// 登録画面（/game/admin.php）: htdocs/images/zzz/ の画像を使って、キャラ・音動機を登録する
require_once __DIR__ . '/banner_gacha_lib.php';

/** 画像フォルダ（htdocs/images/ から） */
const IMAGE_DIR_ICON   = 'zzz/icon';
const IMAGE_DIR_STAND  = 'zzz/standing pose';
const IMAGE_DIR_ENGINE = 'zzz/sound engine';

const POOL_LABELS = ['standard' => '常設', 'limited' => '限定（ピックアップのみ）'];

/** スキルの効果の表示名と、レア度ごとの標準値 [effect => [S級, A級, CT, 持続]] */
const SKILL_TEMPLATES = [
    'damage_single' => ['label' => '敵単体にダメージ',       'S' => 280, 'A' => 240, 'cooldown' => 3, 'duration' => 0],
    'damage_all'    => ['label' => '敵全体にダメージ',       'S' => 160, 'A' => 130, 'cooldown' => 4, 'duration' => 0],
    'heal_single'   => ['label' => '味方単体を回復',         'S' => 280, 'A' => 250, 'cooldown' => 3, 'duration' => 0],
    'heal_all'      => ['label' => '味方全体を回復',         'S' => 150, 'A' => 120, 'cooldown' => 4, 'duration' => 0],
    'taunt'         => ['label' => '挑発＋ダメージ軽減',     'S' => 40,  'A' => 30,  'cooldown' => 4, 'duration' => 2],
    'buff_atk'      => ['label' => '味方全体の攻撃アップ',   'S' => 30,  'A' => 25,  'cooldown' => 4, 'duration' => 3],
    'debuff_def'    => ['label' => '敵全体の防御ダウン',     'S' => 30,  'A' => 25,  'cooldown' => 4, 'duration' => 2],
];

/** タイプごとのおすすめスキル */
const ROLE_DEFAULT_SKILL = ['attacker' => 'damage_single', 'tank' => 'taunt', 'healer' => 'heal_all', 'support' => 'buff_atk'];

/** レベル1の基礎値（レア度ごと）と、タイプによる補正 */
const BASE_STATS = [CARD_RARITY_S => ['hp' => 840, 'atk' => 170, 'def' => 76], CARD_RARITY_A => ['hp' => 640, 'atk' => 125, 'def' => 58]];
const ROLE_STAT_RATES = [
    'attacker' => ['hp' => 0.9, 'atk' => 1.2,  'def' => 0.9, 'spd' => 110],
    'tank'     => ['hp' => 1.3, 'atk' => 0.8,  'def' => 1.4, 'spd' => 85],
    'healer'   => ['hp' => 1.1, 'atk' => 0.85, 'def' => 1.0, 'spd' => 95],
    'support'  => ['hp' => 1.0, 'atk' => 0.95, 'def' => 1.1, 'spd' => 105],
];

/** 専用音動機の標準値（タイプごとのサブステータス・効果） */
const SIGNATURE_ENGINE_TEMPLATES = [
    'attacker' => ['sub_stat' => 'atk', 'effect_type' => 'skill_dmg'],
    'tank'     => ['sub_stat' => 'def', 'effect_type' => 'def_pct'],
    'healer'   => ['sub_stat' => 'hp',  'effect_type' => 'heal'],
    'support'  => ['sub_stat' => 'atk', 'effect_type' => 'atk_pct'],
];
/** 音動機のレア度ごとの標準値 [base_atk, sub_value, effect_value, signature_value] */
const ENGINE_RARITY_DEFAULTS = [
    ENGINE_RARITY_S => ['base_atk' => 48, 'sub_value' => 12, 'effect_value' => 15, 'signature_value' => 30],
    ENGINE_RARITY_A => ['base_atk' => 40, 'sub_value' => 10, 'effect_value' => 12, 'signature_value' => 20],
    ENGINE_RARITY_B => ['base_atk' => 32, 'sub_value' => 8,  'effect_value' => 10, 'signature_value' => 0],
];

// ---------------------------------------------------------------
// 画像ファイル
// ---------------------------------------------------------------

function images_root(): string
{
    $docroot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    return ($docroot !== '' ? $docroot : dirname(__DIR__) . '/htdocs') . '/images/';
}

/**
 * フォルダの中の画像ファイル（htdocs/images/ からのパス）の一覧
 */
function list_image_files(string $dir): array
{
    $files = [];
    foreach (glob(images_root() . $dir . '/*') ?: [] as $path) {
        if (is_file($path) && preg_match('/\.(png|jpe?g|gif|webp|svg)$/i', $path)) {
            $files[] = $dir . '/' . basename($path);
        }
    }
    sort($files, SORT_NATURAL | SORT_FLAG_CASE);
    return $files;
}

/**
 * 画像の「名前」（ファイル名から拡張子を除き、小文字にしたもの）。アイコン・立ち絵・音動機の対応付けに使う
 */
function image_key(?string $path): string
{
    if ($path === null || $path === '') {
        return '';
    }
    return mb_strtolower(pathinfo($path, PATHINFO_FILENAME));
}

/**
 * 同じ名前のファイルを探す。完全に同じものが無ければ、1〜2文字違い（打ち間違い）のものを候補にする
 */
function find_matching_image(string $key, array $files): ?string
{
    if ($key === '') {
        return null;
    }
    $best = null;
    $best_distance = 3;
    foreach ($files as $f) {
        $k = image_key($f);
        if ($k === $key) {
            return $f;
        }
        $d = levenshtein($k, $key);
        if ($d < $best_distance) {
            $best = $f;
            $best_distance = $d;
        }
    }
    return $best;
}

// ---------------------------------------------------------------
// 登録
// ---------------------------------------------------------------

function default_card_stats(int $rarity, string $role): array
{
    $base = BASE_STATS[$rarity] ?? BASE_STATS[CARD_RARITY_A];
    $r = ROLE_STAT_RATES[$role];
    return [
        'hp'  => (int)round($base['hp'] * $r['hp']),
        'atk' => (int)round($base['atk'] * $r['atk']),
        'def' => (int)round($base['def'] * $r['def']),
        'spd' => $r['spd'],
    ];
}

function default_skill(string $effect, int $rarity): array
{
    $t = SKILL_TEMPLATES[$effect];
    return [
        'power'    => $rarity === CARD_RARITY_S ? $t['S'] : $t['A'],
        'cooldown' => $t['cooldown'],
        'duration' => $t['duration'],
    ];
}

/**
 * 入力値の確認（不正なら GameException）
 */
function admin_validate_card(array $in, array $icon_files, array $stand_files): array
{
    $name = trim((string)($in['name'] ?? ''));
    $skill_name = trim((string)($in['skill_name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 50) {
        throw new GameException('名前を50文字以内で入力してください。');
    }
    if ($skill_name === '' || mb_strlen($skill_name) > 50) {
        throw new GameException('スキル名を50文字以内で入力してください。');
    }
    $rarity = (int)($in['rarity'] ?? 0);
    if (!in_array($rarity, [CARD_RARITY_S, CARD_RARITY_A], true)) {
        throw new GameException('レア度は S か A です。');
    }
    foreach (['attribute' => ATTRIBUTES, 'role' => ROLES, 'pool' => POOL_LABELS, 'skill_effect' => SKILL_TEMPLATES] as $key => $allowed) {
        if (!isset($allowed[$in[$key] ?? ''])) {
            throw new GameException("{$key} が正しくありません。");
        }
    }
    $icon = ($in['icon_image'] ?? '') ?: null;
    $stand = ($in['stand_image'] ?? '') ?: null;
    if ($icon !== null && !in_array($icon, $icon_files, true)) {
        throw new GameException('アイコンの画像が見つかりません。');
    }
    if ($stand !== null && !in_array($stand, $stand_files, true)) {
        throw new GameException('立ち絵の画像が見つかりません。');
    }
    return [
        'name' => $name, 'rarity' => $rarity, 'attribute' => $in['attribute'], 'role' => $in['role'], 'pool' => $in['pool'],
        'is_active' => !empty($in['is_active']), 'icon_image' => $icon, 'stand_image' => $stand,
        'skill_name' => $skill_name, 'skill_effect' => $in['skill_effect'],
    ];
}

/**
 * キャラを新しく登録する（スキルも作る）。呼び出し側でトランザクションを開始しておくこと。戻り値はキャラの ID
 * ステータス・スキルの数値は、レア度とタイプから標準値で決める
 */
function admin_save_card_in_transaction(PDO $pdo, array $v): int
{
    $stats = default_card_stats($v['rarity'], $v['role']);
    $skill = default_skill($v['skill_effect'], $v['rarity']);

    // 同じアイコンのキャラを二重に登録しない（一括登録を2回送信したときなど）
    if ($v['icon_image'] !== null) {
        $stmt = $pdo->prepare("SELECT name FROM cards WHERE icon_image = ? FOR UPDATE");
        $stmt->execute([$v['icon_image']]);
        if ($name = $stmt->fetchColumn()) {
            throw new GameException("このアイコンは「{$name}」で登録済みです。");
        }
    }
    $stmt = $pdo->prepare("INSERT INTO skills (name, effect, power, cooldown, duration) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$v['skill_name'], $v['skill_effect'], $skill['power'], $skill['cooldown'], $skill['duration']]);
    $skill_id = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare("
        INSERT INTO cards (name, hp, atk, def, spd, rarity, attribute, role, skill_id, icon_image, stand_image, pool, is_active)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$v['name'], $stats['hp'], $stats['atk'], $stats['def'], $stats['spd'], $v['rarity'], $v['attribute'], $v['role'],
        $skill_id, $v['icon_image'], $v['stand_image'], $v['pool'], (int)$v['is_active']]);
    return (int)$pdo->lastInsertId();
}

/**
 * キャラを登録する（$card_id があれば更新）。戻り値はキャラの ID
 */
function admin_save_card(PDO $pdo, array $v, ?int $card_id): int
{
    $stats = default_card_stats($v['rarity'], $v['role']);
    $skill = default_skill($v['skill_effect'], $v['rarity']);

    $pdo->beginTransaction();
    try {
        if ($card_id === null) {
            $card_id = admin_save_card_in_transaction($pdo, $v);
        } else {
            $stmt = $pdo->prepare("SELECT skill_id, rarity, role FROM cards WHERE id = ? FOR UPDATE");
            $stmt->execute([$card_id]);
            $old = $stmt->fetch();
            if (!$old) {
                throw new GameException('キャラが見つかりません。');
            }
            $stmt = $pdo->prepare("UPDATE skills SET name = ?, effect = ?, power = ?, cooldown = ?, duration = ? WHERE id = ?");
            $stmt->execute([$v['skill_name'], $v['skill_effect'], $skill['power'], $skill['cooldown'], $skill['duration'], $old['skill_id']]);
            $stmt = $pdo->prepare("
                UPDATE cards SET name = ?, rarity = ?, attribute = ?, role = ?, icon_image = ?, stand_image = ?, pool = ?, is_active = ? WHERE id = ?
            ");
            $stmt->execute([$v['name'], $v['rarity'], $v['attribute'], $v['role'], $v['icon_image'], $v['stand_image'], $v['pool'], (int)$v['is_active'], $card_id]);
            // レア度・タイプを変えたときは、ステータスも標準値に合わせ直す
            if ((int)$old['rarity'] !== $v['rarity'] || $old['role'] !== $v['role']) {
                $stmt = $pdo->prepare("UPDATE cards SET hp = ?, atk = ?, def = ?, spd = ? WHERE id = ?");
                $stmt->execute([$stats['hp'], $stats['atk'], $stats['def'], $stats['spd'], $card_id]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $card_id;
}

function admin_validate_engine(PDO $pdo, array $in, array $engine_files): array
{
    $name = trim((string)($in['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 50) {
        throw new GameException('名前を50文字以内で入力してください。');
    }
    $rarity = (int)($in['rarity'] ?? 0);
    if (!isset(ENGINE_RARITY_DEFAULTS[$rarity])) {
        throw new GameException('レア度は S・A・B のどれかです。');
    }
    if (!isset(STAT_LABELS[$in['sub_stat'] ?? '']) || ($in['sub_stat'] ?? '') === 'spd') {
        throw new GameException('サブステータスが正しくありません。');
    }
    if (!isset(ENGINE_EFFECT_LABELS[$in['effect_type'] ?? ''])) {
        throw new GameException('効果が正しくありません。');
    }
    $attribute = ($in['effect_attribute'] ?? '') ?: null;
    if ($in['effect_type'] === 'attr_dmg' && !isset(ATTRIBUTES[$attribute ?? ''])) {
        throw new GameException('属性汎用の効果には属性を選んでください。');
    }
    if ($in['effect_type'] !== 'attr_dmg') {
        $attribute = null;
    }
    $image = ($in['image'] ?? '') ?: null;
    if ($image !== null && !in_array($image, $engine_files, true)) {
        throw new GameException('音動機の画像が見つかりません。');
    }
    $signature = (int)($in['signature_card_id'] ?? 0) ?: null;
    if ($signature !== null) {
        $stmt = $pdo->prepare("SELECT id FROM cards WHERE id = ?");
        $stmt->execute([$signature]);
        if (!$stmt->fetch()) {
            throw new GameException('専用にするキャラが見つかりません。');
        }
    }
    if (!isset(POOL_LABELS[$in['pool'] ?? ''])) {
        throw new GameException('排出の種類が正しくありません。');
    }
    $d = ENGINE_RARITY_DEFAULTS[$rarity];
    $effect_value = (int)($in['effect_value'] ?? 0) ?: $d['effect_value'];
    return [
        'name' => $name, 'rarity' => $rarity, 'image' => $image, 'base_atk' => $d['base_atk'],
        'sub_stat' => $in['sub_stat'], 'sub_value' => $d['sub_value'],
        'effect_type' => $in['effect_type'], 'effect_attribute' => $attribute, 'effect_value' => max(1, min(100, $effect_value)),
        'signature_card_id' => $signature, 'signature_value' => $signature ? max($d['signature_value'], 10) : 0,
        'pool' => $in['pool'], 'is_active' => !empty($in['is_active']),
    ];
}

function admin_save_engine(PDO $pdo, array $v, ?int $engine_id): int
{
    $cols = ['name', 'rarity', 'image', 'base_atk', 'sub_stat', 'sub_value', 'effect_type', 'effect_attribute', 'effect_value',
             'signature_card_id', 'signature_value', 'pool', 'is_active'];
    $values = array_map(fn ($c) => is_bool($v[$c]) ? (int)$v[$c] : $v[$c], $cols);
    if ($engine_id === null) {
        $stmt = $pdo->prepare("INSERT INTO w_engines (" . implode(', ', $cols) . ") VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ")");
        $stmt->execute($values);
        return (int)$pdo->lastInsertId();
    }
    $stmt = $pdo->prepare("UPDATE w_engines SET " . implode(', ', array_map(fn ($c) => "$c = ?", $cols)) . " WHERE id = ?");
    $stmt->execute([...$values, $engine_id]);
    return $engine_id;
}

/**
 * キャラの専用音動機（モチーフ音動機）を、標準値で登録する
 */
function admin_create_signature_engine(PDO $pdo, int $card_id, string $name, ?string $image): int
{
    $stmt = $pdo->prepare("SELECT rarity, role, pool FROM cards WHERE id = ?");
    $stmt->execute([$card_id]);
    $card = $stmt->fetch();
    $rarity = (int)$card['rarity'] === CARD_RARITY_S ? ENGINE_RARITY_S : ENGINE_RARITY_A;
    $t = SIGNATURE_ENGINE_TEMPLATES[$card['role']];
    $d = ENGINE_RARITY_DEFAULTS[$rarity];
    return admin_save_engine($pdo, [
        'name' => $name, 'rarity' => $rarity, 'image' => $image, 'base_atk' => $d['base_atk'],
        'sub_stat' => $t['sub_stat'], 'sub_value' => $d['sub_value'], 'effect_type' => $t['effect_type'],
        'effect_attribute' => null, 'effect_value' => $d['effect_value'],
        'signature_card_id' => $card_id, 'signature_value' => $d['signature_value'],
        'pool' => $card['pool'], 'is_active' => true,
    ], null);
}

function admin_set_banner_pickups(PDO $pdo, ?int $card_id, ?int $engine_id): void
{
    if ($card_id !== null) {
        $stmt = $pdo->prepare("SELECT rarity FROM cards WHERE id = ?");
        $stmt->execute([$card_id]);
        if ((int)$stmt->fetchColumn() !== CARD_RARITY_S) {
            throw new GameException('ピックアップにできるのは S級キャラです。');
        }
    }
    if ($engine_id !== null) {
        $stmt = $pdo->prepare("SELECT rarity FROM w_engines WHERE id = ?");
        $stmt->execute([$engine_id]);
        if ((int)$stmt->fetchColumn() !== ENGINE_RARITY_S) {
            throw new GameException('ピックアップにできるのは S級音動機です。');
        }
    }
    $pdo->prepare("UPDATE banners SET pickup_card_id = ? WHERE type = 'char'")->execute([$card_id]);
    $pdo->prepare("UPDATE banners SET pickup_engine_id = ? WHERE type = 'engine'")->execute([$engine_id]);
}
