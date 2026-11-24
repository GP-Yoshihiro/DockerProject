<?php
// 音動機（キャラの装備）: 所持・育成（レベル・進化・凸）・装備
// 効果の計算（ステータスへの反映）は card_growth_lib.php の engine_bonus()
require_once __DIR__ . '/card_growth_lib.php';

/** 所持している音動機を取る SELECT（マスタ・装備中のキャラも結合） */
const USER_ENGINE_SELECT = "
    SELECT ue.id AS user_engine_id, ue.user_id, ue.w_engine_id, ue.level, ue.exp, ue.evolution, ue.limit_break,
           ue.equipped_user_card_id, ue.created_at,
           we.name, we.rarity, we.image, we.base_atk, we.sub_stat, we.sub_value, we.effect_type, we.effect_attribute,
           we.effect_value, we.signature_card_id, we.signature_value, we.pool,
           sc.name AS signature_card_name,
           ec.name AS equipped_card_name, ec.id AS equipped_card_id
    FROM user_w_engines ue
    JOIN w_engines we ON we.id = ue.w_engine_id
    LEFT JOIN cards sc ON sc.id = we.signature_card_id
    LEFT JOIN user_cards euc ON euc.id = ue.equipped_user_card_id
    LEFT JOIN cards ec ON ec.id = euc.card_id
";

function engine_image_url(?string $image): string
{
    return image_path_url($image) ?? CARD_IMAGE_DIR . CARD_NO_IMAGE;
}

/**
 * 表示用に、数値・説明・画像 URL などを付け足す
 */
function enrich_user_engine(array $e): array
{
    $e['values'] = engine_values($e);
    $e['level_cap'] = level_cap((int)$e['evolution'], (int)$e['limit_break']);
    $e['exp_next'] = exp_to_next((int)$e['level']);
    $e['image_url'] = engine_image_url($e['image']);
    $e['effect_text'] = engine_effect_description($e, $e['values']['effect']);
    $e['sub_text'] = STAT_LABELS[$e['sub_stat']] . " +{$e['values']['sub']}%";
    $e['signature_text'] = $e['signature_card_id']
        ? "【{$e['signature_card_name']}専用】装備するとスキルの効果量 +{$e['values']['signature']}%"
        : null;
    return $e;
}

function fetch_user_engine(PDO $pdo, int $user_id, int $user_engine_id, bool $for_update = false): ?array
{
    $stmt = $pdo->prepare(USER_ENGINE_SELECT . " WHERE ue.id = ? AND ue.user_id = ?" . ($for_update ? ' FOR UPDATE' : ''));
    $stmt->execute([$user_engine_id, $user_id]);
    $row = $stmt->fetch();
    return $row ? enrich_user_engine($row) : null;
}

/**
 * 所持している音動機の一覧（レア度の高い順）
 */
function fetch_user_engine_list(PDO $pdo, int $user_id): array
{
    $stmt = $pdo->prepare(USER_ENGINE_SELECT . " WHERE ue.user_id = ? ORDER BY we.rarity DESC, we.id");
    $stmt->execute([$user_id]);
    return array_map('enrich_user_engine', $stmt->fetchAll());
}

/**
 * ガチャで引いた音動機を付与する（呼び出し側でトランザクションを開始しておくこと）
 * キャラと同じく、持っていなければ追加・持っていれば自動で凸・完凸（5凸）ならショップメダル
 */
function grant_engine(PDO $pdo, int $user_id, int $w_engine_id, int $rarity): array
{
    $stmt = $pdo->prepare("SELECT id, limit_break FROM user_w_engines WHERE user_id = ? AND w_engine_id = ? FOR UPDATE");
    $stmt->execute([$user_id, $w_engine_id]);
    $owned = $stmt->fetch();

    if (!$owned) {
        $stmt = $pdo->prepare("INSERT INTO user_w_engines (user_id, w_engine_id) VALUES (?, ?)");
        $stmt->execute([$user_id, $w_engine_id]);
        return ['type' => 'new'];
    }
    $lb = (int)$owned['limit_break'];
    if ($lb < MAX_ENGINE_LIMIT_BREAK) {
        $stmt = $pdo->prepare("UPDATE user_w_engines SET limit_break = limit_break + 1 WHERE id = ?");
        $stmt->execute([$owned['id']]);
        return ['type' => 'limit_break', 'limit_break' => $lb + 1];
    }
    $medals = OVERFLOW_MEDALS[$rarity] ?? 1;
    add_item($pdo, $user_id, ITEM_MEDAL, $medals);
    return ['type' => 'medal', 'medals' => $medals];
}

/**
 * 音動機用の経験値アイテム（チューニングパーツ）を使う
 */
