<?php
// 登録画面: htdocs/images/zzz/ の画像（アイコン・立ち絵・音動機）を使って、キャラ・音動機を登録し、ガチャのピックアップを設定する
// ※ ログイン機能が無いので、デバッグ画面と同じく誰でも開ける（本番では管理者だけにする）
require_once __DIR__ . '/../../lib/admin_lib.php';

$user_id = GAME_USER_ID;
$icon_files = list_image_files(IMAGE_DIR_ICON);
$stand_files = list_image_files(IMAGE_DIR_STAND);
$engine_files = list_image_files(IMAGE_DIR_ENGINE);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $back = 'admin.php';
    try {
        if ($action === 'save_card') {
            $card_id = filter_input(INPUT_POST, 'card_id', FILTER_VALIDATE_INT) ?: null;
            $v = admin_validate_card($_POST, $icon_files, $stand_files);
            $is_new = $card_id === null;
            $card_id = admin_save_card($pdo, $v, $card_id);
            $msg = "{$v['name']} を" . ($is_new ? '登録' : '更新') . 'しました。';
            // 専用音動機（同じファイル名の音動機）も登録
            $sig_image = ($_POST['signature_image'] ?? '') ?: null;
            if ($is_new && !empty($_POST['create_signature'])) {
                if ($sig_image !== null && !in_array($sig_image, $engine_files, true)) {
                    throw new GameException('専用音動機の画像が見つかりません。');
                }
                $sig_name = trim((string)($_POST['signature_name'] ?? '')) ?: "{$v['name']}のモチーフ音動機";
                admin_create_signature_engine($pdo, $card_id, mb_substr($sig_name, 0, 50), $sig_image);
                $msg .= "専用音動機「{$sig_name}」も登録しました。";
            }
            flash_set('notice', $msg);
            $back .= '#cards';
        } elseif ($action === 'save_engine') {
            $engine_id = filter_input(INPUT_POST, 'engine_id', FILTER_VALIDATE_INT) ?: null;
            $v = admin_validate_engine($pdo, $_POST, $engine_files);
            admin_save_engine($pdo, $v, $engine_id);
            flash_set('notice', "音動機「{$v['name']}」を" . ($engine_id ? '更新' : '登録') . 'しました。');
            $back .= '#engines';
        } elseif ($action === 'toggle_card') {
            $pdo->prepare("UPDATE cards SET is_active = NOT is_active WHERE id = ?")->execute([(int)($_POST['card_id'] ?? 0)]);
            $back .= '#cards';
        } elseif ($action === 'toggle_engine') {
            $pdo->prepare("UPDATE w_engines SET is_active = NOT is_active WHERE id = ?")->execute([(int)($_POST['engine_id'] ?? 0)]);
            $back .= '#engines';
        } elseif ($action === 'banners') {
            admin_set_banner_pickups($pdo,
                filter_input(INPUT_POST, 'pickup_card_id', FILTER_VALIDATE_INT) ?: null,
                filter_input(INPUT_POST, 'pickup_engine_id', FILTER_VALIDATE_INT) ?: null);
            flash_set('notice', 'ガチャのピックアップを設定しました。');
            $back .= '#banners';
        }
    } catch (GameException $e) {
        flash_set('error', $e->getMessage());
    }
    header('Location: ' . $back);
    exit;
}

