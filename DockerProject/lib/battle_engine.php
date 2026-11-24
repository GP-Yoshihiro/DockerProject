<?php
// オートバトルの計算（DB を使わない純粋な計算）
// サーバで最後まで計算し、その経過（イベントの配列）を JSON にして、ブラウザの JavaScript が演出として再生する

const BATTLE_MAX_TURNS = 30;        // このターン数で決着がつかなければ時間切れ
const ATTRIBUTE_WEAK_RATE = 1.5;    // 弱点属性で攻撃したときの倍率
const ATTRIBUTE_RESIST_RATE = 0.5;  // 耐性属性で攻撃したときの倍率
const HEAL_THRESHOLD = 0.7;         // 味方の HP がこの割合を下回ったら回復スキルを使う
const DEFENSE_RATE = 0.6;           // ダメージ = 攻撃 × 倍率 − 防御 × この値
const MIN_DAMAGE_RATE = 0.15;       // 防御が高くても、攻撃 × 倍率 のこの割合は通る

/**
 * バトルに参加するユニット（1人分）を作る
 *
 * $params: name, image, hp, atk, def, spd, attribute, role, level, limit_break, weakness, resistance, skill（null 可）
 *          dmg_bonus（与ダメージ%）, skill_dmg_bonus（スキルのダメージ%）, heal_bonus（回復量%）… 音動機の効果
 */
function make_battle_unit(string $uid, string $side, array $params): array
{
    $skill = $params['skill'] ?? null;
    return [
        'uid'         => $uid,
        'side'        => $side,
        'name'        => $params['name'],
        'image'       => $params['image'],
        'attribute'   => $params['attribute'] ?? null,
        'role'        => $params['role'] ?? null,
        'level'       => $params['level'] ?? null,
        'limit_break' => $params['limit_break'] ?? 0,
        'weakness'    => $params['weakness'] ?? null,
        'resistance'  => $params['resistance'] ?? null,
        'max_hp'      => (int)$params['hp'],
        'hp'          => (int)$params['hp'],
        'atk'         => (int)$params['atk'],
        'def'         => (int)$params['def'],
        'spd'         => (int)$params['spd'],
        'skill'       => $skill,
        'dmg_bonus'       => (float)($params['dmg_bonus'] ?? 0),
        'skill_dmg_bonus' => (float)($params['skill_dmg_bonus'] ?? 0),
        'heal_bonus'      => (float)($params['heal_bonus'] ?? 0),
        'engine'          => $params['engine'] ?? null,
        // スキルが使えるまでのターン数。最初はクールタイムの半分から（序盤からスキルが見られるように）
        'cd'          => $skill ? intdiv((int)$skill['cooldown'], 2) : 0,
        'status'      => [], // ['atk_up' => ['value' => 30, 'turns' => 3], 'def_down' => .., 'taunt' => ..]
    ];
}

/**
 * バトルを最後まで計算する
 *
 * @param array  $allies          味方ユニットの配列
 * @param array  $waves           ウェーブごとの敵ユニットの配列 [[unit, unit], [unit], ...]
 * @param bool   $use_attribute   属性の弱点・耐性を使うか（ステージ: true / 対戦: false）
 * @param string $timeout_result  時間切れのときの結果（ステージ: lose / 対戦: draw）
 * @return array ['result', 'turns', 'units'（最初の状態）, 'events', 'allies_down'（倒れた味方の数）]
 */
