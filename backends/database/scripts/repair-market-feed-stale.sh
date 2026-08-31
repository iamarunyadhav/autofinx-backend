#!/usr/bin/env bash
set -euo pipefail

# AutoFinX P0 market-feed repair.
# Run on the production server from the Laravel backend directory:
#   sudo bash database/scripts/repair-market-feed-stale.sh

APP_DIR="${APP_DIR:-$(pwd)}"
RUN_USER="${RUN_USER:-zcadmin}"
SYMBOLS="${SYMBOLS:-BTCUSDT,ETHUSDT,SOLUSDT}"
MARKET_TYPE="${MARKET_TYPE:-futures}"
SUPERVISOR_GROUP="${SUPERVISOR_GROUP:-autofinx-trading}"
PROGRAM="${PROGRAM:-${SUPERVISOR_GROUP}:live-feed}"
VERIFY_ATTEMPTS="${VERIFY_ATTEMPTS:-24}"
VERIFY_SLEEP="${VERIFY_SLEEP:-2}"
MAX_AGE_MS="${MAX_AGE_MS:-5000}"
LOG_DIR="${LOG_DIR:-/var/log/autofinx}"

if [ "$(id -u)" -eq 0 ]; then
  SUDO=""
else
  SUDO="sudo"
fi

log() {
  printf '[market-feed-repair] %s %s\n' "$(date -Is)" "$*"
}

fail() {
  printf '[market-feed-repair] FAIL: %s\n' "$*" >&2
  exit 1
}

find_php() {
  if [ -n "${PHP_BIN:-}" ] && [ -x "$PHP_BIN" ]; then
    echo "$PHP_BIN"
    return 0
  fi

  for candidate in \
    /opt/cpanel/ea-php84/root/usr/bin/php \
    /opt/cpanel/ea-php83/root/usr/bin/php \
    /opt/cpanel/ea-php82/root/usr/bin/php \
    /usr/local/bin/ea-php84 \
    /usr/local/bin/ea-php83 \
    /usr/local/bin/ea-php82 \
    /usr/bin/php \
    /usr/local/bin/php; do
    if [ -x "$candidate" ]; then
      version="$("$candidate" -r 'echo PHP_VERSION;' 2>/dev/null || true)"
      if "$candidate" -r 'exit(version_compare(PHP_VERSION, "8.2.0", ">=") ? 0 : 1);' 2>/dev/null; then
        echo "$candidate"
        return 0
      fi
      log "skipping PHP $candidate version=$version; need >=8.2"
    fi
  done

  return 1
}

PHP_BIN="$(find_php)" || fail "PHP >= 8.2 binary not found. Set PHP_BIN=/path/to/php82."

cd "$APP_DIR"
[ -f artisan ] || fail "artisan not found in APP_DIR=$APP_DIR"

log "app_dir=$APP_DIR"
log "php=$PHP_BIN version=$("$PHP_BIN" -r 'echo PHP_VERSION;')"
log "symbols=$SYMBOLS market_type=$MARKET_TYPE"

exchange_key() {
  if [ "$MARKET_TYPE" = "futures" ]; then
    echo "binance_futures"
  else
    echo "binance"
  fi
}

market_ws_base() {
  if [ "$MARKET_TYPE" = "futures" ]; then
    echo "wss://fstream.binance.com"
  else
    echo "wss://stream.binance.com:9443"
  fi
}

supervisor_conf_dir() {
  for dir in /etc/supervisord.d /etc/supervisor/conf.d; do
    if [ -d "$dir" ]; then
      echo "$dir"
      return 0
    fi
  done
  fail "Supervisor config directory not found."
}

