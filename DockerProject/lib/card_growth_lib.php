<?php
// カードの育成（レベル・経験値・進化・凸）とステータス・スキルの計算
require_once __DIR__ . '/game_lib.php';

/** 進化段階ごとのレベル上限（進化0 → 20、進化1 → 30、進化2 → 40、進化3 → 50） */
const LEVEL_CAPS = [20, 30, 40, 50];
const MAX_EVOLUTION = 3;
const MAX_LIMIT_BREAK = 6;

/** 最終進化後、この凸数に達するとレベル上限 +5（50 → 55 → 60） */
const LIMIT_BREAK_LEVEL_BONUS = [3 => 5, 5 => 5];

/** 進化に必要なもの（キー = 進化後の段階）。結晶はそのカードの属性のもの */
const EVOLUTION_COSTS = [
    1 => ['crystal' => 3,  'gold' => 1000],
    2 => ['crystal' => 6,  'gold' => 3000],
    3 => ['crystal' => 10, 'gold' => 6000],
];

/** 完凸後にガチャで重複したとき、レア度に応じてもらえるショップメダル */
const OVERFLOW_MEDALS = [5 => 50, 4 => 20, 3 => 8, 2 => 3, 1 => 1];

/** レベル1つごとのステータス上昇率（基礎値に対して） */
const LEVEL_GROWTH = 0.03;

/** タイプごとに、凸で上がるステータス */
const LIMIT_BREAK_STATS = [
    'attacker' => ['atk'],
    'tank'     => ['hp', 'def'],
    'healer'   => ['hp', 'atk'],
    'support'  => ['atk', 'def'],
];

const STAT_LABELS = ['hp' => 'HP', 'atk' => '攻撃', 'def' => '防御', 'spd' => '素早さ'];

/** 所持カード1枚分の情報を取る SELECT（カードマスタ・スキルも結合） */
const USER_CARD_SELECT = "
    SELECT uc.id AS user_card_id, uc.user_id, uc.card_id, uc.level, uc.exp, uc.evolution, uc.limit_break, uc.created_at,
           c.name, c.rarity, c.attribute, c.role, c.hp, c.atk, c.def, c.spd, c.image, c.cutin_image,
           s.id AS skill_id, s.name AS skill_name, s.effect AS skill_effect,
           s.power AS skill_power, s.cooldown AS skill_cooldown, s.duration AS skill_duration
    FROM user_cards uc
    JOIN cards c ON c.id = uc.card_id
    JOIN skills s ON s.id = c.skill_id
";

// ---------------------------------------------------------------
// 計算（DB を使わない）
// ---------------------------------------------------------------

/**
 * レベル上限
 */
function level_cap(int $evolution, int $limit_break): int
{
    $cap = LEVEL_CAPS[min($evolution, MAX_EVOLUTION)];
    if ($evolution >= MAX_EVOLUTION) {
        foreach (LIMIT_BREAK_LEVEL_BONUS as $lb => $bonus) {
            if ($limit_break >= $lb) {
                $cap += $bonus;
            }
        }
    }
    return $cap;
}

/**
 * 次のレベルまでに必要な経験値
 */
function exp_to_next(int $level): int
{
    return 10 * $level + 20;
}

/**
 * 経験値を加える計算。上限レベルに達したら、それ以上はたまらない
 * 戻り値: ['level' => .., 'exp' => .., 'wasted' => 使われなかった経験値]
 */
function calc_add_exp(int $level, int $exp, int $cap, int $gained): array
{
    while ($gained > 0 && $level < $cap) {
        $need = exp_to_next($level) - $exp;
        if ($gained >= $need) {
            $gained -= $need;
            $level++;
            $exp = 0;
        } else {
            $exp += $gained;
            $gained = 0;
        }
    }
    if ($level >= $cap) {
        $exp = 0;
    }
    return ['level' => $level, 'exp' => $exp, 'wasted' => $gained];
}

/**
 * 凸によるステータス上昇率(%)（1凸 +5%、6凸でさらに +10%）
 */
