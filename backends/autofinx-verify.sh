#!/usr/bin/env bash
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${APP_DIR:-$SCRIPT_DIR}"
PHP="${PHP:-}"
SUPERVISOR_GROUP="${SUPERVISOR_GROUP:-autofinx-trading:*}"
RUN_RESTART=1
KILL_LEGACY=1
WORKSPACE_ID="${WORKSPACE_ID:-}"
ALERT_TEST_URL="${ALERT_TEST_URL:-}"
ALERT_TEST_TOKEN="${ALERT_TEST_TOKEN:-}"
LIVE_FEED_MAX_AGE="${LIVE_FEED_MAX_AGE:-30}"
MARKET_FEED_MAX_AGE="${MARKET_FEED_MAX_AGE:-90}"
QUEUE_MAX_PENDING="${QUEUE_MAX_PENDING:-100}"
QUEUE_MAX_OLDEST_AGE="${QUEUE_MAX_OLDEST_AGE:-300}"
FT_SYMBOLS="${FT_SYMBOLS:-ETHUSDT,BTCUSDT,SOLUSDT,BNBUSDT}"
FT_STREAMS="${FT_STREAMS:-trade,kline_1m,kline_5m,kline_15m,kline_1h,kline_4h}"

usage() {
  cat <<HELP
AutoFinX Full Supervisor Runtime & WebSocket Verification

Usage:
  bash autofinx-verify.sh [options]

Options:
  --no-restart              Do not restart supervisor processes before checks.
  --no-kill-legacy          Do not kill legacy/rogue artisan workers.
  --workspace=UUID          Limit Laravel runtime checks to one workspace.
  --app-dir=PATH            Laravel backend directory. Default: script dir.
  --php=PATH                PHP binary. Auto-detects ea-php82/php when omitted.
  --supervisor-group=NAME   Supervisor group/pattern. Default: autofinx-trading:*
  --alert-url=URL           Optional safe fake/test alert endpoint to POST.
  --alert-token=TOKEN       Optional Bearer token for --alert-url.
  --live-feed-max-age=N     Max heartbeat age in seconds. Default: 30.
  --market-feed-max-age=N   Max market_feed_status age in seconds. Default: 90.
  --queue-max-pending=N     Warn/fail if a queue has more pending jobs. Default: 100.
  --queue-max-oldest-age=N  Warn/fail if oldest queued job is older. Default: 300.
  -h, --help                Show this help.

Examples:
  bash autofinx-verify.sh
  bash autofinx-verify.sh --no-restart
  ALERT_TEST_URL=https://api.example.com/api/v1/fake-test-alert bash autofinx-verify.sh
HELP
}

for arg in "$@"; do
  case "$arg" in
    --no-restart) RUN_RESTART=0 ;;
    --no-kill-legacy) KILL_LEGACY=0 ;;
    --workspace=*) WORKSPACE_ID="${arg#*=}" ;;
    --app-dir=*) APP_DIR="${arg#*=}" ;;
    --php=*) PHP="${arg#*=}" ;;
    --supervisor-group=*) SUPERVISOR_GROUP="${arg#*=}" ;;
    --alert-url=*) ALERT_TEST_URL="${arg#*=}" ;;
    --alert-token=*) ALERT_TEST_TOKEN="${arg#*=}" ;;
    --live-feed-max-age=*) LIVE_FEED_MAX_AGE="${arg#*=}" ;;
    --market-feed-max-age=*) MARKET_FEED_MAX_AGE="${arg#*=}" ;;
    --queue-max-pending=*) QUEUE_MAX_PENDING="${arg#*=}" ;;
    --queue-max-oldest-age=*) QUEUE_MAX_OLDEST_AGE="${arg#*=}" ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown option: $arg" >&2; usage; exit 2 ;;
  esac
done

PASS_COUNT=0
WARN_COUNT=0
FAIL_COUNT=0
REPORT_FILE=""

section() {
  printf '\n=== %s ===\n' "$*"
}

pass() {
  PASS_COUNT=$((PASS_COUNT + 1))
  printf '[PASS] %s\n' "$*"
}

warn() {
  WARN_COUNT=$((WARN_COUNT + 1))
  printf '[WARN] %s\n' "$*"
}

fail() {
  FAIL_COUNT=$((FAIL_COUNT + 1))
  printf '[FAIL] %s\n' "$*"
}

