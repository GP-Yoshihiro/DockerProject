<?php
// ステージ攻略・対戦（PvP）の実行と、報酬・経験値・結果の保存
require_once __DIR__ . '/card_growth_lib.php';
require_once __DIR__ . '/battle_engine.php';

const ENEMY_IMAGE_DIR = '/images/enemies/';

/** 敗北したときにもらえる経験値の割合 */
const LOSE_EXP_RATE = 0.2;

/** 対戦（PvP）の報酬 */
const PVP_REWARDS = [
    'win'  => ['items' => [ITEM_STONE => 50, ITEM_GOLD => 300], 'exp' => 150],
    'draw' => ['items' => [ITEM_GOLD => 150], 'exp' => 100],
    'lose' => ['items' => [], 'exp' => 60],
];

const BATTLE_RESULT_LABELS = ['win' => '勝利', 'lose' => '敗北', 'draw' => '引き分け'];

// ---------------------------------------------------------------
// チーム
// ---------------------------------------------------------------

/**
 * ユーザーのチーム一覧（メンバーの戦力の合計付き）
 */
function fetch_teams_with_power(PDO $pdo, int $user_id): array
{
    $stmt = $pdo->prepare("SELECT id, name FROM user_teams WHERE user_id = ? ORDER BY id");
    $stmt->execute([$user_id]);
    $teams = [];
    foreach ($stmt->fetchAll() as $team) {
        $cards = fetch_team_cards($pdo, $user_id, (int)$team['id']);
        $team['cards'] = $cards;
        $team['power'] = array_sum(array_column($cards, 'power'));
        $teams[] = $team;
    }
    return $teams;
}

/**
 * チームのメンバー（枠の順）。他人のチームは GameException
 */