function limit_break_stat_bonus(int $limit_break): int
{
    return ($limit_break >= 1 ? 5 : 0) + ($limit_break >= 6 ? 10 : 0);
}

/**
 * 今のステータス（レベル・凸を反映）
 * $card には hp / atk / def / spd（基礎値）・level・limit_break・role が必要
 */
function card_stats(array $card): array
{
    $growth = 1 + LEVEL_GROWTH * ((int)$card['level'] - 1);
    $bonus = limit_break_stat_bonus((int)$card['limit_break']);
    $boosted = LIMIT_BREAK_STATS[$card['role']] ?? [];

    $stats = [];
    foreach (['hp', 'atk', 'def'] as $key) {
        $value = (int)$card[$key] * $growth;
        if (in_array($key, $boosted, true)) {
            $value *= 1 + $bonus / 100;
        }
        $stats[$key] = (int)round($value);
    }
    $stats['spd'] = (int)$card['spd'];
    return $stats;
}

/**
 * 戦力（チームの強さの目安）
 */
function card_power(array $stats): int
{
    return (int)round($stats['hp'] / 10 + $stats['atk'] + $stats['def']);
}

/**
 * 今のスキル（凸を反映）
 * $card には skill_* の列と role・limit_break が必要
 */
function card_skill(array $card): array
{
    $lb = (int)$card['limit_break'];
    $role = $card['role'];
    $power = (int)$card['skill_power'];
    $cooldown = (int)$card['skill_cooldown'];
    $duration = (int)$card['skill_duration'];

    // 2凸: スキル強化 / 6凸: さらに強化
    if (in_array($role, ['attacker', 'healer'], true)) {
        $rate = ($lb >= 2 ? 20 : 0) + ($lb >= 6 ? 30 : 0);
        $power = (int)round($power * (1 + $rate / 100));
    } else {
        // タンクは軽減率、サポートは効果量を +10（%ポイント）
        if ($lb >= 2) {
            $power += 10;
        }
        if ($lb >= 6) {
            $duration += 1;
        }
    }
    // 4凸: クールタイム -1
    if ($lb >= 4) {
        $cooldown = max(1, $cooldown - 1);
    }

    return [
        'id'       => (int)$card['skill_id'],
        'name'     => $card['skill_name'],
        'effect'   => $card['skill_effect'],
        'power'    => $power,
        'cooldown' => $cooldown,
        'duration' => $duration,
    ];
}

/**
 * スキルの説明文
 */
function skill_description(array $skill): string
{
    $p = $skill['power'];
    $d = $skill['duration'];
    $text = match ($skill['effect']) {
        'damage_single' => "敵単体に攻撃力の{$p}%のダメージ",
        'damage_all'    => "敵全体に攻撃力の{$p}%のダメージ",
        'heal_single'   => "HPの割合が一番低い味方を、攻撃力の{$p}%回復",
        'heal_all'      => "味方全体を、攻撃力の{$p}%回復",
        'taunt'         => "{$d}ターンの間、敵の攻撃を自分に集め、受けるダメージを{$p}%軽減",
        'buff_atk'      => "{$d}ターンの間、味方全体の攻撃力を{$p}%アップ",
        'debuff_def'    => "{$d}ターンの間、敵全体の防御力を{$p}%ダウン",
        default         => '',
    };
    return $text . "（{$skill['cooldown']}ターンに1回）";
}

/**
 * タイプごとの凸効果の一覧 [凸数 => 説明]
 */