function use_engine_exp_item(PDO $pdo, int $user_id, int $user_engine_id, int $item_id, int $quantity): array
{
    $item = fetch_item_master($pdo)[$item_id] ?? null;
    if (!$item || $item['category'] !== 'engine_exp') {
        throw new GameException('音動機用の強化アイテムではありません。');
    }
    if ($quantity < 1 || $quantity > 99) {
        throw new GameException('使う個数が正しくありません。');
    }

    $pdo->beginTransaction();
    try {
        lock_user($pdo, $user_id);
        $engine = fetch_user_engine($pdo, $user_id, $user_engine_id, true);
        if (!$engine) {
            throw new GameException('音動機が見つかりません。');
        }
        if ((int)$engine['level'] >= $engine['level_cap']) {
            throw new GameException('レベル上限に達しています。進化するとレベル上限が上がります。');
        }
        consume_item($pdo, $user_id, $item_id, $quantity);
        $gained = (int)$item['exp_value'] * $quantity;
        $after = calc_add_exp((int)$engine['level'], (int)$engine['exp'], $engine['level_cap'], $gained);
        $stmt = $pdo->prepare("UPDATE user_w_engines SET level = ?, exp = ? WHERE id = ?");
        $stmt->execute([$after['level'], $after['exp'], $user_engine_id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return [
        'name' => $engine['name'], 'gained' => $gained - $after['wasted'], 'wasted' => $after['wasted'],
        'level_before' => (int)$engine['level'], 'level_after' => $after['level'],
    ];
}

/**
 * 音動機の進化に必要なもの（最終進化済みなら null）。レベル条件・ゴールドはキャラと同じ、素材はチューニングコア
 */
function engine_evolution_requirement(array $engine): ?array
{
    $evolution = (int)$engine['evolution'];
    if ($evolution >= MAX_EVOLUTION) {
        return null;
    }
    return [
        'next'       => $evolution + 1,
        'need_level' => LEVEL_CAPS[$evolution],
        'new_cap'    => level_cap($evolution + 1, (int)$engine['limit_break']),
        'core'       => ENGINE_EVOLUTION_CORES[$evolution + 1],
        'gold'       => EVOLUTION_COSTS[$evolution + 1]['gold'],
        'ready'      => (int)$engine['level'] >= LEVEL_CAPS[$evolution],
    ];
}

function evolve_engine(PDO $pdo, int $user_id, int $user_engine_id): array
{
    $pdo->beginTransaction();
    try {
        lock_user($pdo, $user_id);
        $engine = fetch_user_engine($pdo, $user_id, $user_engine_id, true);
        if (!$engine) {
            throw new GameException('音動機が見つかりません。');
        }
        $req = engine_evolution_requirement($engine);
        if ($req === null) {
            throw new GameException('これ以上進化できません。');
        }
        if (!$req['ready']) {
            throw new GameException("レベル{$req['need_level']}になると進化できます。");
        }
        consume_item($pdo, $user_id, ITEM_ENGINE_CORE, $req['core']);
        consume_item($pdo, $user_id, ITEM_GOLD, $req['gold']);
        $stmt = $pdo->prepare("UPDATE user_w_engines SET evolution = evolution + 1 WHERE id = ?");
        $stmt->execute([$user_engine_id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['name' => $engine['name'], 'level_cap' => $req['new_cap']];
}

/**
 * 音動機の凸効果の一覧 [凸数 => 説明]
 */
function engine_limit_break_effects(): array
{
    $rate = (int)(ENGINE_LB_EFFECT_RATE * 100);
    return [
        1 => "効果量（専用効果も） +{$rate}%",
        2 => "効果量 +{$rate}%（合計 +" . ($rate * 2) . "%）",
        3 => "効果量 +{$rate}% ／ レベル上限 +5（最終進化後: 50 → 55）",
        4 => "効果量 +{$rate}%",
        5 => "【完凸】効果量 +{$rate}%（合計 +" . ($rate * 5) . "%）／ レベル上限 +5（55 → 60）",
    ];
}

/**
 * 音動機を装備する（$user_card_id = null なら外す）
 * 1キャラに1つまで。すでに別の音動機を装備しているキャラに付けると、前の音動機は外れる
 * 別のキャラが装備している音動機を付けると、そのキャラからは外れる
 */
function equip_engine(PDO $pdo, int $user_id, int $user_engine_id, ?int $user_card_id): void
{
    $pdo->beginTransaction();
    try {
        lock_user($pdo, $user_id);
        if (!fetch_user_engine($pdo, $user_id, $user_engine_id, true)) {
            throw new GameException('音動機が見つかりません。');
        }
        if ($user_card_id !== null) {
            if (!fetch_user_card($pdo, $user_id, $user_card_id, true)) {
                throw new GameException('キャラが見つかりません。');
            }
            $stmt = $pdo->prepare("UPDATE user_w_engines SET equipped_user_card_id = NULL WHERE user_id = ? AND equipped_user_card_id = ?");
            $stmt->execute([$user_id, $user_card_id]);
        }
        $stmt = $pdo->prepare("UPDATE user_w_engines SET equipped_user_card_id = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$user_card_id, $user_engine_id, $user_id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