function fetch_team_cards(PDO $pdo, int $user_id, int $team_id): array
{
    $stmt = $pdo->prepare("SELECT id FROM user_teams WHERE id = ? AND user_id = ?");
    $stmt->execute([$team_id, $user_id]);
    if (!$stmt->fetch()) {
        throw new GameException('チームが見つかりません。');
    }
    $stmt = $pdo->prepare(USER_CARD_SELECT . "
        JOIN user_team_members tm ON tm.user_card_id = uc.id
        WHERE tm.team_id = ?
        ORDER BY tm.position
    ");
    $stmt->execute([$team_id]);
    return array_map('enrich_user_card', $stmt->fetchAll());
}

/**
 * カードからバトル用のユニットを作る
 */
function card_battle_units(array $cards, string $prefix, string $side): array
{
    $units = [];
    foreach (array_values($cards) as $i => $c) {
        $units[] = make_battle_unit("{$prefix}{$i}", $side, [
            'name'        => $c['name'],
            'image'       => $c['image_url'],
            'hp'          => $c['stats']['hp'],
            'atk'         => $c['stats']['atk'],
            'def'         => $c['stats']['def'],
            'spd'         => $c['stats']['spd'],
            'attribute'   => $c['attribute'],
            'role'        => $c['role'],
            'level'       => (int)$c['level'],
            'limit_break' => (int)$c['limit_break'],
            'skill'       => $c['skill'],
        ]);
    }
    return $units;
}

// ---------------------------------------------------------------
// ステージ
// ---------------------------------------------------------------

function enemy_image_url(?string $image): string
{
    return image_url_or_fallback(ENEMY_IMAGE_DIR, $image);
}

/**
 * ステージ一覧（解放状況・★・出てくる敵付き）
 * ステージは id の順に1本道。1つ前のステージをクリアしていれば解放
 */
function fetch_stage_list(PDO $pdo, int $user_id): array
{
    $stmt = $pdo->prepare("
        SELECT s.*, COALESCE(us.best_stars, 0) AS best_stars, COALESCE(us.clear_count, 0) AS clear_count
        FROM stages s
        LEFT JOIN user_stages us ON us.stage_id = s.id AND us.user_id = ?
        ORDER BY s.id
    ");
    $stmt->execute([$user_id]);
    $stages = $stmt->fetchAll();

    $prev_cleared = true;
    foreach ($stages as &$stage) {
        $stage['unlocked'] = $prev_cleared;
        $stage['waves'] = fetch_stage_waves($pdo, (int)$stage['id']);
        $stage['rewards'] = fetch_stage_rewards($pdo, (int)$stage['id']);
        $prev_cleared = (int)$stage['clear_count'] > 0;
    }
    return $stages;
}

/**
 * ステージの敵 [ウェーブ番号 => [敵, 敵, ...]]
 */
function fetch_stage_waves(PDO $pdo, int $stage_id): array
{
    $stmt = $pdo->prepare("
        SELECT sw.wave, e.*, s.name AS skill_name, s.effect AS skill_effect, s.power AS skill_power,
               s.cooldown AS skill_cooldown, s.duration AS skill_duration
        FROM stage_waves sw
        JOIN enemies e ON e.id = sw.enemy_id
        LEFT JOIN skills s ON s.id = e.skill_id
        WHERE sw.stage_id = ?
        ORDER BY sw.wave, sw.position
    ");
    $stmt->execute([$stage_id]);
    $waves = [];
    foreach ($stmt->fetchAll() as $row) {
        $row['image_url'] = enemy_image_url($row['image']);
        $waves[(int)$row['wave']][] = $row;
    }
    return $waves;
}

function fetch_stage_rewards(PDO $pdo, int $stage_id): array
{
    $stmt = $pdo->prepare("
        SELECT r.kind, r.item_id, r.quantity, r.probability, i.name
        FROM stage_rewards r JOIN items i ON i.id = r.item_id
        WHERE r.stage_id = ? ORDER BY r.kind, r.id
    ");
    $stmt->execute([$stage_id]);
    return $stmt->fetchAll();
}

/**
 * 敵からバトル用のユニットを作る
 */
function enemy_battle_waves(array $waves): array
{
    $result = [];
    foreach (array_values($waves) as $w => $enemies) {
        foreach ($enemies as $i => $e) {
            $skill = $e['skill_id'] ? [
                'id' => (int)$e['skill_id'], 'name' => $e['skill_name'], 'effect' => $e['skill_effect'],
                'power' => (int)$e['skill_power'], 'cooldown' => (int)$e['skill_cooldown'], 'duration' => (int)$e['skill_duration'],
            ] : null;
            $result[$w][] = make_battle_unit('w' . ($w + 1) . "e{$i}", 'enemy', [
                'name'       => $e['name'],
                'image'      => $e['image_url'],
                'hp'         => $e['hp'],
                'atk'        => $e['atk'],
                'def'        => $e['def'],
                'spd'        => $e['spd'],
                'weakness'   => $e['weakness'],
                'resistance' => $e['resistance'],
                'skill'      => $skill,
            ]);
        }
    }
    return $result;
}

/**
 * クリア評価（★）: ★1 クリア / ★2 誰も倒れずにクリア / ★3 目標ターン以内にクリア
 */
function stage_stars(array $battle, int $target_turns): int
{
    if ($battle['result'] !== 'win') {
        return 0;
    }
    return 1 + ($battle['allies_down'] === 0 ? 1 : 0) + ($battle['turns'] <= $target_turns ? 1 : 0);
}

/**
 * ステージに挑戦する。戻り値は保存したバトルの ID（battle_view.php で再生する）
 */
function run_stage_battle(PDO $pdo, int $user_id, int $stage_id, int $team_id): int
{
    $stage = null;
    foreach (fetch_stage_list($pdo, $user_id) as $s) {
        if ((int)$s['id'] === $stage_id) {
            $stage = $s;
        }
    }
    if ($stage === null) {
        throw new GameException('ステージが見つかりません。');
    }
    if (!$stage['unlocked']) {
        throw new GameException('前のステージをクリアすると挑戦できます。');
    }
    $cards = fetch_team_cards($pdo, $user_id, $team_id);
    if (!$cards) {
        throw new GameException('チームにカードがいません。チーム編成でカードを入れてください。');
    }

    // バトルの計算（サーバで最後まで行う）
    $battle = simulate_battle(
        card_battle_units($cards, 'a', 'ally'),
        enemy_battle_waves($stage['waves']),
        true,
        'lose'
    );
    $stars = stage_stars($battle, (int)$stage['target_turns']);
    $now = date('Y-m-d H:i:s');

    $pdo->beginTransaction();
    try {
        lock_user($pdo, $user_id);

        $items = [];
        $first_clear = false;
        if ($battle['result'] === 'win') {
            $stmt = $pdo->prepare("SELECT clear_count FROM user_stages WHERE user_id = ? AND stage_id = ? FOR UPDATE");
            $stmt->execute([$user_id, $stage_id]);
            $first_clear = (int)$stmt->fetchColumn() === 0;

            foreach ($stage['rewards'] as $r) {
                if ($r['kind'] === 'first' && !$first_clear) {
                    continue;
                }
                if (random_int(1, 100) > (int)$r['probability']) {
                    continue;
                }
                $items[] = ['item_id' => (int)$r['item_id'], 'name' => $r['name'], 'quantity' => (int)$r['quantity'], 'first' => $r['kind'] === 'first'];
            }
            grant_items($pdo, $user_id, $items);

            $stmt = $pdo->prepare("
                INSERT INTO user_stages (user_id, stage_id, best_stars, clear_count, first_cleared_at) VALUES (?, ?, ?, 1, ?)
                ON DUPLICATE KEY UPDATE best_stars = GREATEST(best_stars, VALUES(best_stars)), clear_count = clear_count + 1
            ");
            $stmt->execute([$user_id, $stage_id, $stars, $now]);
        }

        $exp = (int)$stage['exp'];
        if ($battle['result'] !== 'win') {
            $exp = (int)round($exp * LOSE_EXP_RATE);
        }
        $exp_results = [];
        foreach ($cards as $c) {
            $exp_results[] = give_card_exp($pdo, $user_id, (int)$c['user_card_id'], $exp);
        }

        $rewards = ['items' => $items, 'exp' => $exp_results, 'first_clear' => $first_clear, 'stars' => $stars];
        $battle_id = save_game_battle($pdo, $user_id, 'stage', $stage_id, null, $team_id, $battle, $stars, $rewards, [
            'title' => $stage['name'],
            'target_turns' => (int)$stage['target_turns'],
        ], $now);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $battle_id;
}

// ---------------------------------------------------------------
// 対戦（PvP）
// ---------------------------------------------------------------

/**
 * 対戦相手の一覧（自分以外で、メンバーがいるチームを持っているユーザー）
 */
function fetch_pvp_opponents(PDO $pdo, int $user_id): array
{
    $stmt = $pdo->prepare("
        SELECT u.id, u.name, MIN(t.id) AS team_id,
               r.rank_no, r.wins, r.losses, r.draws, r.win_rate
        FROM users u
        JOIN user_teams t ON t.user_id = u.id
        JOIN user_team_members tm ON tm.team_id = t.id
        LEFT JOIN battle_rankings r ON r.user_id = u.id
        WHERE u.id <> ?
        GROUP BY u.id, u.name, r.rank_no, r.wins, r.losses, r.draws, r.win_rate
        ORDER BY u.id
    ");
    $stmt->execute([$user_id]);
    $opponents = [];
    foreach ($stmt->fetchAll() as $o) {
        $o['cards'] = fetch_team_cards($pdo, (int)$o['id'], (int)$o['team_id']);
        $o['power'] = array_sum(array_column($o['cards'], 'power'));
        $opponents[] = $o;
    }
    return $opponents;
}

/**
 * 他のユーザーのチームと対戦する。結果は対戦履歴（battle_histories）にも保存され、cron のランキングに反映される
 */
function run_pvp_battle(PDO $pdo, int $user_id, int $opponent_id, int $team_id): int
{
    $opponent = null;
    foreach (fetch_pvp_opponents($pdo, $user_id) as $o) {
        if ((int)$o['id'] === $opponent_id) {
            $opponent = $o;
        }
    }
    if ($opponent === null) {
        throw new GameException('対戦相手が見つかりません。');
    }
    $cards = fetch_team_cards($pdo, $user_id, $team_id);
    if (!$cards) {
        throw new GameException('チームにカードがいません。チーム編成でカードを入れてください。');
    }

    // 対戦はカード同士なので属性の補正なし。時間切れは引き分け
    $battle = simulate_battle(
        card_battle_units($cards, 'a', 'ally'),
        [card_battle_units($opponent['cards'], 'o', 'enemy')],
        false,
        'draw'
    );
    $result = $battle['result'];
    $now = date('Y-m-d H:i:s');

    $pdo->beginTransaction();
    try {
        lock_user($pdo, $user_id);

        // 資料06 の対戦履歴（cron のバッチがランキングに集計する）
        $winner = match ($result) { 'win' => $user_id, 'lose' => $opponent_id, default => null };
        $stmt = $pdo->prepare("
            INSERT INTO battle_histories (player1_user_id, player2_user_id, winner_user_id, created_at) VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$user_id, $opponent_id, $winner, $now]);

        $items = [];
        foreach (PVP_REWARDS[$result]['items'] as $item_id => $quantity) {
            $items[] = ['item_id' => $item_id, 'name' => item_name($pdo, $item_id), 'quantity' => $quantity, 'first' => false];
        }
        grant_items($pdo, $user_id, $items);

        $exp_results = [];
        foreach ($cards as $c) {
            $exp_results[] = give_card_exp($pdo, $user_id, (int)$c['user_card_id'], PVP_REWARDS[$result]['exp']);
        }

        $rewards = ['items' => $items, 'exp' => $exp_results, 'first_clear' => false, 'stars' => 0];
        $battle_id = save_game_battle($pdo, $user_id, 'pvp', null, $opponent_id, $team_id, $battle, 0, $rewards, [
            'title' => "{$opponent['name']} との対戦",
        ], $now);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $battle_id;
}

// ---------------------------------------------------------------
// バトル結果の保存・取得
// ---------------------------------------------------------------

function save_game_battle(PDO $pdo, int $user_id, string $mode, ?int $stage_id, ?int $opponent_id, int $team_id,
                          array $battle, int $stars, array $rewards, array $meta, string $now): int
{
    $log = [
        'mode'   => $mode,
        'title'  => $meta['title'],
        'meta'   => $meta,
        'result' => $battle['result'],
        'turns'  => $battle['turns'],
        'units'  => $battle['units'],
        'events' => $battle['events'],
    ];
    $stmt = $pdo->prepare("
        INSERT INTO game_battles (user_id, mode, stage_id, opponent_user_id, team_id, result, stars, turns, log_json, rewards_json, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $user_id, $mode, $stage_id, $opponent_id, $team_id, $battle['result'], $stars, $battle['turns'],
        json_encode($log, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        json_encode($rewards, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        $now,
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * 自分のバトル結果を1件取得する（他人のものは null）
 */
function fetch_game_battle(PDO $pdo, int $user_id, int $battle_id): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM game_battles WHERE id = ? AND user_id = ?");
    $stmt->execute([$battle_id, $user_id]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $row['log'] = json_decode($row['log_json'], true);
    $row['rewards'] = json_decode($row['rewards_json'], true);
    return $row;
}

/**
 * 最近のバトル（ホーム画面用）
 */
function fetch_recent_battles(PDO $pdo, int $user_id, int $limit = 5): array
{
    $stmt = $pdo->prepare("
        SELECT b.id, b.mode, b.result, b.stars, b.turns, b.created_at, s.name AS stage_name, u.name AS opponent_name
        FROM game_battles b
        LEFT JOIN stages s ON s.id = b.stage_id
        LEFT JOIN users u ON u.id = b.opponent_user_id
        WHERE b.user_id = ?
        ORDER BY b.id DESC
        LIMIT " . (int)$limit
    );
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}
