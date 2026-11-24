<?php
// 一括登録画面: 未登録のアイコンを表に並べ、行ごとにレア度・属性・タイプなどを選んで、まとめて登録する
// （1人ずつ登録するときは admin.php）
require_once __DIR__ . '/../../lib/admin_lib.php';

$user_id = GAME_USER_ID;
$icon_files = list_image_files(IMAGE_DIR_ICON);
$stand_files = list_image_files(IMAGE_DIR_STAND);
$engine_files = list_image_files(IMAGE_DIR_ENGINE);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rows = $_POST['rows'] ?? [];
    $registered = [];
    $errors = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
        if (!is_array($row) || empty($row['include'])) {
            continue;
        }
        $label = basename((string)($row['icon_image'] ?? ''));
        try {
            // スキル名が空なら「名前のスキル」にする
            if (trim((string)($row['skill_name'] ?? '')) === '') {
                $row['skill_name'] = trim((string)($row['name'] ?? '')) . 'のスキル';
            }
            $v = admin_validate_card($row, $icon_files, $stand_files);
            if ($v['icon_image'] === null) {
                throw new GameException('アイコンが選ばれていません。');
            }
            $sig_image = ($row['signature_image'] ?? '') ?: null;
            if (!empty($row['create_signature']) && $sig_image !== null && !in_array($sig_image, $engine_files, true)) {
                throw new GameException('専用音動機の画像が見つかりません。');
            }
            // 1人分（キャラ＋専用音動機）は、途中で失敗したらまとめて取り消す
            $pdo->beginTransaction();
            try {
                $card_id = admin_save_card_in_transaction($pdo, $v);
                if (!empty($row['create_signature'])) {
                    admin_create_signature_engine($pdo, $card_id, mb_substr("{$v['name']}のモチーフ音動機", 0, 50), $sig_image);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            $registered[] = $v['name'];
        } catch (GameException $e) {
            $errors[] = "{$label}: {$e->getMessage()}";
        }
    }
    if ($registered) {
        flash_set('notice', count($registered) . '人を登録しました（' . implode('・', $registered) . '）。');
    }
    foreach ($errors as $message) {
        flash_set('error', $message);
    }
    if (!$registered && !$errors) {
        flash_set('error', '登録する行にチェックを入れてください。');
    }
    header('Location: admin_bulk.php');
    exit;
}

$used_icons = array_filter($pdo->query("SELECT icon_image FROM cards")->fetchAll(PDO::FETCH_COLUMN));
$unregistered = array_values(array_diff($icon_files, $used_icons));

function bulk_options(array $options, string $selected): void
{
    foreach ($options as $value => $label) {
        printf('<option value="%s"%s>%s</option>', htmlspecialchars((string)$value), (string)$value === $selected ? ' selected' : '', htmlspecialchars($label));
    }
}

function bulk_image_options(array $files, ?string $selected): void
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
    <?php render_game_head('一括登録'); ?>