function limit_break_effects(string $role): array
{
    $stats = implode('・', array_map(fn ($k) => STAT_LABELS[$k], LIMIT_BREAK_STATS[$role] ?? []));
    [$skill2, $skill6] = match ($role) {
        'attacker' => ['スキルのダメージ +20%', 'スキルのダメージ さらに +30%'],
        'tank'     => ['挑発中のダメージ軽減率 +10%', '挑発の持続 +1ターン'],
        'healer'   => ['スキルの回復量 +20%', 'スキルの回復量 さらに +30%'],
        'support'  => ['強化・弱体化の効果量 +10%', '強化・弱体化の持続 +1ターン'],
        default    => ['', ''],
    };
    return [
        1 => "{$stats} +5%",
        2 => $skill2,
        3 => 'レベル上限 +5（最終進化後: 50 → 55）',
        4 => 'スキルのクールタイム -1ターン',
        5 => 'レベル上限 +5（最終進化後: 55 → 60）',
        6 => "【完凸】{$stats} さらに +10% ／ {$skill6}",
    ];
}

/**
 * 凸の表示（◆◆◇◇◇◇）
 */
function limit_break_marks(int $limit_break): string
{
    return str_repeat('◆', $limit_break) . str_repeat('◇', MAX_LIMIT_BREAK - $limit_break);
}

/**
 * 表示用に、ステータス・スキル・レベル上限などを計算して付け足す
 */
function enrich_user_card(array $card): array
{
    $card['stats'] = card_stats($card);
    $card['power'] = card_power($card['stats']);
    $card['skill'] = card_skill($card);
    $card['level_cap'] = level_cap((int)$card['evolution'], (int)$card['limit_break']);
    $card['exp_next'] = exp_to_next((int)$card['level']);
    $card['image_url'] = card_image_url($card['image']);
    return $card;
}

// ---------------------------------------------------------------
// DB
// ---------------------------------------------------------------

/**
 * 所持カードを1枚取得する（他人のカードは null）
 */
function fetch_user_card(PDO $pdo, int $user_id, int $user_card_id, bool $for_update = false): ?array
{
    $stmt = $pdo->prepare(USER_CARD_SELECT . " WHERE uc.id = ? AND uc.user_id = ?" . ($for_update ? ' FOR UPDATE' : ''));
    $stmt->execute([$user_card_id, $user_id]);
    $row = $stmt->fetch();
    return $row ? enrich_user_card($row) : null;
}

/**
 * 所持カードの一覧（レア度の高い順）
 */
function fetch_user_card_list(PDO $pdo, int $user_id): array
{
    $stmt = $pdo->prepare(USER_CARD_SELECT . " WHERE uc.user_id = ? ORDER BY c.rarity DESC, c.id");
    $stmt->execute([$user_id]);
    return array_map('enrich_user_card', $stmt->fetchAll());
}

/**
 * ガチャで引いたカードを付与する（呼び出し側でトランザクションを開始しておくこと）
 *   - 持っていない → 新しく追加（type = new）
 *   - 持っている   → 凸 +1（type = limit_break）
 *   - 完凸済み     → ショップメダルに変換（type = medal）
 */
function grant_card(PDO $pdo, int $user_id, int $card_id, int $rarity): array
{
    $stmt = $pdo->prepare("SELECT id, limit_break FROM user_cards WHERE user_id = ? AND card_id = ? FOR UPDATE");
    $stmt->execute([$user_id, $card_id]);
    $owned = $stmt->fetch();

    if (!$owned) {
        $stmt = $pdo->prepare("INSERT INTO user_cards (user_id, card_id) VALUES (?, ?)");
        $stmt->execute([$user_id, $card_id]);
        return ['type' => 'new'];
    }

    $lb = (int)$owned['limit_break'];
    if ($lb < MAX_LIMIT_BREAK) {
        $stmt = $pdo->prepare("UPDATE user_cards SET limit_break = limit_break + 1 WHERE id = ?");
        $stmt->execute([$owned['id']]);
        return ['type' => 'limit_break', 'limit_break' => $lb + 1];
    }

    $medals = OVERFLOW_MEDALS[$rarity] ?? 1;
    add_item($pdo, $user_id, ITEM_MEDAL, $medals);
    return ['type' => 'medal', 'medals' => $medals];
}

/**
 * カードに経験値を加える（呼び出し側でトランザクションを開始しておくこと）
 * 戻り値: 表示用の結果（レベルが上がる前と後など）
 */
