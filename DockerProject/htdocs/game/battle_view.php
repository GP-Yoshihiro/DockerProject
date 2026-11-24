<?php
// バトルの再生: サーバで計算済みのバトルログ（JSON）を、/js/battle_view.js が演出として再生する
// 報酬はバトルを実行した時点（POST）で付与済み。この画面は何度開いても結果は変わらない
require_once __DIR__ . '/../../lib/game_battle_lib.php';

$user_id = GAME_USER_ID;
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$battle = fetch_game_battle($pdo, $user_id, $id);
if (!$battle) {
    header('Location: index.php');
    exit;
}
$log = $battle['log'];
$rewards = $battle['rewards'];
$result = $battle['result'];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <?php render_game_head($log['title']); ?>
    <script>document.documentElement.classList.add('js');</script>
</head>
<body class="battle-page">
    <?php render_game_header($pdo, $user_id); ?>
    <h1><?= htmlspecialchars($log['title']) ?></h1>

    <div class="battle" id="battle">
        <div class="battle-info">
            <span id="battle-wave"></span>
            <span id="battle-turn"></span>
        </div>
        <div class="battle-side enemies" id="battle-enemies"></div>
        <div class="battle-banner" id="battle-banner" aria-live="polite"></div>
        <div class="battle-side allies" id="battle-allies"></div>
        <div class="battle-controls">
            <button type="button" data-speed="1" class="speed active">等速</button>
            <button type="button" data-speed="2" class="speed">2倍速</button>
            <button type="button" id="battle-skip">スキップ</button>
        </div>
    </div>

    <section class="panel result-panel result-<?= htmlspecialchars($result) ?>" id="battle-result">
        <h2 class="result-title">
            <?= BATTLE_RESULT_LABELS[$result] ?>
            <?php if ($log['mode'] === 'stage' && $result === 'win'): ?>
                <span class="stars"><?= str_repeat('★', (int)$battle['stars']) . str_repeat('☆', 3 - (int)$battle['stars']) ?></span>
            <?php endif; ?>
        </h2>
        <p class="muted"><?= (int)$battle['turns'] ?>ターン<?= $log['mode'] === 'stage' ? "（目標 {$log['meta']['target_turns']}ターン）" : '' ?></p>

        <?php if ($rewards['first_clear']): ?>
            <p class="notice">初回クリア！</p>
        <?php endif; ?>
        <?php if ($rewards['items']): ?>
            <h3>獲得アイテム</h3>
            <ul class="reward-list">
                <?php foreach ($rewards['items'] as $r): ?>
                    <li><?= htmlspecialchars($r['name']) ?> ×<?= number_format($r['quantity']) ?><?= $r['first'] ? ' <span class="first-badge">初回</span>' : '' ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <h3>経験値</h3>
        <ul class="reward-list">
            <?php foreach ($rewards['exp'] as $e): ?>
                <li>
                    <?= htmlspecialchars($e['name']) ?> +<?= (int)$e['gained'] ?>
                    <?php if ($e['level_after'] > $e['level_before']): ?>
                        <strong class="level-up">Lv<?= (int)$e['level_before'] ?> → Lv<?= (int)$e['level_after'] ?> LEVEL UP!</strong>
                    <?php else: ?>
                        （Lv<?= (int)$e['level_after'] ?>）
                    <?php endif; ?>
                    <?php if ($e['level_after'] >= $e['level_cap']): ?>
                        <span class="muted">レベル上限（進化でレベル上限アップ）</span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>

        <div class="links">
            <?php if ($log['mode'] === 'stage'): ?>
                <form method="post" action="stages.php" class="inline-form">
                    <input type="hidden" name="stage_id" value="<?= (int)$battle['stage_id'] ?>">
                    <input type="hidden" name="team_id" value="<?= (int)$battle['team_id'] ?>">
                    <button type="submit" class="btn-battle">もう一度挑戦</button>
                </form>
                <a href="stages.php?team_id=<?= (int)$battle['team_id'] ?>">ステージ選択へ</a>
            <?php else: ?>
                <a href="pvp.php">対戦相手の選択へ</a>
                <a href="/battle/ranking.php">ランキング</a>
            <?php endif; ?>
            <a href="/card/card_list.php">カードの育成</a>
            <a href="index.php">ホーム</a>
        </div>
    </section>

    <section class="panel">
        <h2>バトルログ</h2>
        <ol class="battle-log" id="battle-log"></ol>
        <noscript><p>JavaScript を有効にすると、バトルの演出とログが表示されます。</p></noscript>
        <details>
            <summary>バトルログ（JSON）</summary>
            <pre class="json-view"><?= htmlspecialchars(json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
        </details>
    </section>

    <!-- 演出用のデータ（サーバで計算したバトルの経過） -->
    <script type="application/json" id="battle-data"><?= json_encode($log, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    <script src="/js/battle_view.js" defer></script>
    <script src="/js/submit-once.js" defer></script>
</body>
</html>
