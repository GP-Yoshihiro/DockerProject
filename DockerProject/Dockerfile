FROM php:8.1-apache
RUN docker-php-ext-install pdo_mysql

# 資料06: crontab でランキングを自動更新するため、cron を入れる
ENV TZ=Asia/Tokyo
RUN apt-get update \
  && apt-get install -y --no-install-recommends cron tzdata \
  && ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone \
  && rm -rf /var/lib/apt/lists/*

# /etc/cron.d/battle-cron に登録する（Windows で改行が CRLF になっていても動くよう LF に直す）
COPY cron/battle-cron /etc/cron.d/battle-cron
RUN sed -i 's/\r$//' /etc/cron.d/battle-cron \
  && chmod 0644 /etc/cron.d/battle-cron \
  && touch /var/log/cron.log

# cron（バックグラウンド）を起動してから、Apache をいつもどおり起動する
CMD ["sh", "-c", "cron && exec apache2-foreground"]