function give_card_exp(PDO $pdo, int $user_id, int $user_card_id, int $exp): array
{
    $card = fetch_user_card($pdo, $user_id, $user_card_id, true);
    if (!$card) {
        throw new GameException('カードが見つかりません。');
    }
    $after = calc_add_exp((int)$card['level'], (int)$card['exp'], $card['level_cap'], $exp);

    $stmt = $pdo->prepare("UPDATE user_cards SET level = ?, exp = ? WHERE id = ?");
    $stmt->execute([$after['level'], $after['exp'], $user_card_id]);

    return [
        'user_card_id' => $user_card_id,
        'name'         => $card['name'],
        'gained'       => $exp - $after['wasted'],
        'wasted'       => $after['wasted'],
        'level_before' => (int)$card['level'],
        'level_after'  => $after['level'],
        'level_cap'    => $card['level_cap'],
    ];
}

/**
 * 経験値アイテムを使う
 */
function use_exp_item(PDO $pdo, int $user_id, int $user_card_id, int $item_id, int $quantity): array
{
    $item = fetch_item_master($pdo)[$item_id] ?? null;
    if (!$item || $item['category'] !== 'exp') {
        throw new GameException('経験値アイテムではありません。');
    }
    if ($quantity < 1 || $quantity > 99) {
        throw new GameException('使う個数が正しくありません。');
    }

    $pdo->beginTransaction();
    try {
        lock_user($pdo, $user_id);
        $card = fetch_user_card($pdo, $user_id, $user_card_id, true);
        if (!$card) {
            throw new GameException('カードが見つかりません。');
        }
        if ((int)$card['level'] >= $card['level_cap']) {
            throw new GameException('レベル上限に達しています。進化するとレベル上限が上がります。');
        }
        consume_item($pdo, $user_id, $item_id, $quantity);
        $result = give_card_exp($pdo, $user_id, $user_card_id, (int)$item['exp_value'] * $quantity);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * 進化できるか・必要なもの
 * 戻り値: ['next' => 次の段階, 'crystal_item_id', 'crystal', 'gold', 'ready' => レベルが足りているか] / 最終進化済みなら null
 */
function evolution_requirement(array $card): ?array
{
    $evolution = (int)$card['evolution'];
    if ($evolution >= MAX_EVOLUTION) {
        return null;
    }
    $cost = EVOLUTION_COSTS[$evolution + 1];
    return [
        'next'            => $evolution + 1,
        'need_level'      => LEVEL_CAPS[$evolution],
        'new_cap'         => level_cap($evolution + 1, (int)$card['limit_break']),
        'crystal_item_id' => CRYSTAL_ITEM_IDS[$card['attribute']],
        'crystal'         => $cost['crystal'],
        'gold'            => $cost['gold'],
        'ready'           => (int)$card['level'] >= LEVEL_CAPS[$evolution],
    ];
}

/**
 * 進化する（レベル上限の段階を1つ上げる）
 */
function evolve_card(PDO $pdo, int $user_id, int $user_card_id): array
{
    $pdo->beginTransaction();
    try {
        lock_user($pdo, $user_id);
        $card = fetch_user_card($pdo, $user_id, $user_card_id, true);
        if (!$card) {
            throw new GameException('カードが見つかりません。');
        }
        $req = evolution_requirement($card);
        if ($req === null) {
            throw new GameException('これ以上進化できません。');
        }
        if (!$req['ready']) {
            throw new GameException("レベル{$req['need_level']}になると進化できます。");
        }
        consume_item($pdo, $user_id, $req['crystal_item_id'], $req['crystal']);
        consume_item($pdo, $user_id, ITEM_GOLD, $req['gold']);

        $stmt = $pdo->prepare("UPDATE user_cards SET evolution = evolution + 1 WHERE id = ?");
        $stmt->execute([$user_card_id]);
        $pdo->commit();
        return ['name' => $card['name'], 'evolution' => $req['next'], 'level_cap' => $req['new_cap']];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
