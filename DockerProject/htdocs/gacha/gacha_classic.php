<?php
// 資料03〜05 のガチャ（重み付き抽選・ボックスガチャ・JSON 出力）。ゲームのガチャは gacha_form.php（ピックアップガチャ）
require_once __DIR__ . '/../../lib/gacha_lib.php';

$user_id = GAME_USER_ID;
$stones = get_item_quantity($pdo, $user_id, ITEM_STONE);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <?php render_game_head('ガチャ（資料03〜05）'); ?>
</head>
<body>
    <?php render_game_header($pdo, $user_id); ?>
    <h1>ガチャ（資料03〜05）</h1>
    <p class="muted">重み付き抽選のガチャです。ゲームのピックアップガチャは<a href="gacha_form.php">こちら</a>。</p>
    <?php render_flash(); ?>
    <form class="gacha-panel" action="gacha_result.php" method="post">
        <p>
            <label>
                ガチャ:
                <select name="gacha_id">
                    <option value="1">通常ガチャ</option>
                    <option value="2">期間限定ガチャ（UR確率アップ）</option>
                    <option value="3">終了済みガチャ（期間外の確認用）</option>
                </select>
            </label>
        </p>
        <div class="gacha-buttons">
            <button type="submit" name="count" value="1" class="btn-gacha btn-single" <?= $stones < gacha_cost(1) ? 'disabled' : '' ?>>
                単発ガチャ（ガチャ石 <?= number_format(gacha_cost(1)) ?>）
            </button>
            <button type="submit" name="count" value="<?= GACHA_DRAW_COUNT ?>" class="btn-gacha" <?= $stones < gacha_cost(GACHA_DRAW_COUNT) ? 'disabled' : '' ?>>
                <?= GACHA_DRAW_COUNT ?>連ガチャ（ガチャ石 <?= number_format(gacha_cost(GACHA_DRAW_COUNT)) ?>）
            </button>
        </div>
        <p class="gacha-note">
            持っていないカードは新しく手に入り、持っているカードは自動で凸（最大<?= MAX_LIMIT_BREAK ?>凸）します。<br>
            完凸したカードはショップメダルに変換されます。
        </p>
        <?php if ($stones < gacha_cost(1)): ?>
            <p class="error">ガチャ石が足りません。ステージの初回クリアや対戦の勝利で手に入ります（<a href="/game/debug.php">デバッグで付与</a>）。</p>
        <?php endif; ?>
    </form>
    <p class="links">
        <a href="/box_gacha/box_gacha.php">ボックスガチャ</a>
        <a href="gacha_total_count.php">結果集計</a>
        <a href="gacha_stats.php">排出率の集計（確率の収束確認）</a>
        <a href="/">メニュー</a>
    </p>
    <script src="/js/submit-once.js" defer></script>
</body>
</html>