function simulate_battle(array $allies, array $waves, bool $use_attribute = true, string $timeout_result = 'lose'): array
{
    $U = [];               // uid => ユニット
    $ally_ids = [];
    $wave_ids = [];
    foreach ($allies as $u) {
        $U[$u['uid']] = $u;
        $ally_ids[] = $u['uid'];
    }
    foreach ($waves as $w => $enemies) {
        foreach ($enemies as $u) {
            $u['wave'] = $w + 1;
            $U[$u['uid']] = $u;
            $wave_ids[$w][] = $u['uid'];
        }
    }
    $units_snapshot = array_values(array_map('battle_unit_public', $U));

    $events = [];
    $turn = 0;
    $wave = 0;
    $enemy_ids = $wave_ids[0] ?? [];
    $events[] = ['t' => 'wave', 'wave' => 1, 'enemies' => $enemy_ids];
    $result = null;

    while ($result === null) {
        if ($turn >= BATTLE_MAX_TURNS) {
            $result = $timeout_result;
            break;
        }
        $turn++;
        $events[] = ['t' => 'turn', 'turn' => $turn];

        // 素早さの順に行動（同じなら味方が先）
        $order = array_merge($ally_ids, $enemy_ids);
        $rank = array_flip($order);
        usort($order, fn ($a, $b) => [$U[$b]['spd'], $rank[$a]] <=> [$U[$a]['spd'], $rank[$b]]);

        foreach ($order as $uid) {
            if ($U[$uid]['hp'] <= 0 || ($U[$uid]['side'] === 'enemy' && !in_array($uid, $enemy_ids, true))) {
                continue;
            }
            [$friends, $foes] = $U[$uid]['side'] === 'ally' ? [$ally_ids, $enemy_ids] : [$enemy_ids, $ally_ids];
            $events[] = battle_act($U, $uid, $friends, $foes, $use_attribute);

            if (!battle_alive($U, $ally_ids)) {
                $result = 'lose';
                break;
            }
            if (!battle_alive($U, $enemy_ids)) {
                if (isset($wave_ids[$wave + 1])) {
                    // 次のウェーブへ（このターンの残りの行動は無し）
                    $wave++;
                    $enemy_ids = $wave_ids[$wave];
                    $events[] = ['t' => 'wave', 'wave' => $wave + 1, 'enemies' => $enemy_ids];
                } else {
                    $result = 'win';
                }
                break;
            }
        }

        // ターンの終わり: 強化・弱体化・挑発の残りターンを減らす
        foreach (array_merge($ally_ids, $enemy_ids) as $uid) {
            foreach ($U[$uid]['status'] as $type => $st) {
                if (--$U[$uid]['status'][$type]['turns'] <= 0) {
                    unset($U[$uid]['status'][$type]);
                    if ($U[$uid]['hp'] > 0) {
                        $events[] = ['t' => 'expire', 'target' => $uid, 'status' => $type];
                    }
                }
            }
        }
    }

    $events[] = ['t' => 'end', 'result' => $result];
    $allies_down = count(array_filter($ally_ids, fn ($id) => $U[$id]['hp'] <= 0));

    return [
        'result'      => $result,
        'turns'       => $turn,
        'units'       => $units_snapshot,
        'events'      => $events,
        'allies_down' => $allies_down,
    ];
}

/**
 * 演出用に、ユニットの情報のうち画面に必要なものだけを残す
 */
function battle_unit_public(array $u): array
{
    return [
        'uid'        => $u['uid'],
        'side'       => $u['side'],
        'wave'       => $u['wave'] ?? null,
        'name'       => $u['name'],
        'image'      => $u['image'],
        'attribute'  => $u['attribute'],
        'role'       => $u['role'],
        'level'      => $u['level'],
        'weakness'   => $u['weakness'],
        'resistance' => $u['resistance'],
        'max_hp'     => $u['max_hp'],
        'hp'         => $u['hp'],
        'skill'      => $u['skill']['name'] ?? null,
        'engine'     => $u['engine'],
    ];
}

function battle_alive(array $U, array $ids): bool
{
    foreach ($ids as $id) {
        if ($U[$id]['hp'] > 0) {
            return true;
        }
    }
    return false;
}

function battle_alive_ids(array $U, array $ids): array
{
    return array_values(array_filter($ids, fn ($id) => $U[$id]['hp'] > 0));
}

/**
 * 1人分の行動（スキル or 通常攻撃）。戻り値はイベント1件
 */
function battle_act(array &$U, string $uid, array $friends, array $foes, bool $use_attribute): array
{
    $actor = $U[$uid];
    $skill = $actor['skill'];
    $use_skill = false;

    if ($skill && $actor['cd'] <= 0) {
        $use_skill = true;
        // 回復スキルは、HP が減っている味方がいるときだけ使う
        if (in_array($skill['effect'], ['heal_single', 'heal_all'], true)) {
            $use_skill = false;
            foreach (battle_alive_ids($U, $friends) as $id) {
                if ($U[$id]['hp'] < $U[$id]['max_hp'] * HEAL_THRESHOLD) {
                    $use_skill = true;
                    break;
                }
            }
        }
    }

    if ($use_skill) {
        $U[$uid]['cd'] = max(0, (int)$skill['cooldown'] - 1);
        $results = battle_use_skill($U, $uid, $skill, $friends, $foes, $use_attribute);
        return ['t' => 'action', 'actor' => $uid, 'kind' => 'skill', 'skill' => $skill['name'], 'effect' => $skill['effect'], 'results' => $results];
    }

    if ($U[$uid]['cd'] > 0) {
        $U[$uid]['cd']--;
    }
    $target = battle_pick_target($U, $uid, $foes);
    $results = [battle_deal_damage($U, $uid, $target, 100, $use_attribute, false)];
    return ['t' => 'action', 'actor' => $uid, 'kind' => 'attack', 'skill' => null, 'effect' => 'attack', 'results' => $results];
}

/**
 * 攻撃の対象を選ぶ
 *   味方: HP の割合が一番低い敵を狙う（とどめを刺しやすい）
 *   敵:   挑発中の相手がいればその相手、いなければランダム
 */