command_exists() {
  command -v "$1" >/dev/null 2>&1
}

resolve_php() {
  if [ -n "$PHP" ] && [ -x "$PHP" ]; then
    return 0
  fi
  if [ -x "/usr/local/bin/ea-php82" ]; then
    PHP="/usr/local/bin/ea-php82"
    return 0
  fi
  if [ -x "/opt/cpanel/ea-php82/root/usr/bin/php" ]; then
    PHP="/opt/cpanel/ea-php82/root/usr/bin/php"
    return 0
  fi
  PHP="$(command -v php || true)"
  [ -n "$PHP" ] && [ -x "$PHP" ]
}

run_artisan() {
  "$PHP" artisan "$@"
}

proc_count() {
  local pattern="$1"
  pgrep -f "$pattern" 2>/dev/null | wc -l | tr -d '[:space:]'
}

age_from_epoch() {
  local epoch="$1"
  local now
  now="$(date +%s)"
  if [ -z "$epoch" ] || [ "$epoch" = "0" ]; then
    echo ""
    return 0
  fi
  echo $((now - epoch))
}

cd "$APP_DIR" 2>/dev/null || {
  echo "[FAIL] Cannot cd to APP_DIR=$APP_DIR" >&2
  exit 1
}

mkdir -p storage/logs storage/framework storage/app/runtime
REPORT_FILE="$APP_DIR/storage/logs/autofinx-verify-$(date +%Y%m%d-%H%M%S).log"
exec > >(tee "$REPORT_FILE") 2>&1

section "AutoFinX Full Runtime Verification"
echo "Started at       : $(date -Is)"
echo "App dir          : $APP_DIR"
echo "Supervisor group : $SUPERVISOR_GROUP"
echo "Workspace        : ${WORKSPACE_ID:-all}"
echo "Report           : $REPORT_FILE"

section "1. Environment"
if [ -f artisan ]; then
  pass "Laravel artisan found"
else
  fail "artisan not found in $APP_DIR"
fi

if resolve_php; then
  pass "PHP found: $PHP"
  "$PHP" --version 2>/dev/null | head -1 || true
else
  fail "PHP binary not found. Set PHP=/path/to/php."
fi

if command_exists supervisorctl; then
  pass "supervisorctl found"
else
  fail "supervisorctl not found"
fi

if command_exists redis-cli; then
  pass "redis-cli found"
else
  warn "redis-cli not found; Laravel Redis ping will still be checked"
fi

section "2. Restart Supervisor Runtime"
if [ "$RUN_RESTART" -eq 1 ]; then
  if command_exists supervisorctl; then
    echo "Restarting $SUPERVISOR_GROUP ..."
    if supervisorctl restart "$SUPERVISOR_GROUP"; then
      pass "Supervisor restart command completed"
      sleep 5
    else
      fail "Supervisor restart failed for $SUPERVISOR_GROUP"
    fi
  else
    fail "Cannot restart supervisor because supervisorctl is unavailable"
  fi
else
  warn "Supervisor restart skipped by --no-restart"
fi

section "3. Kill Legacy Or Duplicate Workers"
if [ "$KILL_LEGACY" -eq 1 ]; then
  legacy_patterns=(
    "artisan queue:work --queue=critical,execution,reconcile,monitoring,market,signals,strategies,ratio_bot,ops,intelligence,risk,billing,copytrading,analytics,notifications,default,monitoring_safe"
    "artisan market:ws-consume"
    "artisan metals:poll-prices"
  )
  killed_any=0
  for pattern in "${legacy_patterns[@]}"; do
    count="$(proc_count "$pattern")"
    if [ "${count:-0}" -gt 0 ]; then
      echo "Killing legacy pattern: $pattern (count=$count)"
      pkill -f "$pattern" 2>/dev/null || true
      killed_any=1
    fi
  done
  if [ "$killed_any" -eq 1 ]; then
    sleep 2
    pass "Legacy workers were cleaned"
  else
    pass "No legacy workers detected"
  fi
else
  warn "Legacy cleanup skipped by --no-kill-legacy"
fi

section "4. Supervisor Status"
if command_exists supervisorctl; then
  status_output="$(supervisorctl status "$SUPERVISOR_GROUP" 2>&1)"
  echo "$status_output"
  if echo "$status_output" | grep -qiE "ERROR|no such|unix:///|refused"; then
    fail "Supervisor status query failed"
  elif echo "$status_output" | awk 'NF && $2 != "RUNNING" { bad=1 } END { exit bad ? 0 : 1 }'; then
    fail "One or more supervisor processes are not RUNNING"
  else
    pass "All matched supervisor processes are RUNNING"
  fi
