#!/bin/bash
# ガチャ結果ファイル（JSON / CSV）を web コンテナの出力先から storage ディレクトリへ移動する
#
#   移動元: /var/www/output/{json,csv}/gacha_result_YYYYMMDD_HHMMSS.{json,csv}
#   移動先: /storage/gacha_result/{json,csv}/YYYYMMDD/
#
# crontab から1分ごとに実行される。手動で実行する場合:
#   docker compose exec mng move_gacha_output.sh
set -u

SRC_DIR="${SRC_DIR:-/var/www/output}"
DEST_DIR="${DEST_DIR:-/storage/gacha_result}"
LOCK_FILE="/tmp/move_gacha_output.lock"

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [move_gacha_output] $*"
}

# 前回の実行がまだ終わっていなければ、今回は何もしない（同時に動いて二重に移動しないように）
exec 9>"$LOCK_FILE"
if ! flock -n 9; then
    log "前回の処理が実行中のためスキップします"
    exit 0
fi

moved=0
failed=0

for type in json csv; do
    src="$SRC_DIR/$type"
    [ -d "$src" ] || continue

    # 書き込み途中の一時ファイル（*.tmp）は対象にしない（PHP は .tmp に書いてから rename する）
    while IFS= read -r -d '' file; do
        name="$(basename "$file")"

        # ファイル名の日付（gacha_result_20251010_005718.json → 20251010）ごとにフォルダを分ける
        day="$(printf '%s' "$name" | sed -n 's/^gacha_result_\([0-9]\{8\}\)_.*/\1/p')"
        dest="$DEST_DIR/$type/${day:-unknown}"

        if ! mkdir -p "$dest"; then
            log "ERROR 保存先を作成できません: $dest"
            failed=$((failed + 1))
            continue
        fi
        if [ -e "$dest/$name" ]; then
            log "WARN 同じ名前のファイルが保存先にあるため移動しません: $dest/$name"
            failed=$((failed + 1))
            continue
        fi

        if mv "$file" "$dest/$name"; then
            log "移動: $file -> $dest/$name"
            moved=$((moved + 1))
        else
            log "ERROR 移動に失敗しました: $file"
            failed=$((failed + 1))
        fi
    done < <(find "$src" -maxdepth 1 -type f -name 'gacha_result_*' ! -name '*.tmp' -print0 | sort -z)
done

# 何もなかったときはログを出さない（1分ごとにログが埋まらないように）
if [ $((moved + failed)) -gt 0 ]; then
    log "完了: 移動 ${moved} 件 / 失敗 ${failed} 件"
fi

[ "$failed" -eq 0 ]
