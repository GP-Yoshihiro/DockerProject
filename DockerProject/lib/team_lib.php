<?php
// 所持カード・チーム編成の共通処理（htdocs/card/ と htdocs/team/ の画面から使う）
require_once __DIR__ . '/card_growth_lib.php';

const TEAM_SIZE = 3; // 1チームの枠の数

/** 画面にそのまま表示してよいチーム編成のエラー */
class TeamException extends GameException {}

/**
 * ユーザーの所持カード（レベル・ステータス計算済み）
 */
function fetch_user_cards(PDO $pdo, int $user_id): array
{
    return fetch_user_card_list($pdo, $user_id);
}

/**
 * ユーザーのチームを取得する（他人のチームや存在しないチームは null）
 */
function find_user_team(PDO $pdo, int $user_id, int $team_id): ?array
{
    $stmt = $pdo->prepare("SELECT id, name FROM user_teams WHERE id = ? AND user_id = ?");
    $stmt->execute([$team_id, $user_id]);
    return $stmt->fetch() ?: null;
}

/**
 * チームのメンバーを [position => カード情報（レベル・ステータス計算済み）] の形で取得する
 */
function fetch_team_members(PDO $pdo, int $team_id): array
{
    $stmt = $pdo->prepare(USER_CARD_SELECT . "
        JOIN user_team_members tm ON tm.user_card_id = uc.id
        WHERE tm.team_id = ?
        ORDER BY tm.position
    ");
    $stmt->execute([$team_id]);
    $members = [];
    $positions = $pdo->prepare("SELECT position, user_card_id FROM user_team_members WHERE team_id = ?");
    $positions->execute([$team_id]);
    $by_card = array_column($positions->fetchAll(), 'position', 'user_card_id');
    foreach ($stmt->fetchAll() as $row) {
        $members[(int)$by_card[$row['user_card_id']]] = enrich_user_card($row);
    }
    ksort($members);
    return $members;
}

/**
 * チームの編成を保存する
 * $slots = [position => user_card_id または null（空き枠）]
 */
function save_team(PDO $pdo, int $user_id, int $team_id, array $slots): void
{
    if (!find_user_team($pdo, $user_id, $team_id)) {
        throw new TeamException('チームが見つかりません。');
    }

    $card_ids = array_values(array_filter($slots));
    if (count($card_ids) !== count(array_unique($card_ids))) {
        throw new TeamException('同じカードを2つの枠に入れることはできません。');
    }

    $pdo->beginTransaction();
    try {
        // 自分の所持カードかどうかを確認（他人のカードIDを送られても編成できないように）
        if ($card_ids) {
            $placeholders = implode(',', array_fill(0, count($card_ids), '?'));
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM user_cards
                WHERE user_id = ? AND id IN ($placeholders)
            ");
            $stmt->execute(array_merge([$user_id], $card_ids));
            if ((int)$stmt->fetchColumn() !== count($card_ids)) {
                throw new TeamException('所持していないカードが指定されました。');
            }
        }

        // いったん全員外してから、指定された枠に入れ直す
        $stmt = $pdo->prepare("DELETE FROM user_team_members WHERE team_id = ?");
        $stmt->execute([$team_id]);

        $stmt = $pdo->prepare("
            INSERT INTO user_team_members (team_id, position, user_card_id) VALUES (?, ?, ?)
        ");
        foreach ($slots as $position => $user_card_id) {
            if ($user_card_id) {
                $stmt->execute([$team_id, $position, $user_card_id]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