function battle_pick_target(array $U, string $uid, array $foes): string
{
    $alive = battle_alive_ids($U, $foes);
    if ($U[$uid]['side'] === 'enemy') {
        foreach ($alive as $id) {
            if (isset($U[$id]['status']['taunt'])) {
                return $id;
            }
        }
        return $alive[random_int(0, count($alive) - 1)];
    }
    usort($alive, fn ($a, $b) => $U[$a]['hp'] / $U[$a]['max_hp'] <=> $U[$b]['hp'] / $U[$b]['max_hp']);
    return $alive[0];
}

function battle_effective_atk(array $u): float
{
    return $u['atk'] * (1 + ($u['status']['atk_up']['value'] ?? 0) / 100);
}

/**
 * ダメージを与える。戻り値は結果1件
 */
function battle_deal_damage(array &$U, string $from, string $to, int $power, bool $use_attribute, bool $is_skill = true): array
{
    $attacker = $U[$from];
    $target = $U[$to];

    $raw = battle_effective_atk($attacker) * $power / 100;
    // 音動機の効果: 与ダメージ%（属性が合うとき）・スキルのダメージ%
    $raw *= 1 + ($attacker['dmg_bonus'] + ($is_skill ? $attacker['skill_dmg_bonus'] : 0)) / 100;
    $def = $target['def'] * (1 - ($target['status']['def_down']['value'] ?? 0) / 100);
    $damage = max($raw * MIN_DAMAGE_RATE, $raw - $def * DEFENSE_RATE);

    // 属性: 味方の攻撃が敵の弱点・耐性に当たったとき
    $eff = null;
    if ($use_attribute && $attacker['attribute'] !== null) {
        if ($attacker['attribute'] === $target['weakness']) {
            $damage *= ATTRIBUTE_WEAK_RATE;
            $eff = 'weak';
        } elseif ($attacker['attribute'] === $target['resistance']) {
            $damage *= ATTRIBUTE_RESIST_RATE;
            $eff = 'resist';
        }
    }
    // 挑発中は受けるダメージを軽減
    if (isset($target['status']['taunt'])) {
        $damage *= 1 - $target['status']['taunt']['value'] / 100;
    }
    $damage *= random_int(92, 108) / 100;
    $damage = max(1, (int)round($damage));

    $U[$to]['hp'] = max(0, $target['hp'] - $damage);
    $ko = $U[$to]['hp'] === 0;
    if ($ko) {
        $U[$to]['status'] = [];
    }
    return ['target' => $to, 'type' => 'damage', 'amount' => $damage, 'hp' => $U[$to]['hp'], 'eff' => $eff, 'ko' => $ko];
}

function battle_heal(array &$U, string $from, string $to, int $power): array
{
    $amount = (int)round(battle_effective_atk($U[$from]) * $power / 100 * (1 + $U[$from]['heal_bonus'] / 100));
    $before = $U[$to]['hp'];
    $U[$to]['hp'] = min($U[$to]['max_hp'], $before + $amount);
    return ['target' => $to, 'type' => 'heal', 'amount' => $U[$to]['hp'] - $before, 'hp' => $U[$to]['hp']];
}

function battle_add_status(array &$U, string $to, string $type, int $value, int $turns): array
{
    // 同じ効果は重ねがけせず、上書きする
    $U[$to]['status'][$type] = ['value' => $value, 'turns' => $turns];
    return ['target' => $to, 'type' => 'status', 'status' => $type, 'value' => $value, 'turns' => $turns];
}

/**
 * スキルの効果。戻り値は結果の配列
 */
function battle_use_skill(array &$U, string $uid, array $skill, array $friends, array $foes, bool $use_attribute): array
{
    $power = (int)$skill['power'];
    $turns = (int)$skill['duration'];
    $results = [];

    switch ($skill['effect']) {
        case 'damage_single':
            $results[] = battle_deal_damage($U, $uid, battle_pick_target($U, $uid, $foes), $power, $use_attribute);
            break;
        case 'damage_all':
            foreach (battle_alive_ids($U, $foes) as $id) {
                $results[] = battle_deal_damage($U, $uid, $id, $power, $use_attribute);
            }
            break;
        case 'heal_single':
            $alive = battle_alive_ids($U, $friends);
            usort($alive, fn ($a, $b) => $U[$a]['hp'] / $U[$a]['max_hp'] <=> $U[$b]['hp'] / $U[$b]['max_hp']);
            $results[] = battle_heal($U, $uid, $alive[0], $power);
            break;
        case 'heal_all':
            foreach (battle_alive_ids($U, $friends) as $id) {
                $results[] = battle_heal($U, $uid, $id, $power);
            }
            break;
        case 'taunt':
            $results[] = battle_add_status($U, $uid, 'taunt', $power, $turns);
            break;
        case 'buff_atk':
            foreach (battle_alive_ids($U, $friends) as $id) {
                $results[] = battle_add_status($U, $id, 'atk_up', $power, $turns);
            }
            break;
        case 'debuff_def':
            foreach (battle_alive_ids($U, $foes) as $id) {
                $results[] = battle_add_status($U, $id, 'def_down', $power, $turns);
            }
            break;
    }
    return $results;
}
