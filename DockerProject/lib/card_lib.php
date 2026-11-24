<?php
// カード（アイテム）関連の共通処理

const CARD_IMAGE_DIR = '/images/cards/';        // htdocs から見た画像フォルダ
const CARD_NO_IMAGE  = 'no_image.svg';          // 画像が登録されていないときの代わりの画像

/**
 * cards.image（ファイル名）から、<img src="..."> に入れるURLを作る
 */
function card_image_url(?string $image): string
{
    return image_url_or_fallback(CARD_IMAGE_DIR, $image);
}

/**
 * 画像フォルダとファイル名から URL を作る。ファイルが無いときは代わりの画像（no_image.svg）にする
 * （追加キャラや敵の画像は各自で用意するので、置いていなくても画面が崩れないように）
 */
function image_url_or_fallback(string $dir, ?string $image): string
{
    // DBの値をそのままパスに使うので、フォルダをまたぐ指定（../ など）は受け付けない
    if ($image === null || $image === '' || basename($image) !== $image) {
        return CARD_IMAGE_DIR . CARD_NO_IMAGE;
    }
    // Web サーバから実行されているときは、ファイルがあるかを確認する
    $docroot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    if ($docroot !== '' && !is_file($docroot . $dir . $image)) {
        return CARD_IMAGE_DIR . CARD_NO_IMAGE;
    }
    return $dir . rawurlencode($image);
}

/**
 * htdocs/images/ からの相対パス（例: zzz/standing pose/xxx.png）から URL を作る
 * フォルダ名・ファイル名に空白やアクセント記号（è など）があってもよいように、1階層ずつ URL エンコードする
 * パスが不正（.. を含むなど）・ファイルが無いときは null
 */
function image_path_url(?string $path): ?string
{
    if ($path === null || $path === '' || !is_safe_image_path($path)) {
        return null;
    }
    $docroot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    if ($docroot !== '' && !is_file($docroot . '/images/' . $path)) {
        return null;
    }
    return '/images/' . implode('/', array_map('rawurlencode', explode('/', $path)));
}

/**
 * 画像パスとして安全か（フォルダの外を指さない・制御文字やバックスラッシュを含まない）
 */
function is_safe_image_path(string $path): bool
{
    if (str_starts_with($path, '/') || str_contains($path, '\\') || preg_match('/[\x00-\x1f]/', $path)) {
        return false;
    }
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.' || $part === '..') {
            return false;
        }
    }
    return true;
}

/**
 * キャラのアイコン（正方形の小さい画像）。無ければ立ち絵 → 旧カード画像 → 代わりの画像
 */
function card_icon_url(array $card): string
{
    return image_path_url($card['icon_image'] ?? null)
        ?? image_path_url($card['stand_image'] ?? null)
        ?? card_image_url($card['image'] ?? null);
}

/**
 * キャラの立ち絵（大きい画像）。無ければ旧カード画像 → アイコン → 代わりの画像
 */
function card_stand_url(array $card): string
{
    $legacy = card_image_url($card['image'] ?? null);
    return image_path_url($card['stand_image'] ?? null)
        ?? (str_ends_with($legacy, CARD_NO_IMAGE) ? null : $legacy)
        ?? image_path_url($card['icon_image'] ?? null)
        ?? $legacy;
}

/**
 * レア度の数値（キャラと音動機を合わせた順番）
 *   5 Sキャラ ＞ 4 S音動機 ＞ 3 Aキャラ ＞ 2 A音動機 ＞ 1 B音動機
 */
const RARITY_LABELS = [5 => 'S', 4 => 'S', 3 => 'A', 2 => 'A', 1 => 'B'];

/**
 * レア度を表示用のラベル（S / A / B）にする
 */