else
  fail "Supervisor status skipped; supervisorctl unavailable"
fi

section "5. Process Counts"
queue_count="$(proc_count "artisan queue:work")"
feed_count="$(proc_count "artisan future-trade:live-feed")"
tick_count="$(proc_count "artisan future-trade:tick")"
stream_count="$(proc_count "artisan future-trade:user-stream")"
printf 'queue:work             : %s\n' "${queue_count:-0}"
printf 'future-trade:live-feed : %s\n' "${feed_count:-0}"
printf 'future-trade:tick      : %s\n' "${tick_count:-0}"
printf 'future-trade:user-stream: %s\n' "${stream_count:-0}"

if [ "${queue_count:-0}" -ge 1 ]; then pass "At least one queue worker is running"; else fail "No queue workers running"; fi
if [ "${feed_count:-0}" -eq 1 ]; then pass "Exactly one live-feed process is running"; else fail "Expected exactly one live-feed process, got ${feed_count:-0}"; fi
if [ "${tick_count:-0}" -le 1 ]; then pass "No duplicate tick process"; else fail "Duplicate tick processes detected: $tick_count"; fi
if [ "${stream_count:-0}" -le 1 ]; then pass "No duplicate user-stream process"; else fail "Duplicate user-stream processes detected: $stream_count"; fi

section "6. Redis And Database"
if command_exists redis-cli; then
  redis_cli_response="$(redis-cli ping 2>&1 || true)"
  echo "redis-cli ping: $redis_cli_response"
  if [ "$redis_cli_response" = "PONG" ]; then pass "Redis CLI ping OK"; else fail "Redis CLI ping failed"; fi
fi

if [ -x "${PHP:-}" ]; then
  infra_json="$(run_artisan future-trade:runtime-status --json ${WORKSPACE_ID:+--workspace="$WORKSPACE_ID"} 2>/tmp/autofinx-runtime-status.err || true)"
  if [ -n "$infra_json" ] && echo "$infra_json" | "$PHP" -r 'json_decode(stream_get_contents(STDIN), true); exit(json_last_error() === JSON_ERROR_NONE ? 0 : 1);'; then
    echo "$infra_json" > storage/logs/autofinx-runtime-status-last.json
    infra_check_lines="$("$PHP" -r '
      function pick($target, $path, $default = null) {
        foreach (explode(".", $path) as $part) {
          if (!is_array($target) || !array_key_exists($part, $target)) {
            return $default;
          }
          $target = $target[$part];
        }
        return $target;
      }
      $s = json_decode(file_get_contents("php://stdin"), true);
      $checks = [
        "Laravel Redis ping" => (bool) pick($s, "redis.ok"),
        "Laravel DB ping" => (bool) pick($s, "database.ok"),
      ];
      foreach ($checks as $label => $ok) {
        echo ($ok ? "PASS:" : "FAIL:") . $label . PHP_EOL;
      }
      $restOpen = (bool) pick($s, "binance_rest.open", false);
      echo ($restOpen ? "FAIL:" : "PASS:") . "Binance REST circuit closed" . PHP_EOL;
    ' <<< "$infra_json" | tr -d "\r")"
    while IFS=: read -r state label; do
      [ -z "$state" ] && continue
      [ "$state" = "PASS" ] && pass "$label" || fail "$label"
    done <<< "$infra_check_lines"
  else
    fail "future-trade:runtime-status --json failed"
    cat /tmp/autofinx-runtime-status.err 2>/dev/null || true
  fi
else
  fail "Laravel infrastructure checks skipped; PHP unavailable"
fi

