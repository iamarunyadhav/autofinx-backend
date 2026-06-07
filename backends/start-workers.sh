#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${APP_DIR:-$SCRIPT_DIR}"
PHP="${PHP:-$(command -v php || true)}"
if { [ -z "$PHP" ] || [ ! -x "$PHP" ]; } && [ -x "/usr/local/bin/ea-php82" ]; then
  PHP="/usr/local/bin/ea-php82"
fi
if { [ -z "$PHP" ] || [ ! -x "$PHP" ]; } && [ -x "/opt/cpanel/ea-php82/root/usr/bin/php" ]; then
  PHP="/opt/cpanel/ea-php82/root/usr/bin/php"
fi
if [ -z "$PHP" ] || [ ! -x "$PHP" ]; then
  echo "PHP binary not found. Set PHP=/path/to/php and retry." >&2
  exit 1
fi

FT_SYMBOLS="${FT_SYMBOLS:-ETHUSDT,BTCUSDT,SOLUSDT,BNBUSDT}"
FT_STREAMS="${FT_STREAMS:-trade,kline_1m,kline_5m,kline_15m,kline_1h,kline_4h}"

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
  "$PHP" artisan future-trade:live-feed --symbols="$FT_SYMBOLS" --streams="$FT_STREAMS" --reconnect-sleep=2 \
  >> storage/logs/future-trade-live-feed.log 2>> storage/logs/future-trade-live-feed.error.log

echo "AutoFinX workers started."
