<?php
// カード（アイテム）関連の共通処理

const CARD_IMAGE_DIR = '/images/cards/';        // htdocs から見た画像フォルダ
const CARD_NO_IMAGE  = 'no_image.svg';          // 画像が登録されていないときの代わりの画像

/**
 * cards.image（ファイル名）から、<img src="..."> に入れるURLを作る
 */
function card_image_url(?string $image): string
{
    // DBの値をそのままパスに使うので、フォルダをまたぐ指定（../ など）は受け付けない
    if ($image === null || $image === '' || basename($image) !== $image) {
        $image = CARD_NO_IMAGE;
    }
    return CARD_IMAGE_DIR . rawurlencode($image);
}

const RARITY_LABELS = [5 => 'UR', 4 => 'SSR', 3 => 'SR', 2 => 'R', 1 => 'N'];

/**
 * レア度（星の数）を表示用のラベルにする
 */
function rarity_label(int $rarity): string
{
    return RARITY_LABELS[$rarity] ?? 'N';
}

/**
 * 演出を使うページの <head> に入れるスクリプト
 * 描画より先に html へ js クラスを付けて、カードを最初から裏向きにしておく（表が一瞬見えないように）
 * 「視差効果を減らす」設定の人には演出をしない
 */
function render_reveal_head(): void
{
    echo '<script>if (!matchMedia("(prefers-reduced-motion: reduce)").matches) document.documentElement.classList.add("js");</script>' . "\n";
}

/**
 * ガチャ結果のカード一覧（と演出用の画面）を出力する
 *
 * JavaScript が動く環境では /js/gacha.js が
 *   「タップして開封」→ 光の演出 → カードを1枚ずつめくる
 * という演出を行う。JavaScript が無くても結果はそのまま表示される。
 */
function render_card_results(array $results): void
{
    $max = $results ? max(array_map(fn ($r) => (int)$r['rarity'], $results)) : 1;
    ?>
    <div class="card-grid<?= count($results) === 1 ? ' single' : '' ?>" data-max-rarity="<?= $max ?>">
        <?php foreach ($results as $i => $item): ?>
            <?php $rarity = (int)$item['rarity']; ?>
            <figure class="card rarity-<?= $rarity ?>" data-rarity="<?= $rarity ?>" data-card-id="<?= htmlspecialchars($item['card_id']) ?>" style="--i: <?= $i ?>">
                <div class="card-flip">
                    <div class="card-face card-front">
                        <img src="<?= htmlspecialchars(card_image_url($item['image'])) ?>" alt="<?= htmlspecialchars($item['name']) ?>">
                    </div>
                    <div class="card-face card-back" aria-hidden="true"></div>
                </div>
                <!-- 画像の下にレア度とカード名 -->
                <figcaption>
                    <span class="rarity-badge rarity-<?= $rarity ?>"><?= rarity_label($rarity) ?></span>
                    <span class="card-name"><?= htmlspecialchars($item['name']) ?></span>
                </figcaption>
            </figure>
        <?php endforeach; ?>
    </div>
    <p class="result-summary">
        最高レア: <span class="rarity-badge rarity-<?= $max ?>"><?= rarity_label($max) ?></span>
        <?= str_repeat('★', $max) ?>
    </p>

    <div class="summon" data-rarity="<?= $max ?>">
        <div class="summon-stage">
            <div class="summon-orb"></div>
            <p class="summon-text">タップして開封</p>
        </div>
        <button type="button" class="summon-skip">スキップ</button>
    </div>
    <div class="flash" aria-hidden="true"></div>
    <script src="/js/gacha.js" defer></script>
    <?php
}
