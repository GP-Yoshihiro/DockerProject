#!/bin/bash
# mng コンテナの起動処理
set -e

# 出力先・保存先のディレクトリを用意する
mkdir -p /var/www/output/json /var/www/output/csv /storage/gacha_result

# Linux ホストでは bind mount したディレクトリが root 所有で作られ、
# web コンテナの Apache（www-data）が書き込めないことがあるので、書き込み可能にしておく
chmod 777 /var/www/output /var/www/output/json /var/www/output/csv

echo "[$(date '+%Y-%m-%d %H:%M:%S')] [mng] cron を起動します"
exec cron -f
