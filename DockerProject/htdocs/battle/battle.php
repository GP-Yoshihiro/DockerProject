<?php
// 資料06: 対戦（バトル）シミュレーション。結果は対戦履歴（battle_histories）に保存される
require_once __DIR__ . '/../../lib/battle_lib.php';

$users = fetch_battle_users($pdo);
$names = array_column($users, 'name', 'id');

$user_id = 1;
$opponent_id = 2;
$error = null;
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT) ?: 0;
    $opponent_id = filter_input(INPUT_POST, 'opponent_id', FILTER_VALIDATE_INT) ?: 0;
    $count = filter_input(INPUT_POST, 'count', FILTER_VALIDATE_INT) ?: 0;

    if (!isset($names[$user_id], $names[$opponent_id])) {
        $error = 'ユーザーを選んでください。';
    } elseif (!in_array($count, [1, 10, BATTLE_MAX_COUNT], true)) {
        $error = '対戦回数が正しくありません。';
    } else {
        try {
            $winners = execute_battles($pdo, $user_id, $opponent_id, $count);
            $result = [
                'count'  => $count,
                'wins'   => count(array_filter($winners, fn ($w) => $w === $user_id)),
                'losses' => count(array_filter($winners, fn ($w) => $w === $opponent_id)),
                'draws'  => count(array_filter($winners, fn ($w) => $w === null)),
            ];
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            file_put_contents('php://stderr', '[battle] ' . $e->getMessage() . PHP_EOL);
            $error = 'エラーが発生しました。';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>対戦</title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
    <h1>対戦</h1>

    <?php if ($error !== null): ?>
        <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <?php if ($result !== null): ?>
        <div class="notice">
            <p>
                <strong><?= htmlspecialchars($names[$user_id]) ?></strong> vs
                <strong><?= htmlspecialchars($names[$opponent_id]) ?></strong>
                （<?= $result['count'] ?>戦）:
                <?= htmlspecialchars($names[$user_id]) ?> の
                <?= $result['wins'] ?>勝 <?= $result['losses'] ?>敗 <?= $result['draws'] ?>分
            </p>
            <p>対戦履歴に保存しました。ランキングは1分ごとに自動で更新されます。</p>
        </div>
    <?php endif; ?>

    <form class="battle-form" method="post" action="battle.php">
        <label>
            プレイヤー
            <select name="user_id">
                <?php foreach ($users as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" <?= (int)$u['id'] === $user_id ? 'selected' : '' ?>><?= htmlspecialchars($u['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <span>vs</span>
        <label>
            対戦相手
            <select name="opponent_id">
                <?php foreach ($users as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" <?= (int)$u['id'] === $opponent_id ? 'selected' : '' ?>><?= htmlspecialchars($u['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="battle-buttons">
            <button type="submit" name="count" value="1">1回対戦</button>
            <button type="submit" name="count" value="10">10回対戦</button>
            <button type="submit" name="count" value="<?= BATTLE_MAX_COUNT ?>"><?= BATTLE_MAX_COUNT ?>回対戦</button>
        </div>
    </form>
    <p class="json-note">勝敗はランダムです（勝ち・負け 各40%、引き分け 20%）。</p>

    <p class="links">
        <a href="ranking.php">対戦ランキング</a>
        <a href="/">メニューに戻る</a>
    </p>
    <script src="/js/submit-once.js" defer></script>
</body>
</html>