find_supervisor_conf() {
  for dir in /etc/supervisord.d /etc/supervisor/conf.d; do
    [ -d "$dir" ] || continue
    match="$(grep -Rsl "^\[program:${SUPERVISOR_GROUP}:live-feed\]" "$dir" 2>/dev/null | head -n 1 || true)"
    if [ -n "$match" ]; then
      echo "$match"
      return 0
    fi
  done

  conf_dir="$(supervisor_conf_dir)"
  if [ "$conf_dir" = "/etc/supervisor/conf.d" ]; then
    echo "$conf_dir/autofinx-market-feed.conf"
  else
    echo "$conf_dir/autofinx-market-feed.ini"
  fi
}

write_supervisor_config() {
  command -v supervisorctl >/dev/null 2>&1 || fail "supervisorctl not found."

  conf_file="$(find_supervisor_conf)"

  log "patching supervisor live-feed config=$conf_file"
  $SUDO mkdir -p "$LOG_DIR"
  $SUDO chown -R "$RUN_USER:$RUN_USER" "$LOG_DIR" 2>/dev/null || true

  if [ -f "$conf_file" ]; then
    $SUDO cp -a "$conf_file" "$conf_file.bak.market-feed.$(date +%Y%m%d%H%M%S)"
  fi

  section="$(mktemp)"
  cat > "$section" <<EOF
[program:${SUPERVISOR_GROUP}:live-feed]
process_name=%(program_name)s
command=${PHP_BIN} artisan future-trade:live-feed --symbols=${SYMBOLS} --streams=trade,kline_1m,kline_5m,kline_15m,kline_1h,kline_4h,kline_1d --reconnect-sleep=2
directory=${APP_DIR}
autostart=true
autorestart=true
startsecs=5
startretries=50
stopwaitsecs=30
killasgroup=true
stopasgroup=true
user=${RUN_USER}
redirect_stderr=false
stdout_logfile=${LOG_DIR}/live-feed.out.log
stdout_logfile_maxbytes=20MB
stdout_logfile_backups=5
stderr_logfile=${LOG_DIR}/live-feed.err.log
stderr_logfile_maxbytes=20MB
stderr_logfile_backups=5
environment=APP_ENV="production"
EOF

  tmp="$(mktemp)"
  if [ -f "$conf_file" ]; then
    "$PHP_BIN" -r '
        $file = $argv[1];
        $section = file_get_contents($argv[2]);
        $text = file_get_contents($file);
        $header = preg_quote("[program:".$argv[3].":live-feed]", "/");
        $pattern = "/^".$header."\\R.*?(?=^\\[|\\z)/ms";
        if (preg_match($pattern, $text)) {
            $text = preg_replace($pattern, rtrim($section).PHP_EOL.PHP_EOL, $text, 1);
        } else {
            $text = rtrim($text).PHP_EOL.PHP_EOL.rtrim($section).PHP_EOL;
        }
        file_put_contents($argv[4], $text);
    ' "$conf_file" "$section" "$SUPERVISOR_GROUP" "$tmp"
  else
    cp "$section" "$tmp"
  fi

  $SUDO mv "$tmp" "$conf_file"
  rm -f "$section"
}

artisan_clear() {
  log "clearing Laravel caches and restarting queues"
  "$PHP_BIN" artisan config:clear || true
  "$PHP_BIN" artisan cache:clear || true
  "$PHP_BIN" artisan queue:restart || true
  "$PHP_BIN" artisan optimize:clear || true
}

stop_duplicates() {
  log "stopping duplicate live-feed/ws consumers"
  $SUDO supervisorctl stop "$PROGRAM" >/dev/null 2>&1 || true
  $SUDO pkill -f "artisan future-trade:live-feed" >/dev/null 2>&1 || true
  $SUDO pkill -f "artisan market:ws-consume" >/dev/null 2>&1 || true
}

clear_runtime_files() {
  log "clearing stale runtime heartbeats"
  rm -f \
    storage/framework/autofinx-market-ws-heartbeat-futures.json \
    storage/framework/autofinx-market-ws-heartbeat-spot.json \
    storage/framework/autofinx-live-feed-heartbeat.json \
    storage/app/runtime/future-trade-live-feed.json \
    storage/framework/autofinx-runtime-master-live-feed-restart.at 2>/dev/null || true
}