function rarity_label(int $rarity): string
{
    return RARITY_LABELS[$rarity] ?? 'B';
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

    // 特殊演出（カットイン）: 結果の中にカットイン画像を持つカードがあれば、レア度が一番高いものを1回だけ流す
    // そのカードがめくれる直前に流すので、何枚目か（$cutin_index）も覚えておく
    $cutin = null;
    $cutin_index = null;
    foreach ($results as $i => &$item) {
        // カットイン画像: cutin_url（URL）を優先。無ければ cutin_image（images/cards/ のファイル名）
        $file = $item['cutin_image'] ?? null;
        if (empty($item['cutin_url'])) {
            if ($file === null || $file === '' || basename($file) !== $file) {
                continue;
            }
            $item['cutin_url'] = CARD_IMAGE_DIR . rawurlencode($file);
        }
        if ($cutin === null || (int)$item['rarity'] > (int)$cutin['rarity']) {
            $cutin = $item;
            $cutin_index = $i;
        }
    }
    unset($item);
    ?>
    <div class="card-grid<?= count($results) === 1 ? ' single' : '' ?>" data-max-rarity="<?= $max ?>">
        <?php foreach ($results as $i => $item): ?>
            <?php $rarity = (int)$item['rarity']; ?>
            <figure class="card rarity-<?= $rarity ?>" data-rarity="<?= $rarity ?>" data-card-id="<?= htmlspecialchars($item['card_id'] ?? '') ?>" style="--i: <?= $i ?>">
                <div class="card-flip">
                    <div class="card-face card-front">
                        <img src="<?= htmlspecialchars($item['image_url'] ?? card_image_url($item['image'])) ?>" alt="<?= htmlspecialchars($item['name']) ?>">
                    </div>
                    <div class="card-face card-back" aria-hidden="true"></div>
                </div>
                <!-- 画像の下にレア度とカード名 -->
                <figcaption>
                    <span class="rarity-badge rarity-<?= $rarity ?>"><?= rarity_label($rarity) ?></span>
                    <span class="card-name"><?= htmlspecialchars($item['name']) ?></span>
                    <?php if (!empty($item['kind_label'])): ?>
                        <span class="kind-badge"><?= htmlspecialchars($item['kind_label']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($item['is_pickup'])): ?>
                        <span class="grant-badge grant-pickup">PICK UP</span>
                    <?php endif; ?>
                    <?php $grant = $item['grant'] ?? null; ?>
                    <?php if ($grant !== null): ?>
                        <!-- 持っていないカードは NEW、持っていれば自動で凸、完凸ならショップメダルに変換 -->
                        <span class="grant-badge grant-<?= htmlspecialchars($grant['type']) ?>"><?= htmlspecialchars(match ($grant['type']) {
                            'new' => 'NEW',
                            'limit_break' => "{$grant['limit_break']}凸",
                            'medal' => "メダル+{$grant['medals']}",
                            default => '',
                        }) ?></span>
                    <?php endif; ?>
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
    <?php if ($cutin !== null): ?>
        <?php
        $cutin_rarity = (int)$cutin['rarity'];
        $cutin_url = $cutin['cutin_url'];
        ?>
        <div class="cutin rarity-<?= $cutin_rarity ?>" data-card-id="<?= htmlspecialchars($cutin['card_id']) ?>" data-index="<?= $cutin_index ?>"
             style="--cutin-img: url('<?= htmlspecialchars($cutin_url) ?>')" aria-hidden="true">
            <!-- 1・2段目: 画像の一部（足元 → 顔）を大きく映す -->
            <div class="cutin-closeup"></div>
            <!-- 3段目: 立ち絵全体 -->
            <div class="cutin-lines"></div>
            <div class="cutin-band"></div>
            <img class="cutin-img" src="<?= htmlspecialchars($cutin_url) ?>" alt="">
            <div class="cutin-text">
                <span class="rarity-badge rarity-<?= $cutin_rarity ?>"><?= rarity_label($cutin_rarity) ?></span>
                <span class="cutin-name"><?= htmlspecialchars($cutin['name']) ?></span>
            </div>
            <p class="cutin-hint">タップで進む</p>
        </div>
    <?php endif; ?>
    <div class="flash" aria-hidden="true"></div>
    <script src="/js/gacha.js" defer></script>
    <?php
}
