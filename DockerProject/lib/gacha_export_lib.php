<?php
// 資料05: ガチャ結果の JSON 化・ファイル出力（JSON / CSV）
require_once __DIR__ . '/card_lib.php';

/**
 * 出力先ディレクトリ（任意のディレクトリ）
 *
 * 公開フォルダ（/var/www/html = htdocs）の外に置き、ブラウザから直接ダウンロードされないようにする。
 * docker-compose.yml で ./output をここにマウントしているので、ホストの DockerProject/output/ からも見える。
 * mng コンテナの cron がここから storage ディレクトリへファイルを移動する（mng/scripts/move_gacha_output.sh）。
 */
const GACHA_EXPORT_DIR      = '/var/www/output';
const GACHA_EXPORT_JSON_DIR = GACHA_EXPORT_DIR . '/json';
const GACHA_EXPORT_CSV_DIR  = GACHA_EXPORT_DIR . '/csv';

/** json_encode の共通オプション（日本語・スラッシュをエスケープせず、改行・インデント付きで読みやすく） */
const GACHA_JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

/**
 * ガチャ結果（DB から取った行の配列）を、JSON にする形の配列へ整形する
 *
 * 資料の例と同じく item_id / weight / item_name / item_image を基本に、
 * レア度・カットイン画像も含めた「全項目」版。
 */
function gacha_results_to_items(array $results): array
{
    $items = [];
    foreach ($results as $row) {
        $items[] = [
            'item_id'     => (int)$row['card_id'],
            'weight'      => (int)$row['weight'],
            'item_name'   => $row['name'],
            'rarity'      => (int)$row['rarity'],
            'item_image'  => $row['image'],
            'cutin_image' => $row['cutin_image'] ?? null,
        ];
    }
    return $items;
}

/**
 * 応用: クライアントのガチャ演出に必要な項目だけに絞った形
 *
 *   - weight（排出の重み）はサーバー側の情報なのでクライアントには渡さない
 *   - 画像はファイル名ではなく、そのまま <img src> に使える URL にする
 *   - レア度は演出の分岐に使うので数値と表示用ラベルの両方を持たせる
 *   - order（何枚目か）があれば、クライアントはめくる順番どおりに演出できる
 */
function gacha_results_to_client_items(array $results): array
{
    $items = [];
    foreach (array_values($results) as $i => $row) {
        $rarity = (int)$row['rarity'];
        $item = [
            'order'        => $i + 1,
            'item_id'      => (int)$row['card_id'],
            'item_name'    => $row['name'],
            'rarity'       => $rarity,
            'rarity_label' => rarity_label($rarity),
            'image_url'    => card_image_url($row['image']),
        ];
        $cutin = $row['cutin_image'] ?? null;
        if ($cutin !== null && $cutin !== '' && basename($cutin) === $cutin) {
            $item['cutin_image_url'] = CARD_IMAGE_DIR . rawurlencode($cutin);
        }
        $items[] = $item;
    }
    return $items;
}

/**
 * 配列を JSON 文字列にする（画面表示・ファイル出力で共通）
 */
function gacha_to_json(array $data): string
{
    return json_encode($data, GACHA_JSON_FLAGS);
}

/** CSV の列（見出し => gacha_results_to_items() のキー） */
const GACHA_CSV_COLUMNS = [
    'item_id'     => 'item_id',
    'weight'      => 'weight',
    'item_name'   => 'item_name',
    'rarity'      => 'rarity',
    'item_image'  => 'item_image',
    'cutin_image' => 'cutin_image',
];

/**
 * ガチャ結果を CSV 文字列にする
 * Excel で開いても文字化けしないよう、先頭に UTF-8 の BOM を付ける
 */
function gacha_to_csv(array $items): string
{
    $fp = fopen('php://temp', 'r+');
    fwrite($fp, "\xEF\xBB\xBF");
    fputcsv($fp, array_keys(GACHA_CSV_COLUMNS), ',', '"', '');
    foreach ($items as $item) {
        $row = [];
        foreach (GACHA_CSV_COLUMNS as $key) {
            $row[] = $item[$key] ?? '';
        }
        fputcsv($fp, $row, ',', '"', '');
    }
    rewind($fp);
    $csv = stream_get_contents($fp);
    fclose($fp);
    return $csv;
}

/**
 * 出力ファイル名の元（例: gacha_result_20251010_005718）
 */
function gacha_export_basename(?int $timestamp = null): string
{
    return 'gacha_result_' . date('Ymd_His', $timestamp ?? time());
}

/**
 * 文字列をファイルに保存し、保存したパスを返す
 *
 * - 出力先ディレクトリが無ければ作る
 * - 同じ秒に2回引いても上書きしないよう、既にあれば _2, _3 … を付ける
 * - 一時ファイル（.tmp）に書いてから rename する。
 *   mng コンテナの cron が書き込み途中のファイルを移動してしまわないようにするため
 *   （同じディレクトリ内の rename は一瞬で切り替わる。移動スクリプトは *.tmp を無視する）
 */
function gacha_write_export_file(string $dir, string $basename, string $ext, string $content): string
{
    if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException("出力先ディレクトリを作成できません: {$dir}");
    }
    if (!is_writable($dir)) {
        throw new RuntimeException("出力先ディレクトリに書き込めません: {$dir}");
    }

    for ($n = 1; $n <= 100; $n++) {
        $path = sprintf('%s/%s%s.%s', $dir, $basename, $n === 1 ? '' : "_{$n}", $ext);
        $tmp = $path . '.tmp';

        // 'x' モード: 既にファイルがあれば失敗する（他のリクエストと名前がぶつからない）
        $fp = @fopen($tmp, 'x');
        if ($fp === false) {
            continue;
        }
        if (file_exists($path)) {
            fclose($fp);
            unlink($tmp);
            continue;
        }

        $ok = fwrite($fp, $content) === strlen($content);
        fclose($fp);
        if (!$ok || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("ファイルの書き込みに失敗しました: {$path}");
        }
        return $path;
    }
    throw new RuntimeException('出力ファイル名を決められませんでした。');
}

/**
 * ガチャ結果を JSON・CSV ファイルに出力し、保存したパスを返す
 *   ['json' => '/var/www/output/json/gacha_result_....json', 'csv' => '...csv']
 */
function export_gacha_results(array $results, ?int $timestamp = null): array
{
    $items = gacha_results_to_items($results);
    $basename = gacha_export_basename($timestamp);

    return [
        'json' => gacha_write_export_file(GACHA_EXPORT_JSON_DIR, $basename, 'json', gacha_to_json($items) . "\n"),
        'csv'  => gacha_write_export_file(GACHA_EXPORT_CSV_DIR, $basename, 'csv', gacha_to_csv($items)),
    ];
}