$cards = $pdo->query("
    SELECT c.*, s.name AS skill_name, s.effect AS skill_effect, we.name AS signature_engine
    FROM cards c JOIN skills s ON s.id = c.skill_id
    LEFT JOIN w_engines we ON we.signature_card_id = c.id
    ORDER BY c.is_active DESC, c.rarity DESC, c.id
")->fetchAll();
$engines = $pdo->query("
    SELECT we.*, c.name AS signature_card_name FROM w_engines we LEFT JOIN cards c ON c.id = we.signature_card_id
    ORDER BY we.is_active DESC, we.rarity DESC, we.id
")->fetchAll();
$banners = fetch_banners($pdo);

$used_icons = array_filter(array_column($cards, 'icon_image'));
$used_engine_images = array_filter(array_column($engines, 'image'));
$unregistered_icons = array_values(array_diff($icon_files, $used_icons));
$unregistered_engines = array_values(array_diff($engine_files, $used_engine_images));

// ---- 編集するキャラ（?card=ID）・新しく登録するキャラ（?new=アイコンのパス）----
$form = null;
if ($id = filter_input(INPUT_GET, 'card', FILTER_VALIDATE_INT)) {
    foreach ($cards as $c) {
        if ((int)$c['id'] === $id) {
            $form = $c;
        }
    }
} elseif (($new = $_GET['new'] ?? '') !== '' || isset($_GET['new_card'])) {
    $icon = in_array($new, $icon_files, true) ? $new : null;
    $key = image_key($icon);
    $form = [
        'id' => null, 'name' => $icon ? pathinfo($icon, PATHINFO_FILENAME) : '', 'rarity' => CARD_RARITY_A,
        'attribute' => 'physical', 'role' => 'attacker', 'pool' => 'standard', 'is_active' => 1,
        'icon_image' => $icon, 'stand_image' => find_matching_image($key, $stand_files),
        'skill_name' => '', 'skill_effect' => ROLE_DEFAULT_SKILL['attacker'],
        'signature_image' => find_matching_image($key, $engine_files),
    ];
}

// ---- 編集する音動機（?engine=ID）・新しく登録する音動機（?new_engine=画像のパス）----
$engine_form = null;
if ($id = filter_input(INPUT_GET, 'engine', FILTER_VALIDATE_INT)) {
    foreach ($engines as $e) {
        if ((int)$e['id'] === $id) {
            $engine_form = $e;
        }
    }
} elseif (($new = $_GET['new_engine'] ?? '') !== '') {
    $image = in_array($new, $engine_files, true) ? $new : null;
    // 同じファイル名のキャラがいれば、そのキャラ専用の候補にする
    $signature = null;
    foreach ($cards as $c) {
        if ($image && image_key($c['icon_image']) === image_key($image)) {
            $signature = (int)$c['id'];
        }
    }
    $engine_form = [
        'id' => null, 'name' => $image ? pathinfo($image, PATHINFO_FILENAME) : '', 'rarity' => $signature ? ENGINE_RARITY_S : ENGINE_RARITY_B,
        'image' => $image, 'sub_stat' => 'atk', 'effect_type' => 'atk_pct', 'effect_attribute' => null, 'effect_value' => '',
        'signature_card_id' => $signature, 'pool' => 'standard', 'is_active' => 1,
    ];
}

function image_options(array $files, ?string $selected): void
{
    echo '<option value="">（なし）</option>';
    foreach ($files as $f) {
        printf('<option value="%s"%s>%s</option>', htmlspecialchars($f), $f === $selected ? ' selected' : '', htmlspecialchars(basename($f)));
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <?php render_game_head('登録画面'); ?>
</head>
<body>
    <?php render_game_header($pdo, $user_id); ?>
    <h1>登録画面（キャラ・音動機・ガチャ）</h1>
    <?php render_flash(); ?>
    <p class="muted">
        <code>htdocs/images/zzz/</code> の <code>icon</code>（アイコン）・<code>standing pose</code>（立ち絵）・<code>sound engine</code>（音動機）の画像を使って登録します。
        ステータス・スキルの数値は、レア度とタイプから標準値で決まります。
        <strong>ファイル名がキャラ名と同じ音動機</strong>は、そのキャラの専用音動機として登録できます。
    </p>
    <nav class="links"><a href="admin_bulk.php">一括登録</a> <a href="#unregistered">未登録の画像</a> <a href="#cards">キャラ</a> <a href="#engines">音動機</a> <a href="#banners">ガチャのピックアップ</a></nav>

    <?php if ($form !== null): ?>
        <section class="panel form-panel" id="card-form">
            <h2><?= $form['id'] ? 'キャラの編集: ' . htmlspecialchars($form['name']) : 'キャラの登録' ?></h2>
            <form method="post" class="admin-form">
                <input type="hidden" name="action" value="save_card">
                <input type="hidden" name="card_id" value="<?= (int)$form['id'] ?: '' ?>">
                <div class="form-images">
                    <figure><img id="preview-icon" src="<?= htmlspecialchars(image_path_url($form['icon_image']) ?? '/images/cards/no_image.svg') ?>" alt=""><figcaption>アイコン</figcaption></figure>
                    <figure><img id="preview-stand" src="<?= htmlspecialchars(image_path_url($form['stand_image']) ?? '/images/cards/no_image.svg') ?>" alt=""><figcaption>立ち絵</figcaption></figure>
                </div>
                <label>名前 <input type="text" name="name" value="<?= htmlspecialchars($form['name']) ?>" maxlength="50" required></label>
                <label>レア度
                    <select name="rarity">
                        <option value="<?= CARD_RARITY_S ?>" <?= (int)$form['rarity'] === CARD_RARITY_S ? 'selected' : '' ?>>S級</option>
                        <option value="<?= CARD_RARITY_A ?>" <?= (int)$form['rarity'] === CARD_RARITY_A ? 'selected' : '' ?>>A級</option>
                    </select>
                </label>
                <label>属性
                    <select name="attribute">
                        <?php foreach (ATTRIBUTES as $k => $label): ?><option value="<?= $k ?>" <?= $form['attribute'] === $k ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label>タイプ
                    <select name="role" id="role-select">
                        <?php foreach (ROLES as $k => $label): ?><option value="<?= $k ?>" <?= $form['role'] === $k ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label>アイコン <select name="icon_image" data-preview="preview-icon"><?php image_options($icon_files, $form['icon_image']); ?></select></label>
                <label>立ち絵 <select name="stand_image" data-preview="preview-stand"><?php image_options($stand_files, $form['stand_image']); ?></select></label>
                <label>スキル名 <input type="text" name="skill_name" value="<?= htmlspecialchars($form['skill_name']) ?>" maxlength="50" required></label>
                <label>スキルの効果
                    <select name="skill_effect">
                        <?php foreach (SKILL_TEMPLATES as $k => $t): ?><option value="<?= $k ?>" <?= $form['skill_effect'] === $k ? 'selected' : '' ?>><?= $t['label'] ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label>ガチャの排出
                    <select name="pool">
                        <?php foreach (POOL_LABELS as $k => $label): ?><option value="<?= $k ?>" <?= $form['pool'] === $k ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label class="check"><input type="checkbox" name="is_active" value="1" <?= $form['is_active'] ? 'checked' : '' ?>> ガチャから出す</label>

                <?php if (!$form['id']): ?>
                    <fieldset>
                        <legend>専用音動機（モチーフ音動機）</legend>
                        <label class="check"><input type="checkbox" name="create_signature" value="1" <?= $form['signature_image'] ? 'checked' : '' ?>> このキャラの専用音動機も登録する</label>
                        <label>音動機の画像 <select name="signature_image"><?php image_options($engine_files, $form['signature_image']); ?></select></label>
                        <label>音動機の名前 <input type="text" name="signature_name" maxlength="50" placeholder="（空欄なら「○○のモチーフ音動機」）"></label>
                        <p class="muted">レア度はキャラに合わせます（S級キャラ → S級音動機、A級キャラ → A級音動機）。タイプに合ったサブステータス・効果と、専用効果（スキルの効果量アップ）が付きます。</p>
                    </fieldset>
                <?php endif; ?>
                <p><button type="submit"><?= $form['id'] ? '更新する' : '登録する' ?></button> <a href="admin.php">キャンセル</a></p>
            </form>
        </section>
    <?php endif; ?>

    <?php if ($engine_form !== null): ?>
        <section class="panel form-panel" id="engine-form">
            <h2><?= $engine_form['id'] ? '音動機の編集: ' . htmlspecialchars($engine_form['name']) : '音動機の登録' ?></h2>
            <form method="post" class="admin-form">
                <input type="hidden" name="action" value="save_engine">
                <input type="hidden" name="engine_id" value="<?= (int)$engine_form['id'] ?: '' ?>">
                <div class="form-images">
                    <figure><img id="preview-engine" src="<?= htmlspecialchars(engine_image_url($engine_form['image'])) ?>" alt=""><figcaption>画像</figcaption></figure>
                </div>
                <label>名前 <input type="text" name="name" value="<?= htmlspecialchars($engine_form['name']) ?>" maxlength="50" required></label>
                <label>レア度
                    <select name="rarity">
                        <?php foreach ([ENGINE_RARITY_S => 'S級', ENGINE_RARITY_A => 'A級', ENGINE_RARITY_B => 'B級'] as $k => $label): ?>
                            <option value="<?= $k ?>" <?= (int)$engine_form['rarity'] === $k ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>画像 <select name="image" data-preview="preview-engine"><?php image_options($engine_files, $engine_form['image']); ?></select></label>
                <label>サブステータス
                    <select name="sub_stat">
                        <?php foreach (['hp', 'atk', 'def'] as $k): ?><option value="<?= $k ?>" <?= $engine_form['sub_stat'] === $k ? 'selected' : '' ?>><?= STAT_LABELS[$k] ?> %</option><?php endforeach; ?>
                    </select>
                </label>
                <label>効果
                    <select name="effect_type">
                        <?php foreach (ENGINE_EFFECT_LABELS as $k => $label): ?>
                            <option value="<?= $k ?>" <?= $engine_form['effect_type'] === $k ? 'selected' : '' ?>><?= $k === 'attr_dmg' ? '属性汎用（同じ属性なら与ダメージ）' : $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>属性（属性汎用のとき）
                    <select name="effect_attribute">
                        <option value="">—</option>
                        <?php foreach (ATTRIBUTES as $k => $label): ?><option value="<?= $k ?>" <?= $engine_form['effect_attribute'] === $k ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label>効果量 <input type="number" name="effect_value" value="<?= htmlspecialchars((string)$engine_form['effect_value']) ?>" min="1" max="100" placeholder="空欄なら標準値"></label>
                <label>専用にするキャラ
                    <select name="signature_card_id">
                        <option value="">（汎用）</option>
                        <?php foreach ($cards as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)$engine_form['signature_card_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label>ガチャの排出
                    <select name="pool">
                        <?php foreach (POOL_LABELS as $k => $label): ?><option value="<?= $k ?>" <?= $engine_form['pool'] === $k ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label class="check"><input type="checkbox" name="is_active" value="1" <?= $engine_form['is_active'] ? 'checked' : '' ?>> ガチャから出す</label>
                <p><button type="submit"><?= $engine_form['id'] ? '更新する' : '登録する' ?></button> <a href="admin.php#engines">キャンセル</a></p>
            </form>
        </section>
    <?php endif; ?>

    <section class="panel" id="unregistered">
        <h2>未登録の画像</h2>
        <h3>アイコン（<?= count($unregistered_icons) ?>）</h3>
        <div class="file-grid">
            <?php foreach ($unregistered_icons as $f): ?>
                <a class="file-chip" href="admin.php?new=<?= rawurlencode($f) ?>#card-form">
                    <img src="<?= htmlspecialchars(image_path_url($f) ?? '') ?>" alt=""><?= htmlspecialchars(pathinfo($f, PATHINFO_FILENAME)) ?>
                </a>
            <?php endforeach; ?>
            <?php if (!$unregistered_icons): ?><p class="muted">ありません。</p><?php endif; ?>
        </div>
        <h3>音動機（<?= count($unregistered_engines) ?>）</h3>
        <div class="file-grid">
            <?php foreach ($unregistered_engines as $f): ?>
                <a class="file-chip" href="admin.php?new_engine=<?= rawurlencode($f) ?>#engine-form">
                    <img src="<?= htmlspecialchars(image_path_url($f) ?? '') ?>" alt=""><?= htmlspecialchars(pathinfo($f, PATHINFO_FILENAME)) ?>
                </a>
            <?php endforeach; ?>
            <?php if (!$unregistered_engines): ?><p class="muted">ありません。</p><?php endif; ?>
        </div>
        <p class="muted">
            キャラを先に登録すると、同じファイル名の音動機が専用音動機の候補になります。
            立ち絵・音動機のファイル名が少し違う（1〜2文字の打ち間違い）ときも、近いものを候補にします。
        </p>
        <p class="links">
            <a href="admin_bulk.php"><strong>未登録のアイコンをまとめて登録（一括登録）</strong></a>
            <a href="admin.php?new_card=1#card-form">画像を選ばずにキャラを登録</a>
        </p>
    </section>

    <section class="panel" id="cards">
        <h2>キャラ（<?= count($cards) ?>）</h2>
        <table class="admin-table">
            <tr><th></th><th>名前</th><th>レア</th><th>属性 / タイプ</th><th>スキル</th><th>専用音動機</th><th>排出</th><th></th></tr>
            <?php foreach ($cards as $c): ?>
                <tr class="<?= $c['is_active'] ? '' : 'inactive' ?>">
                    <td><img class="thumb" src="<?= htmlspecialchars(card_icon_url($c)) ?>" alt=""></td>
                    <td><?= htmlspecialchars($c['name']) ?></td>
                    <td><span class="rarity-badge rarity-<?= (int)$c['rarity'] ?>"><?= rarity_label((int)$c['rarity']) ?></span></td>
                    <td><?= attribute_badge($c['attribute']) ?> <?= htmlspecialchars(role_label($c['role'])) ?></td>
                    <td><?= htmlspecialchars($c['skill_name']) ?></td>
                    <td><?= htmlspecialchars($c['signature_engine'] ?? '—') ?></td>
                    <td><?= $c['pool'] === 'limited' ? '限定' : '常設' ?><?= $c['is_active'] ? '' : '（出ない）' ?></td>
                    <td class="actions">
                        <a href="admin.php?card=<?= (int)$c['id'] ?>#card-form">編集</a>
                        <form method="post"><input type="hidden" name="action" value="toggle_card"><input type="hidden" name="card_id" value="<?= (int)$c['id'] ?>">
                            <button type="submit" class="btn-small"><?= $c['is_active'] ? 'ガチャから外す' : 'ガチャに出す' ?></button></form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    </section>

    <section class="panel" id="engines">
        <h2>音動機（<?= count($engines) ?>）</h2>
        <table class="admin-table">
            <tr><th></th><th>名前</th><th>レア</th><th>効果</th><th>専用</th><th>排出</th><th></th></tr>
            <?php foreach ($engines as $e): ?>
                <tr class="<?= $e['is_active'] ? '' : 'inactive' ?>">
                    <td><img class="thumb" src="<?= htmlspecialchars(engine_image_url($e['image'])) ?>" alt=""></td>
                    <td><?= htmlspecialchars($e['name']) ?></td>
                    <td><span class="rarity-badge rarity-<?= (int)$e['rarity'] ?>"><?= rarity_label((int)$e['rarity']) ?></span></td>
                    <td><?= htmlspecialchars(STAT_LABELS[$e['sub_stat']]) ?> +<?= (int)$e['sub_value'] ?>% ／ <?= htmlspecialchars(engine_effect_description($e)) ?></td>
                    <td><?= htmlspecialchars($e['signature_card_name'] ?? '汎用') ?></td>
                    <td><?= $e['pool'] === 'limited' ? '限定' : '常設' ?><?= $e['is_active'] ? '' : '（出ない）' ?></td>
                    <td class="actions">
                        <a href="admin.php?engine=<?= (int)$e['id'] ?>#engine-form">編集</a>
                        <form method="post"><input type="hidden" name="action" value="toggle_engine"><input type="hidden" name="engine_id" value="<?= (int)$e['id'] ?>">
                            <button type="submit" class="btn-small"><?= $e['is_active'] ? 'ガチャから外す' : 'ガチャに出す' ?></button></form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    </section>

    <section class="panel" id="banners">
        <h2>ガチャのピックアップ</h2>
        <form method="post" class="admin-form">
            <input type="hidden" name="action" value="banners">
            <?php $char_banner = current(array_filter($banners, fn ($b) => $b['type'] === 'char')); $engine_banner = current(array_filter($banners, fn ($b) => $b['type'] === 'engine')); ?>
            <label>Sキャラ ピックアップ
                <select name="pickup_card_id">
                    <option value="">（なし）</option>
                    <?php foreach ($cards as $c): if ((int)$c['rarity'] !== CARD_RARITY_S) { continue; } ?>
                        <option value="<?= (int)$c['id'] ?>" <?= (int)$char_banner['pickup_card_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?><?= $c['pool'] === 'limited' ? '（限定）' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>モチーフ音動機 ピックアップ
                <select name="pickup_engine_id">
                    <option value="">（なし）</option>
                    <?php foreach ($engines as $e): if ((int)$e['rarity'] !== ENGINE_RARITY_S) { continue; } ?>
                        <option value="<?= (int)$e['id'] ?>" <?= (int)$engine_banner['pickup_engine_id'] === (int)$e['id'] ? 'selected' : '' ?>><?= htmlspecialchars($e['name']) ?><?= $e['signature_card_name'] ? "（{$e['signature_card_name']}専用）" : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <p class="muted">ピックアップのキャラ・音動機は、限定（ピックアップのみ）にしておくと、すり抜けや常設ガチャでは出なくなります。天井のカウントは、ピックアップを変えても引き継がれます。</p>
            <p><button type="submit">設定する</button></p>
        </form>
    </section>

    <script>
        // 画像を選んだらプレビューを切り替える
        document.querySelectorAll('select[data-preview]').forEach(sel => {
            sel.addEventListener('change', () => {
                const img = document.getElementById(sel.dataset.preview);
                img.src = sel.value ? '/images/' + sel.value.split('/').map(encodeURIComponent).join('/') : '/images/cards/no_image.svg';
            });
        });
    </script>
</body>
</html>