start_supervisor() {
  log "reloading supervisor"
  $SUDO supervisorctl reread || true
  $SUDO supervisorctl update || true
  $SUDO supervisorctl start "$PROGRAM" || $SUDO supervisorctl restart "$PROGRAM" || true
  sleep 5
  $SUDO supervisorctl status "$PROGRAM" || true
}

snapshot_line() {
  local symbol="$1"
  local exchange
  exchange="$(exchange_key)"

  "$PHP_BIN" artisan tinker --execute="
\$s = app(\\App\\Domain\\MarketData\\MarketSnapshotService::class)->getLiveSnapshot('binance', '${symbol}', '${exchange}', 30);
echo json_encode([
    'symbol' => \$s['symbol'] ?? '${symbol}',
    'state' => \$s['connection_state'] ?? null,
    'age_ms' => \$s['age_ms'] ?? null,
    'received_at' => \$s['received_at'] ?? null,
    'event_lag_ms' => \$s['legacy']['event_lag_ms'] ?? null,
    'source' => \$s['source'] ?? null,
    'fresh' => \$s['is_fresh'] ?? false,
    'reason' => \$s['stale_reason'] ?? null,
], JSON_UNESCAPED_SLASHES);
" 2>/dev/null | tail -n 1
}

verify_freshness() {
  IFS=',' read -r -a symbol_array <<< "$SYMBOLS"
  log "verifying selected-symbol Redis snapshots"

  for attempt in $(seq 1 "$VERIFY_ATTEMPTS"); do
    all_fresh=1
    echo "attempt=$attempt"

    for raw_symbol in "${symbol_array[@]}"; do
      symbol="$(echo "$raw_symbol" | tr '[:lower:]' '[:upper:]' | xargs)"
      [ -n "$symbol" ] || continue
      line="$(snapshot_line "$symbol")"
      echo "$line"
      fresh="$(printf '%s' "$line" | "$PHP_BIN" -r '$j=json_decode(stream_get_contents(STDIN), true); echo !empty($j["fresh"]) ? "1" : "0";' 2>/dev/null || echo 0)"
      age="$(printf '%s' "$line" | "$PHP_BIN" -r '$j=json_decode(stream_get_contents(STDIN), true); echo (int)($j["age_ms"] ?? 999999);' 2>/dev/null || echo 999999)"
      if [ "$fresh" != "1" ] || [ "$age" -gt "$MAX_AGE_MS" ]; then
        all_fresh=0
      fi
    done

    if [ "$all_fresh" -eq 1 ]; then
      log "OK: market feed fresh for all selected symbols"
      return 0
    fi

    sleep "$VERIFY_SLEEP"
  done

  log "live-feed stdout tail"
  $SUDO tail -n 120 "$LOG_DIR/live-feed.out.log" 2>/dev/null || true
  log "live-feed stderr tail"
  $SUDO tail -n 120 "$LOG_DIR/live-feed.err.log" 2>/dev/null || true
  fail "market feed did not become fresh within $((VERIFY_ATTEMPTS * VERIFY_SLEEP)) seconds"
}

print_redis_keys_via_laravel() {
  local first_symbol="${SYMBOLS%%,*}"
  local exchange
  exchange="$(exchange_key)"

  log "sample Redis keys via Laravel for $first_symbol"
  "$PHP_BIN" artisan tinker --execute="
foreach (['market:live:${first_symbol}', 'live-feed:${exchange}:${first_symbol}', 'autofinx:market:binance:${first_symbol}:quote'] as \$key) {
    \$value = \\Illuminate\\Support\\Facades\\Redis::get(\$key);
    echo \$key.': '.(\$value ? substr(\$value, 0, 220) : 'NULL').PHP_EOL;
}
" || true
}

write_supervisor_config
artisan_clear
stop_duplicates
clear_runtime_files
start_supervisor
verify_freshness
print_redis_keys_via_laravel

log "done"
