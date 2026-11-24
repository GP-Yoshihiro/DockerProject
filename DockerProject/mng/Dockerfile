# 管理用（mng）コンテナ
# web コンテナが出力したガチャ結果ファイル（JSON / CSV）を、cron で storage ディレクトリへ移動する
FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive
ENV TZ=Asia/Tokyo

RUN apt-get update \
  && apt-get install -y --no-install-recommends cron tzdata vim \
  && ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone \
  && rm -rf /var/lib/apt/lists/*

COPY scripts/move_gacha_output.sh /usr/local/bin/move_gacha_output.sh
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
COPY crontab /tmp/crontab
# Windows で編集して改行が CRLF になっていても動くように、LF に直してから登録する
RUN sed -i 's/\r$//' /usr/local/bin/move_gacha_output.sh /usr/local/bin/entrypoint.sh /tmp/crontab \
  && chmod 755 /usr/local/bin/move_gacha_output.sh /usr/local/bin/entrypoint.sh \
  && crontab /tmp/crontab \
  && rm /tmp/crontab

CMD ["/usr/local/bin/entrypoint.sh"]
