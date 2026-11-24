<?php
// ゲームのホーム
require_once __DIR__ . '/../../lib/game_battle_lib.php';

$user_id = GAME_USER_ID;
$teams = fetch_teams_with_power($pdo, $user_id);
$stages = fetch_stage_list($pdo, $user_id);
$cleared = count(array_filter($stages, fn ($s) => (int)$s['clear_count'] > 0));
$stars = array_sum(array_column($stages, 'best_stars'));
$recent = fetch_recent_battles($pdo, $user_id);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <?php render_game_head('ゲーム'); ?>
</head>
<body>
    <?php render_game_header($pdo, $user_id); ?>
    <h1>ゲーム</h1>

    <div class="home-grid">
        <a class="home-tile tile-stage" href="stages.php">
            <strong>ステージ</strong>
            <span>クリア <?= $cleared ?> / <?= count($stages) ?>・★ <?= $stars ?> / <?= count($stages) * 3 ?></span>
        </a>
        <a class="home-tile tile-pvp" href="pvp.php">
            <strong>対戦</strong>
            <span>他のユーザーのチームと戦う</span>
        </a>
        <a class="home-tile tile-gacha" href="/gacha/gacha_form.php">
            <strong>ガチャ</strong>
            <span>ガチャ石でカードを入手</span>
        </a>
        <a class="home-tile" href="/card/card_list.php"><strong>キャラ</strong><span>レベルアップ・進化・音動機の装備</span></a>
        <a class="home-tile" href="/card/engine_list.php"><strong>音動機</strong><span>キャラの装備。レベルアップ・進化</span></a>
        <a class="home-tile" href="/team/team_list.php"><strong>チーム編成</strong><span>3人で出撃</span></a>
        <a class="home-tile" href="items.php"><strong>アイテム</strong><span>所持アイテム</span></a>
        <a class="home-tile" href="shop.php"><strong>ショップ</strong><span>メダルで交換</span></a>
        <a class="home-tile" href="/battle/ranking.php"><strong>ランキング</strong><span>対戦の勝率（1分ごとに更新）</span></a>
        <a class="home-tile tile-debug" href="debug.php"><strong>デバッグ</strong><span>アイテムの付与</span></a>
        <a class="home-tile tile-debug" href="admin.php"><strong>登録画面</strong><span>キャラ・音動機・ピックアップ</span></a>
    </div>

    <h2>チーム</h2>
    <table>
        <tr><th>チーム</th><th>メンバー</th><th>戦力</th></tr>
        <?php foreach ($teams as $t): ?>
            <tr>
                <td><?= htmlspecialchars($t['name']) ?></td>
                <td><?= $t['cards'] ? htmlspecialchars(implode('・', array_map(fn ($c) => "{$c['name']} Lv{$c['level']}", $t['cards']))) : '（空き）' ?></td>
                <td><?= number_format($t['power']) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>

    <?php if ($recent): ?>
        <h2>最近のバトル</h2>
        <table>
            <tr><th>日時</th><th>内容</th><th>結果</th><th></th></tr>
            <?php foreach ($recent as $b): ?>
                <tr>
                    <td><?= htmlspecialchars($b['created_at']) ?></td>
                    <td><?= htmlspecialchars($b['mode'] === 'stage' ? $b['stage_name'] : "対戦: {$b['opponent_name']}") ?></td>
                    <td class="result-<?= htmlspecialchars($b['result']) ?>">
                        <?= BATTLE_RESULT_LABELS[$b['result']] ?>
                        <?= $b['mode'] === 'stage' && $b['result'] === 'win' ? str_repeat('★', (int)$b['stars']) : '' ?>
                    </td>
                    <td><a href="battle_view.php?id=<?= (int)$b['id'] ?>">再生</a></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

    <p class="links"><a href="/">メニューに戻る</a></p>
</body>
</html>
