#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends}"
PHP="${PHP:-/usr/local/bin/ea-php82}"
if [ ! -x "$PHP" ] && [ -x "/opt/cpanel/ea-php82/root/usr/bin/php" ]; then
  PHP="/opt/cpanel/ea-php82/root/usr/bin/php"
fi

FT_SYMBOLS="${FT_SYMBOLS:-ETHUSDT,BTCUSDT,SOLUSDT,BNBUSDT}"
FT_STREAMS="${FT_STREAMS:-trade,kline_1m,kline_5m,kline_15m,kline_1h,kline_4h}"
LIVE_FEED_STALE_SECONDS="${LIVE_FEED_STALE_SECONDS:-60}"
LIVE_FEED_RESTART_COOLDOWN_SECONDS="${LIVE_FEED_RESTART_COOLDOWN_SECONDS:-60}"

LOCK_DIR="$APP_DIR/storage/framework/future-trade-heartbeat.lock"
RESTART_STAMP="$APP_DIR/storage/framework/future-trade-live-feed-restart.at"

cd "$APP_DIR"
mkdir -p storage/logs storage/framework

if [ -d "$LOCK_DIR" ]; then
  if find "$LOCK_DIR" -maxdepth 0 -mmin +2 2>/dev/null | grep -q .; then
    rmdir "$LOCK_DIR" >/dev/null 2>&1 || true
  fi
fi

if ! mkdir "$LOCK_DIR" 2>/dev/null; then
  echo "[HEARTBEAT] skipped; another heartbeat is active at $(date -Is)"
  exit 0
fi

trap 'rmdir "$LOCK_DIR" >/dev/null 2>&1 || true' EXIT

count_processes() {
  local pattern="$1"
  pgrep -f "$pattern" 2>/dev/null | wc -l | tr -d ' '
}

kill_extra_processes() {
  local pattern="$1"
  local label="$2"
  mapfile -t pids < <(pgrep -f "$pattern" 2>/dev/null || true)
  local count="${#pids[@]}"

  if [ "$count" -le 1 ]; then
    return 0
  fi

  echo "[HEARTBEAT] $label duplicate count=$count; keeping pid=${pids[0]}, stopping extras"
  for pid in "${pids[@]:1}"; do
    kill "$pid" >/dev/null 2>&1 || true
  done
}

start_worker_if_missing() {
  local pattern="$1"
  local label="$2"
  local log_file="$3"
  shift 3

  kill_extra_processes "$pattern" "$label"

  if pgrep -f "$pattern" >/dev/null 2>&1; then
    return 0
  fi

  echo "[HEARTBEAT] starting $label"
  nohup "$@" >> "$log_file" 2>&1 &
}

start_live_feed() {
  local reason="$1"
  echo "[HEARTBEAT] starting live-feed reason=$reason symbols=$FT_SYMBOLS streams=$FT_STREAMS"
  nohup "$PHP" artisan future-trade:live-feed \
    --symbols="$FT_SYMBOLS" \
    --streams="$FT_STREAMS" \
    --reconnect-sleep=2 \
    >> storage/logs/future-trade-live-feed.log \
    2>> storage/logs/future-trade-live-feed.error.log &
  date +%s > "$RESTART_STAMP"
}

restart_live_feed_if_needed() {
  local feed_age
  local feed_count
  local now_epoch
  local last_restart

  feed_count="$(count_processes "artisan future-trade:live-feed")"
  if [ "$feed_count" -gt 1 ]; then
    echo "[HEARTBEAT] live-feed duplicate count=$feed_count; restarting single feed"
    pkill -f "artisan future-trade:live-feed" || true
    sleep 1
    start_live_feed "duplicate_processes"
    return 0
  fi

  feed_age="$({ "$PHP" artisan tinker --execute='$feed=\App\Models\MarketFeedStatus::where("market_type","futures")->whereNotNull("last_tick_at")->latest("last_tick_at")->first(); echo $feed && $feed->last_tick_at ? (int) now()->diffInSeconds($feed->last_tick_at, true) : 999999;' 2>/dev/null | grep -Eo '[0-9]+' | tail -n 1; } || true)"
  feed_age="${feed_age:-999999}"
  now_epoch="$(date +%s)"
  last_restart="$(cat "$RESTART_STAMP" 2>/dev/null || echo 0)"

  if [ "$feed_count" -lt 1 ]; then
    start_live_feed "missing_process"
    return 0
  fi

  if [ "$feed_age" -gt "$LIVE_FEED_STALE_SECONDS" ]; then
    if [ $((now_epoch - last_restart)) -lt "$LIVE_FEED_RESTART_COOLDOWN_SECONDS" ]; then
      echo "[HEARTBEAT] live-feed stale age=${feed_age}s; restart cooldown active"
      return 0
    fi

    echo "[HEARTBEAT] live-feed stale age=${feed_age}s; restarting"
    pkill -f "artisan future-trade:live-feed" || true
    sleep 1
    start_live_feed "stale_feed_${feed_age}s"
    return 0
  fi

  echo "[HEARTBEAT] live-feed ok age=${feed_age}s"
}

start_runtime_slice_if_missing() {
  local pattern="$1"
  local label="$2"
  local log_file="$3"
  shift 3

  if pgrep -f "$pattern" >/dev/null 2>&1; then
    return 0
  fi

  echo "[HEARTBEAT] starting runtime slice $label"
  nohup "$@" >> "$log_file" 2>&1 &
}

echo "[HEARTBEAT] start at $(date -Is)"

start_worker_if_missing \
  "queue:work --queue=critical,execution,reconcile,monitoring" \
  "queue-critical" \
  "storage/logs/worker-critical.log" \
  "$PHP" artisan queue:work --queue=critical,execution,reconcile,monitoring --sleep=1 --tries=3 --timeout=120 --max-time=3600

start_worker_if_missing \
  "queue:work --queue=market,signals,strategies,ratio_bot,ops,intelligence,risk,billing,copytrading" \
  "queue-market" \
  "storage/logs/worker-market.log" \
  "$PHP" artisan queue:work --queue=market,signals,strategies,ratio_bot,ops,intelligence,risk,billing,copytrading --sleep=1 --tries=3 --timeout=120 --max-time=3600

start_worker_if_missing \
  "queue:work --queue=analytics,notifications,default,monitoring_safe" \
  "queue-safe" \
  "storage/logs/worker-safe.log" \
  "$PHP" artisan queue:work --queue=analytics,notifications,default,monitoring_safe --sleep=1 --tries=3 --timeout=120 --max-time=3600

restart_live_feed_if_needed

start_runtime_slice_if_missing \
  "artisan future-trade:tick" \
  "future-trade:tick" \
  "storage/logs/future-trade-tick.log" \
  "$PHP" artisan future-trade:tick --duration=55 --sleep-ms=1000

start_runtime_slice_if_missing \
  "artisan future-trade:user-stream" \
  "future-trade:user-stream" \
  "storage/logs/future-trade-user-stream.log" \
  "$PHP" artisan future-trade:user-stream --duration=55

if ! pgrep -f "artisan schedule:run" >/dev/null 2>&1; then
  "$PHP" artisan schedule:run >> storage/logs/cron-schedule-run.log 2>&1 || true
fi

queue_count="$(count_processes "artisan queue:work")"
feed_count="$(count_processes "artisan future-trade:live-feed")"
tick_count="$(count_processes "artisan future-trade:tick")"
user_stream_count="$(count_processes "artisan future-trade:user-stream")"

echo "[HEARTBEAT] done queue=$queue_count feed=$feed_count tick=$tick_count user_stream=$user_stream_count at $(date -Is)"