section "7. Binance WebSocket Handshake"
if [ -x "${PHP:-}" ]; then
  ws_result="$("$PHP" -r '
    $socket = @stream_socket_client("tls://fstream.binance.com:443", $errno, $errstr, 10);
    if (!$socket) { echo "CONNECT_FAIL:" . $errstr; exit; }
    stream_set_timeout($socket, 5);
    $key = base64_encode(random_bytes(16));
    fwrite($socket, "GET /stream?streams=btcusdt@trade HTTP/1.1\r\nHost: fstream.binance.com\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n\r\n");
    $headers = "";
    $deadline = microtime(true) + 5;
    while (microtime(true) < $deadline && !str_contains($headers, "\r\n\r\n")) {
      $r = [$socket]; $w = null; $x = null;
      if (@stream_select($r, $w, $x, 0, 200000) > 0) {
        $chunk = fread($socket, 4096);
        if ($chunk === false || ($chunk === "" && feof($socket))) { break; }
        $headers .= $chunk;
      }
    }
    if (!str_contains($headers, " 101 ")) {
      echo "HANDSHAKE_FAIL:" . trim(explode("\r\n", $headers)[0] ?? "");
      fclose($socket);
      exit;
    }
    $r = [$socket]; $w = null; $x = null;
    $ready = @stream_select($r, $w, $x, 3, 0);
    if ($ready === 0) { echo "HANDSHAKE_OK:NO_DATA_YET"; fclose($socket); exit; }
    $byte = fread($socket, 1);
    if ($byte === false || $byte === "" || feof($socket)) { echo "HANDSHAKE_OK:IMMEDIATE_CLOSE"; fclose($socket); exit; }
    echo "HANDSHAKE_OK:OPCODE_" . (ord($byte) & 0x0F);
    fclose($socket);
  ' 2>&1 || true)"
  echo "WebSocket result: $ws_result"
  case "$ws_result" in
    HANDSHAKE_OK:OPCODE_1|HANDSHAKE_OK:NO_DATA_YET) pass "Binance WebSocket accepts connections" ;;
    HANDSHAKE_OK:IMMEDIATE_CLOSE|HANDSHAKE_OK:OPCODE_8) fail "Binance WebSocket connected but closed immediately; likely rate-limited" ;;
    *) fail "Binance WebSocket handshake failed" ;;
  esac
else
  fail "WebSocket handshake skipped; PHP unavailable"
fi

section "8. Live Feed Heartbeat And Logs"
heartbeat_file="storage/framework/autofinx-live-feed-heartbeat.json"
if [ -f "$heartbeat_file" ]; then
  heartbeat_epoch="$("$PHP" -r '$j=json_decode(file_get_contents($argv[1]), true); echo (int)($j["last_message_epoch"] ?? 0);' "$heartbeat_file" 2>/dev/null || echo 0)"
  heartbeat_age="$(age_from_epoch "$heartbeat_epoch")"
  cat "$heartbeat_file"
  printf '\n'
  if [ -n "$heartbeat_age" ] && [ "$heartbeat_age" -le "$LIVE_FEED_MAX_AGE" ]; then
    pass "Live-feed heartbeat is fresh (${heartbeat_age}s old)"
  else
    fail "Live-feed heartbeat is stale or unreadable (age=${heartbeat_age:-unknown}s, max=${LIVE_FEED_MAX_AGE}s)"
  fi
else
  fail "Live-feed heartbeat file missing: $heartbeat_file"
fi

live_log="storage/logs/future-trade-live-feed.log"
live_err_log="storage/logs/future-trade-live-feed.error.log"
if [ -f "$live_log" ]; then
  echo "--- last 100 lines: $live_log ---"
  tail -n 100 "$live_log"
  if tail -n 100 "$live_log" | grep -qiE "connected|message|trade|kline|heartbeat|subscribed|live-feed"; then
    pass "Recent live-feed log contains stream activity"
  else
    warn "Recent live-feed log did not contain obvious stream activity keywords"
  fi
else
  fail "Live-feed log missing: $live_log"
fi

if [ -f "$live_err_log" ] && tail -n 50 "$live_err_log" | grep -qiE "ERROR|Exception|failed|closed|rate|timeout"; then
  warn "Recent live-feed error log contains warnings/errors"
  tail -n 50 "$live_err_log"
else
  pass "No recent live-feed error log problems detected"
fi

