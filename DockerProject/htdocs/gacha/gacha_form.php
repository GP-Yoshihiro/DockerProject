<?php
// ゲームのガチャ: Sキャラ ピックアップ / モチーフ音動機 ピックアップ / 恒常
require_once __DIR__ . '/../../lib/banner_gacha_lib.php';

$user_id = GAME_USER_ID;
$stones = get_item_quantity($pdo, $user_id, ITEM_STONE);
$banners = fetch_banners($pdo);
$pity = fetch_user_pity($pdo, $user_id);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <?php render_game_head('ガチャ'); ?>
</head>
<body>
    <?php render_game_header($pdo, $user_id); ?>
    <h1>ガチャ</h1>
    <?php render_flash(); ?>

    <?php foreach ($banners as $b): ?>
        <?php
        $type = $b['type'];
        $rate = BANNER_RATES[$type];
        $p = $pity[$type];
        $pools = fetch_banner_pools($pdo, $b);
        $pickup = $pools['pickup'];
        ?>
        <section class="panel banner banner-<?= htmlspecialchars($type) ?>">
            <div class="banner-body">
                <?php if ($pickup): ?>
                    <div class="banner-pickup">
                        <img src="<?= htmlspecialchars($pickup['image_url']) ?>" alt="">
                    </div>
                <?php endif; ?>
                <div class="banner-info">
                    <h2><?= htmlspecialchars($b['name']) ?></h2>
                    <?php if ($pickup): ?>
                        <p class="banner-pu">
                            PICK UP: <span class="rarity-badge rarity-<?= $pickup['tier'] ?>"><?= rarity_label($pickup['tier']) ?></span>
                            <strong><?= htmlspecialchars($pickup['name']) ?></strong>
                        </p>
                    <?php elseif ($type !== 'standard'): ?>
                        <p class="error">ピックアップが設定されていません（<a href="/game/admin.php#banners">登録画面</a>で設定）。</p>
                    <?php endif; ?>

                    <ul class="banner-rates">
                        <li>S級 <?= $rate['s'] / 100 ?>%（<?= $rate['hard'] ?>連目で確定）</li>
                        <?php if ($rate['pu'] !== null): ?>
                            <li>S級のうち <?= $rate['pu'] ?>% がピックアップ。すり抜けたら次のS級はピックアップ確定（最大 <?= $rate['hard'] * 2 ?>連）</li>
                        <?php else: ?>
                            <li>S級はキャラと音動機が半々</li>
                        <?php endif; ?>
                        <li>A級 <?= $rate['a'] / 100 ?>%。10回目は必ずA級以上の<?= $type === 'char' ? 'キャラ' : ($type === 'engine' ? '音動機' : 'キャラか音動機') ?></li>
                    </ul>

                    <div class="pity">
                        <div class="pity-row">
                            <span>S級まで</span>
                            <div class="pity-bar"><span style="width: <?= $p['s_count'] / $rate['hard'] * 100 ?>%"></span></div>
                            <span>あと <strong><?= $rate['hard'] - $p['s_count'] ?></strong> 連</span>
                        </div>
                        <div class="pity-row">
                            <span>A級以上まで</span>
                            <div class="pity-bar small"><span style="width: <?= $p['a_count'] / A_GUARANTEE * 100 ?>%"></span></div>
                            <span>あと <strong><?= A_GUARANTEE - $p['a_count'] ?></strong> 連</span>
                        </div>
                        <?php if ($p['guaranteed']): ?>
                            <p class="guaranteed">次のS級はピックアップ確定！</p>
                        <?php endif; ?>
                    </div>

                    <form class="gacha-buttons" action="banner_result.php" method="post">
                        <input type="hidden" name="banner_id" value="<?= (int)$b['id'] ?>">
                        <button type="submit" name="count" value="1" class="btn-gacha btn-single" <?= $stones < gacha_cost(1) ? 'disabled' : '' ?>>
                            1回（石 <?= number_format(gacha_cost(1)) ?>）
                        </button>
                        <button type="submit" name="count" value="<?= GACHA_DRAW_COUNT ?>" class="btn-gacha" <?= $stones < gacha_cost(GACHA_DRAW_COUNT) ? 'disabled' : '' ?>>
                            <?= GACHA_DRAW_COUNT ?>回（石 <?= number_format(gacha_cost(GACHA_DRAW_COUNT)) ?>）
                        </button>
                    </form>
                </div>
            </div>
        </section>
    <?php endforeach; ?>

    <p class="muted">
        天井のカウントはガチャの種類ごとに引き継がれます（ピックアップが変わっても0に戻りません）。
        持っているキャラ・音動機が出ると自動で凸し、完凸後はショップメダルになります。
        レア度: Sキャラ ＞ S音動機 ＞ Aキャラ ＞ A音動機 ＞ B音動機
    </p>
    <?php if ($stones < gacha_cost(1)): ?>
        <p class="error">ガチャ石が足りません。ステージの初回クリアや対戦の勝利で手に入ります（<a href="/game/debug.php">デバッグで付与</a>）。</p>
    <?php endif; ?>
    <p class="links">
        <a href="/card/engine_list.php">所持音動機</a>
        <a href="/card/card_list.php">所持キャラ</a>
        <a href="gacha_classic.php">資料03〜05 のガチャ</a>
    </p>
    <script src="/js/submit-once.js" defer></script>
</body>
</html>
