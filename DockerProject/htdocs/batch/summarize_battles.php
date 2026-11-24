<?php
/**
 * 資料06: 対戦履歴を集計して対戦ランキングを作り直すバッチ
 *
 * crontab（/etc/cron.d/battle-cron）から1分ごとに実行される:
 *   * * * * * root /usr/local/bin/php /var/www/html/batch/summarize_battles.php >> /var/log/cron.log 2>&1
 *
 * 手動で実行する場合:
 *   docker compose exec web php /var/www/html/batch/summarize_battles.php
 */

// htdocs の中にあるが、ブラウザ（Apache）からは実行させない。コマンドライン（cron）からのみ
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/../../lib/battle_lib.php';

function batch_log(string $message): void
{
    echo sprintf('[%s] [summarize_battles] %s', date('Y-m-d H:i:s'), $message), PHP_EOL;
}

try {
    // 前回の集計がまだ終わっていなければ、今回は何もしない（同時に2つ動かないように）
    $locked = (int)$pdo->query("SELECT GET_LOCK('summarize_battles', 0)")->fetchColumn();
    if ($locked !== 1) {
        batch_log('前回の集計が実行中のためスキップします');
        exit(0);
    }

    $start = microtime(true);
    $count = summarize_battles($pdo);
    batch_log(sprintf('ランキングを更新しました（%d 人, %.3f 秒）', $count, microtime(true) - $start));

    $pdo->query("SELECT RELEASE_LOCK('summarize_battles')");
} catch (Throwable $e) {
    batch_log('ERROR ' . $e->getMessage());
    exit(1);
}