section "9. Market Feed Database Freshness"
if [ -x "${PHP:-}" ]; then
  market_result="$(run_artisan tinker --no-interaction --execute='
    try {
      if (!Schema::hasTable("market_feed_status")) { echo "MISSING_TABLE"; return; }
      $rows = DB::table("market_feed_status")
        ->orderByDesc(DB::raw("COALESCE(last_tick_at, updated_at)"))
        ->limit(8)
        ->get();
      foreach ($rows as $row) {
        $ts = $row->last_tick_at ?: $row->updated_at;
        $age = $ts ? now()->diffInSeconds(\Carbon\Carbon::parse($ts), true) : null;
        echo implode("|", [
          $row->symbol ?? $row->symbol_id ?? "-",
          $row->exchange ?? "-",
          $row->market_type ?? "-",
          $row->last_price ?? "-",
          $ts ?? "-",
          $age === null ? "null" : $age,
        ]) . PHP_EOL;
      }
    } catch (Throwable $e) {
      echo "ERROR|" . $e->getMessage() . PHP_EOL;
    }
  ' 2>&1 | tr -d "\r" || true)"
  echo "$market_result"
  if echo "$market_result" | grep -q "^MISSING_TABLE"; then
    fail "market_feed_status table missing"
  elif echo "$market_result" | grep -q "^ERROR|"; then
    fail "Market feed DB query failed"
  else
    freshest_age="$(echo "$market_result" | awk -F'|' 'NF >= 6 && $6 ~ /^[0-9]+$/ { print $6; exit }')"
    if [ -n "$freshest_age" ] && [ "$freshest_age" -le "$MARKET_FEED_MAX_AGE" ]; then
      pass "Market feed DB is fresh (${freshest_age}s old)"
    else
      fail "Market feed DB is stale or empty (freshest=${freshest_age:-unknown}s, max=${MARKET_FEED_MAX_AGE}s)"
    fi
  fi
else
  fail "Market feed DB check skipped; PHP unavailable"
fi

section "10. Queue Health"
if [ -x "${PHP:-}" ]; then
  queue_result="$(run_artisan tinker --no-interaction --execute='
    try {
      $queues = ["critical","execution","reconcile","monitoring","market","signals","strategies","ratio_bot","ops","intelligence","risk","billing","copytrading","analytics","notifications","default","monitoring_safe"];
      foreach ($queues as $queue) {
        $pending = Schema::hasTable("jobs") ? DB::table("jobs")->where("queue", $queue)->count() : 0;
        $failed = Schema::hasTable("failed_jobs") ? DB::table("failed_jobs")->where("queue", $queue)->count() : 0;
        $oldest = Schema::hasTable("jobs") ? DB::table("jobs")->where("queue", $queue)->min("created_at") : null;
        $oldestAge = is_numeric($oldest) ? max(0, time() - (int) $oldest) : ($oldest ? now()->diffInSeconds(\Carbon\Carbon::parse($oldest), true) : 0);
        echo "{$queue}|{$pending}|{$failed}|{$oldestAge}" . PHP_EOL;
      }
    } catch (Throwable $e) {
      echo "ERROR|" . $e->getMessage() . PHP_EOL;
    }
  ' 2>&1 | tr -d "\r" || true)"
  echo "$queue_result"
  queue_failed=0
  while IFS='|' read -r queue pending failed_jobs oldest_age; do
    [ -z "$queue" ] && continue
    pending="${pending//[^0-9]/}"
    failed_jobs="${failed_jobs//[^0-9]/}"
    oldest_age="${oldest_age//[^0-9]/}"
    pending="${pending:-0}"
    failed_jobs="${failed_jobs:-0}"
    oldest_age="${oldest_age:-0}"
    if [ "$queue" = "ERROR" ]; then queue_failed=1; continue; fi
    if [ "${failed_jobs:-0}" -gt 0 ] || [ "${pending:-0}" -gt "$QUEUE_MAX_PENDING" ] || [ "${oldest_age:-0}" -gt "$QUEUE_MAX_OLDEST_AGE" ]; then
      queue_failed=1
    fi
  done <<< "$queue_result"
  if [ "$queue_failed" -eq 0 ]; then
    pass "Queues are within pending/failed/age thresholds"
  else
    fail "One or more queues exceed thresholds or have failed jobs"
  fi
  run_artisan future-trade:queue-health || warn "future-trade:queue-health command returned non-zero"
else
  fail "Queue health skipped; PHP unavailable"
fi

