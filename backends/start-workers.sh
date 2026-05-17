#!/usr/bin/env bash
set -euo pipefail

APP_DIR="/home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends"
PHP="/usr/local/bin/ea-php82"

cd "$APP_DIR"
mkdir -p storage/logs

start_if_missing() {
  local pattern="$1"
  shift

  if pgrep -af "$pattern" >/dev/null 2>&1; then
    return 0
  fi

  nohup "$@" &
}

start_if_missing \
  "queue:work --queue=critical,execution,reconcile,monitoring" \
  "$PHP" artisan queue:work --queue=critical,execution,reconcile,monitoring --sleep=1 --tries=3 --timeout=120 --max-time=3600 \
  >> storage/logs/worker-critical.log 2>&1

start_if_missing \
  "queue:work --queue=market,signals,strategies,ratio_bot,ops,intelligence,risk,billing,copytrading" \
  "$PHP" artisan queue:work --queue=market,signals,strategies,ratio_bot,ops,intelligence,risk,billing,copytrading --sleep=1 --tries=3 --timeout=120 --max-time=3600 \
  >> storage/logs/worker-market.log 2>&1

start_if_missing \
  "queue:work --queue=analytics,notifications,default,monitoring_safe" \
  "$PHP" artisan queue:work --queue=analytics,notifications,default,monitoring_safe --sleep=1 --tries=3 --timeout=120 --max-time=3600 \
  >> storage/logs/worker-safe.log 2>&1

start_if_missing \
  "future-trade:live-feed" \
  "$PHP" artisan future-trade:live-feed --reconnect-sleep=2 \
  >> storage/logs/future-trade-live-feed.log 2>> storage/logs/future-trade-live-feed.error.log

echo "AutoFinX workers started."