</head>
<body class="wide">
    <?php render_game_header($pdo, $user_id); ?>
    <h1>一括登録（キャラ）</h1>
    <?php render_flash(); ?>
    <p class="muted">
        <code>htdocs/images/zzz/icon/</code> にある未登録のアイコンを並べています。行ごとにレア度・属性・タイプなどを選び、
        登録する行にチェックを入れて「まとめて登録」を押してください。
        立ち絵・専用音動機は、同じ名前（1〜2文字の打ち間違いも含む）のファイルを自動で選んでいます。
        スキル名が空のときは「名前のスキル」になります。ステータスとスキルの数値は、レア度とタイプの標準値です。
    </p>
    <p class="links"><a href="admin.php">登録画面（1人ずつ・音動機・ピックアップ）に戻る</a></p>

    <?php if (!$unregistered): ?>
        <p class="notice">未登録のアイコンはありません。</p>
    <?php else: ?>
        <form method="post" id="bulk-form">
            <div class="bulk-tools">
                <label class="check"><input type="checkbox" id="check-all" checked> すべて選択</label>
                <span class="muted">選んだ行をまとめて:</span>
                <select data-bulk="rarity"><option value="">レア度…</option><?php bulk_options([CARD_RARITY_S => 'S級', CARD_RARITY_A => 'A級'], ''); ?></select>
                <select data-bulk="attribute"><option value="">属性…</option><?php bulk_options(ATTRIBUTES, ''); ?></select>
                <select data-bulk="role"><option value="">タイプ…</option><?php bulk_options(ROLES, ''); ?></select>
                <select data-bulk="pool"><option value="">排出…</option><?php bulk_options(POOL_LABELS, ''); ?></select>
            </div>
            <div class="bulk-scroll">
                <table class="admin-table bulk-table">
                    <thead>
                        <tr>
                            <th>登録</th><th>アイコン</th><th>名前</th><th>レア</th><th>属性</th><th>タイプ</th>
                            <th>スキル名</th><th>スキルの効果</th><th>立ち絵</th><th>専用音動機</th><th>排出</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($unregistered as $i => $icon): ?>
                            <?php
                            $key = image_key($icon);
                            $stand = find_matching_image($key, $stand_files);
                            $engine = find_matching_image($key, $engine_files);
                            ?>
                            <tr class="bulk-row">
                                <td><input type="checkbox" name="rows[<?= $i ?>][include]" value="1" class="row-check" checked aria-label="この行を登録"></td>
                                <td>
                                    <img class="thumb" src="<?= htmlspecialchars(image_path_url($icon) ?? '') ?>" alt="">
                                    <input type="hidden" name="rows[<?= $i ?>][icon_image]" value="<?= htmlspecialchars($icon) ?>">
                                    <input type="hidden" name="rows[<?= $i ?>][is_active]" value="1">
                                </td>
                                <td><input type="text" name="rows[<?= $i ?>][name]" value="<?= htmlspecialchars(pathinfo($icon, PATHINFO_FILENAME)) ?>" maxlength="50" required></td>
                                <td><select name="rows[<?= $i ?>][rarity]" data-field="rarity"><?php bulk_options([CARD_RARITY_S => 'S', CARD_RARITY_A => 'A'], (string)CARD_RARITY_A); ?></select></td>
                                <td><select name="rows[<?= $i ?>][attribute]" data-field="attribute"><?php bulk_options(ATTRIBUTES, 'physical'); ?></select></td>
                                <td><select name="rows[<?= $i ?>][role]" data-field="role"><?php bulk_options(ROLES, 'attacker'); ?></select></td>
                                <td><input type="text" name="rows[<?= $i ?>][skill_name]" maxlength="50" placeholder="（空欄可）"></td>
                                <td><select name="rows[<?= $i ?>][skill_effect]" data-field="skill_effect"><?php bulk_options(array_map(fn ($t) => $t['label'], SKILL_TEMPLATES), ROLE_DEFAULT_SKILL['attacker']); ?></select></td>
                                <td>
                                    <select name="rows[<?= $i ?>][stand_image]"><?php bulk_image_options($stand_files, $stand); ?></select>
                                    <?php if ($stand !== null && image_key($stand) !== $key): ?><span class="hint" title="ファイル名が少し違うものを選んでいます">≈</span><?php endif; ?>
                                </td>
                                <td>
                                    <label class="check"><input type="checkbox" name="rows[<?= $i ?>][create_signature]" value="1" <?= $engine ? 'checked' : '' ?>> 作る</label>
                                    <select name="rows[<?= $i ?>][signature_image]"><?php bulk_image_options($engine_files, $engine); ?></select>
                                </td>
                                <td><select name="rows[<?= $i ?>][pool]" data-field="pool"><?php bulk_options(['standard' => '常設', 'limited' => '限定'], 'standard'); ?></select></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p><button type="submit" class="btn-battle">まとめて登録（<span id="checked-count"><?= count($unregistered) ?></span>人）</button></p>
        </form>
    <?php endif; ?>

    <script>
        (function () {
            const form = document.getElementById('bulk-form');
            if (!form) {
                return;
            }
            // タイプごとのおすすめスキル（タイプを変えたらスキルの効果も合わせる）
            const roleSkill = <?= json_encode(ROLE_DEFAULT_SKILL) ?>;
            const rows = [...form.querySelectorAll('.bulk-row')];
            const checks = () => rows.filter(r => r.querySelector('.row-check').checked);
            const updateCount = () => { document.getElementById('checked-count').textContent = checks().length; };

            const setRole = (row, role) => {
                row.querySelector('[data-field=role]').value = role;
                row.querySelector('[data-field=skill_effect]').value = roleSkill[role];
            };
            rows.forEach(row => {
                row.querySelector('[data-field=role]').addEventListener('change', e => setRole(row, e.target.value));
                row.querySelector('.row-check').addEventListener('change', updateCount);
            });
            document.getElementById('check-all').addEventListener('change', e => {
                rows.forEach(r => (r.querySelector('.row-check').checked = e.target.checked));
                updateCount();
            });
            // 上の「まとめて」選ぶと、チェックした行すべてに反映
            form.querySelectorAll('[data-bulk]').forEach(sel => {
                sel.addEventListener('change', () => {
                    if (!sel.value) {
                        return;
                    }
                    checks().forEach(row => {
                        if (sel.dataset.bulk === 'role') {
                            setRole(row, sel.value);
                        } else {
                            row.querySelector(`[data-field=${sel.dataset.bulk}]`).value = sel.value;
                        }
                    });
                    sel.value = '';
                });
            });
        })();
    </script>
</body>
</html>
