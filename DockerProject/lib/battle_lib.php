<?php
// 資料06: 対戦（バトル）履歴と対戦ランキング
require_once __DIR__ . '/db.php';

date_default_timezone_set('Asia/Tokyo');

/** 1回の「まとめて対戦」で実行できる最大回数 */
const BATTLE_MAX_COUNT = 100;

/**
 * ユーザー一覧（対戦相手の選択用）
 */
function fetch_battle_users(PDO $pdo): array
{
    return $pdo->query("SELECT id, name FROM users ORDER BY id")->fetchAll();
}

/**
 * 2人のユーザーを $count 回対戦させ、対戦履歴に保存する。戻り値は各バトルの勝者（NULL = 引き分け）の配列
 *
 * 勝敗はランダム（勝ち・負け 各40%、引き分け 20%）。
 * ランキングはここでは更新しない。crontab のバッチ（batch/summarize_battles.php）が1分ごとに集計する。
 */
function execute_battles(PDO $pdo, int $user_id, int $opponent_id, int $count = 1): array
{
    if ($user_id === $opponent_id) {
        throw new InvalidArgumentException('自分自身とは対戦できません。');
    }
    if ($count < 1 || $count > BATTLE_MAX_COUNT) {
        throw new InvalidArgumentException('対戦回数が正しくありません。');
    }

    $winners = [];
    for ($i = 0; $i < $count; $i++) {
        $r = random_int(1, 100);
        $winners[] = $r <= 40 ? $user_id : ($r <= 80 ? $opponent_id : null);
    }

    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("
        INSERT INTO battle_histories (player1_user_id, player2_user_id, winner_user_id, created_at)
        VALUES (?, ?, ?, ?)
    ");
    $pdo->beginTransaction();
    try {
        foreach ($winners as $winner) {
            $stmt->execute([$user_id, $opponent_id, $winner, $now]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $winners;
}

/**
 * 対戦履歴を集計して、対戦ランキング（battle_rankings）を作り直す。戻り値はランキングの件数
 *
 * - 1回のバトルは player1 / player2 の2人分の成績になるので、UNION ALL で「ユーザーごとの1行」に展開して集計する
 * - 勝率(%) = 勝ち / (勝ち + 負け + 引き分け) × 100
 * - 順位: 勝率が高い順。勝率が同じなら勝ち数が多い順。両方同じなら同じ順位（RANK）
 * - 「古いランキングを消す → 新しいランキングを入れる」を1つのトランザクションで行うので、
 *   集計中に画面を開いても、ランキングが空になったり途中の状態が見えたりしない
 */
function summarize_battles(PDO $pdo, ?string $now = null): int
{
    $now ??= date('Y-m-d H:i:s');

    $pdo->beginTransaction();
    try {
        $pdo->exec("DELETE FROM battle_rankings");

        $stmt = $pdo->prepare("
            INSERT INTO battle_rankings (user_id, rank_no, wins, losses, draws, win_rate, updated_at)
            SELECT
                user_id,
                RANK() OVER (ORDER BY win_rate DESC, wins DESC) AS rank_no,
                wins, losses, draws, win_rate, ?
            FROM (
                SELECT
                    user_id,
                    SUM(result = 'win')  AS wins,
                    SUM(result = 'lose') AS losses,
                    SUM(result = 'draw') AS draws,
                    ROUND(SUM(result = 'win') / COUNT(*) * 100, 2) AS win_rate
                FROM (
                    SELECT player1_user_id AS user_id,
                           CASE WHEN winner_user_id IS NULL THEN 'draw'
                                WHEN winner_user_id = player1_user_id THEN 'win'
                                ELSE 'lose' END AS result
                    FROM battle_histories
                    UNION ALL
                    SELECT player2_user_id AS user_id,
                           CASE WHEN winner_user_id IS NULL THEN 'draw'
                                WHEN winner_user_id = player2_user_id THEN 'win'
                                ELSE 'lose' END AS result
                    FROM battle_histories
                ) AS results
                GROUP BY user_id
            ) AS stats
        ");
        $stmt->execute([$now]);
        $count = $stmt->rowCount();

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $count;
}

/**
 * 集計済みのランキングを順位順に取得する（画面表示用。集計はしない）
 */
function fetch_battle_ranking(PDO $pdo): array
{
    return $pdo->query("
        SELECT r.rank_no, r.user_id, u.name, r.wins, r.losses, r.draws, r.win_rate, r.updated_at
        FROM battle_rankings r
        JOIN users u ON u.id = r.user_id
        ORDER BY r.rank_no, r.user_id
    ")->fetchAll();
}

/**
 * ランキングの最終更新日時（まだ一度も集計されていなければ null）
 */
function fetch_ranking_updated_at(PDO $pdo): ?string
{
    $value = $pdo->query("SELECT MAX(updated_at) FROM battle_rankings")->fetchColumn();
    return $value === false ? null : $value;
}

/**
 * まだランキングに反映されていない対戦があるか（最終集計より後のバトルの件数）
 */
function count_unsummarized_battles(PDO $pdo, ?string $updated_at): int
{
    if ($updated_at === null) {
        return (int)$pdo->query("SELECT COUNT(*) FROM battle_histories")->fetchColumn();
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM battle_histories WHERE created_at > ?");
    $stmt->execute([$updated_at]);
    return (int)$stmt->fetchColumn();
}