section "11. Runtime Diagnostics And Alert Latency"
if [ -x "${PHP:-}" ]; then
  run_artisan future-trade:diagnostics ${WORKSPACE_ID:+"$WORKSPACE_ID"} --persist || warn "future-trade:diagnostics returned non-zero"
  runtime_json="$(run_artisan future-trade:runtime-status --json ${WORKSPACE_ID:+--workspace="$WORKSPACE_ID"} 2>/dev/null || true)"
  if [ -n "$runtime_json" ]; then
    "$PHP" -r '
      function pick($target, $path, $default = null) {
        foreach (explode(".", $path) as $part) {
          if (!is_array($target) || !array_key_exists($part, $target)) {
            return $default;
          }
          $target = $target[$part];
        }
        return $target;
      }
      $s = json_decode(file_get_contents("php://stdin"), true);
      $event = pick($s, "last_webhook_event", []);
      $timing = (array) pick($event, "webhook_timing", []);
      $total = $timing["total_ms"] ?? $timing["totalMs"] ?? null;
      $occurred = pick($event, "occurred_at");
      echo "Last webhook event: " . (pick($event, "event") ?? "none") . PHP_EOL;
      echo "Last webhook occurred_at: " . ($occurred ?: "none") . PHP_EOL;
      echo "Last webhook total_ms: " . ($total === null ? "unknown" : $total) . PHP_EOL;
    ' <<< "$runtime_json"
    latency_ms="$(echo "$runtime_json" | "$PHP" -r '
      function pick($target, $path, $default = null) {
        foreach (explode(".", $path) as $part) {
          if (!is_array($target) || !array_key_exists($part, $target)) {
            return $default;
          }
          $target = $target[$part];
        }
        return $target;
      }
      $s=json_decode(stream_get_contents(STDIN), true);
      $t=pick($s,"last_webhook_event.webhook_timing.total_ms", pick($s,"last_webhook_event.webhook_timing.totalMs"));
      echo is_numeric($t) ? (int)$t : "";
    ' 2>/dev/null || true)"
    if [ -n "$latency_ms" ]; then
      if [ "$latency_ms" -le 2000 ]; then pass "Last webhook latency is acceptable (${latency_ms}ms)"; else fail "Last webhook latency is high (${latency_ms}ms)"; fi
    else
      warn "No webhook latency metric found; skipping latency assertion"
    fi
  else
    fail "Could not read runtime JSON for alert latency"
  fi
else
  fail "Runtime diagnostics skipped; PHP unavailable"
fi

section "12. Optional Safe Alert Endpoint Test"
if [ -n "$ALERT_TEST_URL" ]; then
  if command_exists curl; then
    auth_args=()
    if [ -n "$ALERT_TEST_TOKEN" ]; then
      auth_args=(-H "Authorization: Bearer $ALERT_TEST_TOKEN")
    fi
    http_code="$(curl -sS -o /tmp/autofinx-alert-test-response.json -w "%{http_code}" --max-time 15 \
      -X POST "$ALERT_TEST_URL" \
      -H "Content-Type: application/json" \
      "${auth_args[@]}" \
      --data '{"dry_run":true,"source":"autofinx-verify","symbol":"BTCUSDT","signal":"WAIT","profile":"verification"}' 2>&1 || true)"
    echo "HTTP status: $http_code"
    cat /tmp/autofinx-alert-test-response.json 2>/dev/null || true
    printf '\n'
    if echo "$http_code" | grep -qE '^2[0-9][0-9]$'; then
      pass "Safe alert endpoint accepted dry-run request"
    else
      fail "Safe alert endpoint failed or returned non-2xx"
    fi
  else
    fail "curl not found; cannot test alert endpoint"
  fi
else
  warn "Alert endpoint test skipped. Set ALERT_TEST_URL or pass --alert-url to test a safe dry-run endpoint."
fi

section "13. Recent Laravel Errors"
if [ -f storage/logs/laravel.log ]; then
  recent_errors="$(grep -E "ERROR|CRITICAL|SQLSTATE|Exception" storage/logs/laravel.log 2>/dev/null | tail -n 20 || true)"
  if [ -n "$recent_errors" ]; then
    echo "$recent_errors"
    warn "Laravel log has recent error-looking lines; review above"
  else
    pass "No recent Laravel error-looking lines found"
  fi
else
  warn "Laravel log missing"
fi

section "Final Result"
echo "Passes : $PASS_COUNT"
echo "Warnings: $WARN_COUNT"
echo "Failures: $FAIL_COUNT"
echo "Report : $REPORT_FILE"

if [ "$FAIL_COUNT" -eq 0 ]; then
  echo "ALL CHECKS PASSED"
  exit 0
fi

echo "CHECKS FAILED - review the [FAIL] lines above"
exit 1
