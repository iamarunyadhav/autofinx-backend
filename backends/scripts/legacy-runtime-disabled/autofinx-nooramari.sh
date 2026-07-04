#!/usr/bin/env bash
# =============================================================================
#  AutoFinX — NOORAMARI (100%) Health Check + Auto-Repair
#  Run: bash autofinx-nooramari.sh
#  Safe to run anytime. Auto-repairs workers, reports every component.
# =============================================================================
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP="${APP_DIR:-$SCRIPT_DIR}"
PHP="${PHP:-}"
WS_ID="${WORKSPACE_ID:-26a36b56-4383-4edd-99d1-7622e7c191ef}"
FT_SYMBOLS="${FT_SYMBOLS:-ETHUSDT,BTCUSDT,SOLUSDT,BNBUSDT}"
FT_STREAMS="${FT_STREAMS:-trade,kline_1m,kline_5m,kline_15m,kline_1h,kline_4h}"
TIMESTAMP="$(date '+%Y-%m-%d %H:%M:%S')"
REPORT_DIR="${APP}/storage/logs"
REPORT_FILE="${REPORT_DIR}/nooramari-$(date +%Y%m%d-%H%M%S).log"

PASS=0; WARN=0; FAIL=0
SECTION_FAILS=()

# ── Colors ────────────────────────────────────────────────────────────────────
if [ -t 1 ]; then
  GREEN='\033[0;32m'; YELLOW='\033[1;33m'; RED='\033[0;31m'
  CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'
else
  GREEN=''; YELLOW=''; RED=''; CYAN=''; BOLD=''; RESET=''
fi

pass()    { PASS=$((PASS+1));   printf "${GREEN}[PASS]${RESET} %s\n" "$*"; }
warn()    { WARN=$((WARN+1));   printf "${YELLOW}[WARN]${RESET} %s\n" "$*"; }
fail()    { FAIL=$((FAIL+1));   printf "${RED}[FAIL]${RESET} %s\n" "$*"; SECTION_FAILS+=("$*"); }
info()    { printf "${CYAN}[INFO]${RESET} %s\n" "$*"; }
section() { printf "\n${BOLD}══════════════════════════════════════════════════${RESET}\n"; printf "${BOLD} %s${RESET}\n" "$*"; printf "${BOLD}══════════════════════════════════════════════════${RESET}\n"; }

proc_count() { pgrep -f "$1" 2>/dev/null | wc -l | tr -d '[:space:]'; }

resolve_php() {
  [ -n "$PHP" ] && [ -x "$PHP" ] && return 0
  for candidate in \
      "/usr/local/bin/ea-php82" \
      "/opt/cpanel/ea-php82/root/usr/bin/php" \
      "/usr/bin/php82" "/usr/bin/php8.2" "/usr/bin/php8" "/usr/bin/php"; do
    [ -x "$candidate" ] && { PHP="$candidate"; return 0; }
  done
  PHP="$(command -v php 2>/dev/null || true)"
  [ -n "$PHP" ] && [ -x "$PHP" ]
}

artisan() { "$PHP" artisan "$@"; }

tinker_run() {
  local code="$1"
  "$PHP" artisan tinker --no-interaction --execute="$code" 2>/dev/null | tr -d '\r'
}

redis_get() {
  # Try redis-cli first, fall back to PHP
  local key="$1"
  if command -v redis-cli &>/dev/null; then
    redis-cli get "$key" 2>/dev/null
  else
    tinker_run "echo Cache::get('$key') ?? 'NULL';" 2>/dev/null
  fi
}

# ── Setup ─────────────────────────────────────────────────────────────────────
cd "$APP" || { echo "ERROR: Cannot cd to $APP"; exit 1; }
mkdir -p "$REPORT_DIR"
exec > >(tee "$REPORT_FILE") 2>&1

printf "${BOLD}╔══════════════════════════════════════════════════╗${RESET}\n"
printf "${BOLD}║   AutoFinX NOORAMARI Health Check + Auto-Repair  ║${RESET}\n"
printf "${BOLD}╚══════════════════════════════════════════════════╝${RESET}\n"
echo "  Time    : $TIMESTAMP"
echo "  App dir : $APP"
echo "  Report  : $REPORT_FILE"
echo "  Workspace: $WS_ID"

# =============================================================================
section "1. PHP & Environment"
# =============================================================================
if resolve_php; then
  PHP_VER="$("$PHP" --version 2>/dev/null | head -1 | awk '{print $2}')"
  pass "PHP found: $PHP (v$PHP_VER)"
else
  fail "PHP binary not found — set PHP=/path/to/php"
fi

[ -f "$APP/artisan" ] && pass "Laravel artisan found" || fail "artisan missing in $APP"
[ -f "$APP/.env" ]    && pass ".env file present"      || warn ".env file missing"

# =============================================================================
section "2. Services (Redis / MySQL)"
# =============================================================================
if command -v redis-cli &>/dev/null; then
  if redis-cli ping 2>/dev/null | grep -q PONG; then
    pass "Redis CLI ping → PONG"
  else
    REDIS_AUTH="$(grep '^REDIS_PASSWORD' "$APP/.env" 2>/dev/null | cut -d= -f2- | tr -d '"' | tr -d "'")"
    if [ -n "$REDIS_AUTH" ] && redis-cli -a "$REDIS_AUTH" ping 2>/dev/null | grep -q PONG; then
      pass "Redis CLI ping (with auth) → PONG"
    else
      fail "Redis CLI ping failed"
    fi
  fi
else
  info "redis-cli not installed; using Laravel for Redis check"
fi

if [ -n "$PHP" ]; then
  REDIS_OK=$(tinker_run "try { Cache::put('_nooramari_ping','1',5); echo Cache::get('_nooramari_ping')==='1'?'ok':'fail'; } catch (\\Throwable \$e){echo 'fail';}")
  [ "$REDIS_OK" = "ok" ] && pass "Laravel Redis read/write OK" || fail "Laravel Redis read/write FAILED"

  DB_OK=$(tinker_run "try { DB::select('SELECT 1'); echo 'ok'; } catch (\\Throwable \$e){echo 'fail|'.\$e->getMessage();}")
  [[ "$DB_OK" == ok ]] && pass "MySQL connection OK" || fail "MySQL connection FAILED: $DB_OK"
fi

# =============================================================================
section "3. Process Health + Auto-Repair"
# =============================================================================
QUEUE_N=$(proc_count "artisan queue:work")
FEED_N=$(proc_count "future-trade:live-feed")
TICK_N=$(proc_count "future-trade:tick")
STREAM_N=$(proc_count "future-trade:user-stream")

printf "  queue:work       : %s\n" "$QUEUE_N"
printf "  live-feed        : %s\n" "$FEED_N"
printf "  tick (transient) : %s\n" "$TICK_N"
printf "  user-stream      : %s\n" "$STREAM_N"

NEED_RESTART=0
HAS_SUPERVISOR=0
command -v supervisorctl &>/dev/null && HAS_SUPERVISOR=1

if [ "$HAS_SUPERVISOR" -eq 1 ]; then
  # ── Supervisor mode: let supervisorctl own all process lifecycle ──────────
  # Never pkill supervisor-managed processes — supervisor will just restart
  # them immediately, creating duplicates. Restart via supervisorctl only.
  if [ "${QUEUE_N:-0}" -gt 2 ]; then
    warn "Too many queue:work processes ($QUEUE_N) — restarting via supervisor"
    supervisorctl restart "autofinx-trading:queue-critical" \
                          "autofinx-trading:queue-market" \
                          "autofinx-trading:queue-safe" 2>/dev/null || true
    sleep 3; NEED_RESTART=1
  fi
  if [ "${FEED_N:-0}" -eq 0 ]; then
    warn "live-feed not running — restarting via supervisor"
    supervisorctl restart "autofinx-trading:live-feed" 2>/dev/null || true
    sleep 4; NEED_RESTART=1
  fi
else
  # ── Non-supervisor (cron) mode: manage directly ───────────────────────────
  if [ "${QUEUE_N:-0}" -gt 1 ]; then
    warn "Duplicate queue:work ($QUEUE_N) — killing all for clean restart"
    pkill -f "artisan queue:work" 2>/dev/null; sleep 2; NEED_RESTART=1
  fi
  if [ "${FEED_N:-0}" -gt 1 ]; then
    warn "Duplicate live-feed ($FEED_N) — killing all"
    pkill -f "future-trade:live-feed" 2>/dev/null; sleep 2; NEED_RESTART=1
  fi
  [ "${QUEUE_N:-0}" -eq 0 ] && NEED_RESTART=1
  [ "${FEED_N:-0}" -eq 0 ]  && NEED_RESTART=1

  if [ "$NEED_RESTART" -eq 1 ] && [ -n "$PHP" ]; then
    if ! pgrep -f "artisan queue:work" >/dev/null 2>&1; then
      info "Auto-starting queue:work (no-supervisor mode)..."
      nohup "$PHP" artisan queue:work \
        --queue=critical,execution,reconcile,monitoring,market,signals,strategies,ratio_bot,ops,intelligence,risk,billing,copytrading,analytics,notifications,default,monitoring_safe \
        --sleep=1 --tries=3 --timeout=120 --max-time=3600 \
        >> storage/logs/worker-all.log 2>&1 &
      sleep 3
    fi
    if ! pgrep -f "future-trade:live-feed" >/dev/null 2>&1; then
      info "Auto-starting live-feed (no-supervisor mode)..."
      nohup "$PHP" artisan future-trade:live-feed \
        --symbols="$FT_SYMBOLS" --streams="$FT_STREAMS" --reconnect-sleep=2 \
        >> storage/logs/future-trade-live-feed.log 2>> storage/logs/future-trade-live-feed.error.log &
      sleep 4
    fi
  fi
fi

# Re-check after repair
QUEUE_N2=$(proc_count "artisan queue:work")
FEED_N2=$(proc_count "future-trade:live-feed")
[ "${QUEUE_N2:-0}" -ge 1 ] && pass "queue:work running (count=$QUEUE_N2)" || fail "queue:work NOT running"
[ "${FEED_N2:-0}" -eq 1 ]  && pass "live-feed running"                    || fail "live-feed NOT running"
[ "${TICK_N:-0}"  -le 1 ]  && pass "No duplicate tick process"            || warn "Multiple tick processes: $TICK_N"
[ "${STREAM_N:-0}" -le 1 ] && pass "user-stream OK (count=$STREAM_N)"     || warn "Multiple user-stream processes: $STREAM_N"

# =============================================================================
section "4. Supervisor Status"
# =============================================================================
if command -v supervisorctl &>/dev/null; then
  SUP_OUT="$(supervisorctl status 2>&1 || true)"
  echo "$SUP_OUT"
  if echo "$SUP_OUT" | grep -qiE "STOPPED|FATAL|EXITED|ERROR"; then
    fail "One or more supervisor processes are not RUNNING"
  else
    pass "All supervisor processes appear RUNNING"
  fi
else
  warn "supervisorctl not found (shared host cron mode?)"
fi

# =============================================================================
section "5. Binance REST Circuit Breaker + IP Ban Status"
# =============================================================================
if command -v redis-cli &>/dev/null; then
  BAN_UNTIL_RAW="$(redis-cli get 'autofinx:binance:rest:ban_until' 2>/dev/null || true)"
  LAST_ERR_RAW="$(redis-cli get 'autofinx:binance:rest:last_error' 2>/dev/null || true)"
else
  BAN_UNTIL_RAW="$(tinker_run "echo Cache::get('autofinx:binance:rest:ban_until') ?? '0';")"
  LAST_ERR_RAW="$(tinker_run "echo Cache::get('autofinx:binance:rest:last_error') ?? '';")"
fi

BAN_UNTIL="${BAN_UNTIL_RAW//[^0-9]/}"
BAN_UNTIL="${BAN_UNTIL:-0}"
NOW_EPOCH="$(date +%s)"

if [ "$BAN_UNTIL" -gt "$NOW_EPOCH" ]; then
  BAN_REMAINING=$(( BAN_UNTIL - NOW_EPOCH ))
  BAN_DATE="$(date -d "@$BAN_UNTIL" '+%Y-%m-%d %H:%M:%S' 2>/dev/null || date -r "$BAN_UNTIL" '+%Y-%m-%d %H:%M:%S' 2>/dev/null || echo "unknown")"
  fail "BINANCE IP BANNED — lifts at $BAN_DATE (${BAN_REMAINING}s remaining)"
  info "Last error: $LAST_ERR_RAW"
else
  pass "Binance REST circuit breaker: CLEAR (no active ban)"
fi

# Per-host circuit breaker (binance_cb:fapi.binance.com)
if command -v redis-cli &>/dev/null; then
  CB_RAW="$(redis-cli get 'binance_cb:fapi.binance.com' 2>/dev/null || true)"
else
  CB_RAW="$(tinker_run "echo Cache::get('binance_cb:fapi.binance.com') ?? '';")"
fi
if echo "$CB_RAW" | grep -q '"open":true'; then
  fail "Per-host circuit breaker OPEN for fapi.binance.com: $CB_RAW"
else
  pass "Per-host circuit breaker for fapi.binance.com: closed"
fi

# =============================================================================
section "6. Per-Config API Key Health (binance_key_dead check)"
# =============================================================================
if [ -n "$PHP" ]; then
  tinker_run "
    try {
      \$configs = App\\Models\\FutureTradeBotConfig
        ::where('workspace_id','$WS_ID')
        ->orderBy('slot_index')->get();

      if (\$configs->isEmpty()) {
        echo 'No configs found for workspace $WS_ID' . PHP_EOL;
        return;
      }

      \$anyDead = false;
      foreach (\$configs as \$c) {
        \$settings = \$c->mergedSettings();
        \$useLive  = (\$settings['liveTrading'] ?? false);
        \$apiKey   = \$useLive
          ? (string)(\$settings['liveApiKey'] ?? '')
          : (string)(\$settings['testnetApiKey'] ?? '');

        if (\$apiKey === '') {
          echo sprintf('Slot %d | config=%s | NO API KEY SET'.PHP_EOL,
            (int)\$c->slot_index, (string)\$c->id);
          continue;
        }

        \$cacheKey = 'autofinx:binance:key_health:' . substr(hash('sha256', \$apiKey), 0, 16);
        \$health   = Cache::get(\$cacheKey, 'unknown');
        \$mode     = \$useLive ? 'LIVE' : 'TESTNET';
        \$enabled  = \$c->enabled ? 'ON' : 'OFF';

        if (\$health === 'dead_auth') {
          \$anyDead = true;
          echo sprintf('DEAD  | Slot %d | %-8s | %-7s | config=%s | KEY IS DEAD (-2015/-2014) — fix Binance key then save config'.PHP_EOL,
            (int)\$c->slot_index, \$enabled, \$mode, (string)\$c->id);
        } elseif (\$health === 'ok') {
          echo sprintf('OK    | Slot %d | %-8s | %-7s | config=%s'.PHP_EOL,
            (int)\$c->slot_index, \$enabled, \$mode, (string)\$c->id);
        } else {
          echo sprintf('NEW   | Slot %d | %-8s | %-7s | config=%s | no health record yet'.PHP_EOL,
            (int)\$c->slot_index, \$enabled, \$mode, (string)\$c->id);
        }
      }
    } catch (\\Throwable \$e) {
      echo 'Key health check error: ' . \$e->getMessage() . PHP_EOL;
    }
  "
fi

# =============================================================================
section "7. createListenKey Global Throttle Status"
# =============================================================================
if command -v redis-cli &>/dev/null; then
  CLK_LAST="$(redis-cli get 'autofinx_ft:create_listen_key_last_ms' 2>/dev/null || true)"
else
  CLK_LAST="$(tinker_run "echo Cache::get('autofinx_ft:create_listen_key_last_ms') ?? '0';")"
fi
CLK_MS="${CLK_LAST//[^0-9]/}"
CLK_MS="${CLK_MS:-0}"
NOW_MS=$(( NOW_EPOCH * 1000 ))
if [ "$CLK_MS" -gt 0 ]; then
  AGE_MS=$(( NOW_MS - CLK_MS ))
  info "Last createListenKey call: ${AGE_MS}ms ago (throttle=1200ms)"
  [ "$AGE_MS" -gt 1200 ] && pass "createListenKey throttle: OK (no recent storm)" \
                          || warn "createListenKey throttle: recent call ${AGE_MS}ms ago — may be mid-connect"
else
  pass "createListenKey throttle: no recent calls recorded"
fi

# =============================================================================
section "8. User Stream Status per Config"
# =============================================================================
if [ -n "$PHP" ]; then
  tinker_run "
    try {
      \$configs = App\\Models\\FutureTradeBotConfig
        ::where('workspace_id','$WS_ID')
        ->get();
      foreach (\$configs as \$c) {
        \$key   = 'autofinx_ft:user_stream:' . \$c->id . ':state';
        \$state = Cache::get(\$key, []);
        \$status   = (string)(\$state['status'] ?? 'UNKNOWN');
        \$hbAt     = (string)(\$state['last_heartbeat_at'] ?? 'never');
        \$eventAt  = (string)(\$state['last_event_at'] ?? 'never');
        \$lastErr  = (string)(\$state['last_error'] ?? '');
        \$hasKey   = !empty(\$state['listen_key']) ? 'has_key' : 'no_key';

        \$age = '';
        if (!empty(\$state['last_heartbeat_at'])) {
          try {
            \$age = (int)\\Carbon\\Carbon::parse(\$state['last_heartbeat_at'])->diffInSeconds(now(), true) . 's ago';
          } catch(\\Throwable \$_) {}
        }

        echo sprintf('Slot %d | %-12s | %-8s | heartbeat=%s %s | event=%s%s'.PHP_EOL,
          (int)\$c->slot_index,
          \$status,
          \$hasKey,
          \$hbAt,
          \$age ? \"(\$age)\" : '',
          \$eventAt,
          \$lastErr ? ' | ERR: '.substr(\$lastErr, 0, 80) : '');
      }
    } catch(\\Throwable \$e) {
      echo 'User stream status error: ' . \$e->getMessage() . PHP_EOL;
    }
  "
fi

# =============================================================================
section "9. Binance Network Connectivity"
# =============================================================================
# REST API ping
if [ -n "$PHP" ]; then
  REST_RESULT="$("$PHP" -r '
    $ch = curl_init("https://fapi.binance.com/fapi/v1/ping");
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT        => 6,
      CURLOPT_CONNECTTIMEOUT => 4,
      CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err) { echo "FAIL:$err"; }
    elseif ($code === 200 && $body === "{}") { echo "OK:${code}"; }
    else { echo "WARN:HTTP $code body=".substr($body,0,100); }
  ' 2>/dev/null || echo "FAIL:php error")"
  case "$REST_RESULT" in
    OK:*) pass "Binance fapi REST ping: $REST_RESULT" ;;
    WARN:*) warn "Binance fapi REST ping: $REST_RESULT" ;;
    *) fail "Binance fapi REST ping: $REST_RESULT" ;;
  esac

  # WebSocket handshake
  WS_RESULT="$("$PHP" -r '
    $sock = @stream_socket_client("tls://fstream.binance.com:443", $errno, $errstr, 8);
    if (!$sock) { echo "FAIL:$errstr"; exit; }
    stream_set_timeout($sock, 5);
    $key = base64_encode(random_bytes(16));
    fwrite($sock, "GET /stream?streams=btcusdt@trade HTTP/1.1\r\nHost: fstream.binance.com\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n\r\n");
    $buf = ""; $t = microtime(true)+5;
    while (microtime(true)<$t && !str_contains($buf,"\r\n\r\n")) {
      $r=[$sock];$w=$x=null;
      if (@stream_select($r,$w,$x,0,200000)>0) $buf .= fread($sock,4096);
    }
    fclose($sock);
    echo str_contains($buf," 101 ") ? "OK:101_Switching_Protocols" : "FAIL:".trim(explode("\r\n",$buf)[0]??"no_response");
  ' 2>/dev/null || echo "FAIL:php error")"
  case "$WS_RESULT" in
    OK:*) pass "Binance fstream WebSocket handshake: $WS_RESULT" ;;
    *) fail "Binance fstream WebSocket: $WS_RESULT" ;;
  esac
fi

# =============================================================================
section "10. Queue Health"
# =============================================================================
if [ -n "$PHP" ]; then
  QUEUE_RESULT="$(tinker_run "
    try {
      \$total_pending = DB::table('jobs')->count();
      \$total_failed  = DB::table('failed_jobs')->count();
      \$exec_pending  = DB::table('jobs')->where('queue','execution')->count();
      \$oldest_job    = DB::table('jobs')->min('created_at');
      \$oldest_age    = \$oldest_job ? max(0, time() - (int)\$oldest_job) : 0;
      echo \"total_pending={\$total_pending} execution_queue={\$exec_pending} failed={\$total_failed} oldest_age={\$oldest_age}s\";
    } catch (\\Throwable \$e) { echo 'error: '.\$e->getMessage(); }
  ")"
  echo "  $QUEUE_RESULT"
  # Parse and check
  FAILED_Q="$(echo "$QUEUE_RESULT" | grep -oP 'failed=\K\d+')"
  OLDEST_Q="$(echo "$QUEUE_RESULT" | grep -oP 'oldest_age=\K\d+')"
  [ "${FAILED_Q:-0}" -eq 0 ]    && pass "No failed jobs" || fail "Failed jobs: $FAILED_Q — run: php artisan queue:retry all"
  [ "${OLDEST_Q:-0}" -le 300 ]  && pass "No stale queued jobs (oldest=${OLDEST_Q:-0}s)" || warn "Stale queued job: ${OLDEST_Q}s old"
fi

# =============================================================================
section "11. Recent PINE_WEBHOOK_JOB_DONE Stats (last 2h)"
# =============================================================================
TWOHOURSAGO="$(date -d '2 hours ago' '+%Y-%m-%d %H:%M:%S' 2>/dev/null || date -v -2H '+%Y-%m-%d %H:%M:%S' 2>/dev/null || echo '')"
if [ -f storage/logs/laravel.log ] && [ -n "$TWOHOURSAGO" ]; then
  # Count by execution result
  echo "  Execution breakdown (last 2h):"
  grep "PINE_WEBHOOK_JOB_DONE" storage/logs/laravel.log \
    | awk -v cutoff="$TWOHOURSAGO" '$0 >= "[" cutoff "]"' \
    | grep -oP '"execution":"[^"]+"' \
    | sort | uniq -c | sort -rn \
    | sed 's/^/    /'

  # Filter last 2h only using awk (works on all Linux)
  RECENT_LOG="$(awk -v cutoff="[$TWOHOURSAGO]" '$0 >= cutoff' storage/logs/laravel.log 2>/dev/null || true)"

  # Check for -2015 in last 2h
  DEAD_KEY_COUNT="$(echo "$RECENT_LOG" | grep -c 'binance_key_dead\|Invalid API-key' 2>/dev/null || true)"
  if [ "${DEAD_KEY_COUNT:-0}" -gt 0 ]; then
    fail "-2015/dead-key errors in last 2h: $DEAD_KEY_COUNT occurrences — fix Binance API key"
  else
    pass "No -2015 (dead key) errors in last 2h"
  fi

  # Check for -1003 IP ban in last 2h
  BAN_COUNT="$(echo "$RECENT_LOG" | grep -c '\-1003\|Way too many requests\|banned until' 2>/dev/null || true)"
  if [ "${BAN_COUNT:-0}" -gt 0 ]; then
    fail "-1003 IP ban errors in last 2h: $BAN_COUNT — check Section 5 ban status"
  else
    pass "No -1003 (IP ban) errors in last 2h"
  fi

  # Check for blocked_with_logged_reason in last 2h
  TIMEOUT_BLOCKS="$(echo "$RECENT_LOG" | grep -c 'blocked_with_logged_reason' 2>/dev/null || true)"
  if [ "${TIMEOUT_BLOCKS:-0}" -gt 0 ]; then
    warn "blocked_with_logged_reason in last 2h: $TIMEOUT_BLOCKS times"
  else
    pass "No blocked_with_logged_reason timeouts in last 2h"
  fi

  # Job timeouts in last 2h
  JOB_TIMEOUTS="$(echo "$RECENT_LOG" | grep -c 'has timed out' 2>/dev/null || true)"
  if [ "${JOB_TIMEOUTS:-0}" -gt 0 ]; then
    fail "ProcessPineWebhookSignalJob timed out in last 2h: $JOB_TIMEOUTS times"
    echo "$RECENT_LOG" | grep 'has timed out' | tail -3 | sed 's/^/    /'
  else
    pass "No job timeouts in last 2h"
  fi

  # Show last 5 executed trades
  echo ""
  echo "  Last 5 EXECUTED trades:"
  grep 'PINE_WEBHOOK_JOB_DONE' storage/logs/laravel.log \
    | grep '"execution":"executed"' \
    | tail -5 \
    | grep -oP '\[\K[^\]]+(?=\])|\{"workspace[^}]+\}' \
    | sed 's/^/    /'

  # Latency stats on executed trades
  echo ""
  echo "  Recent latency (totalMs) on executed trades:"
  grep 'PINE_WEBHOOK_JOB_DONE' storage/logs/laravel.log \
    | grep '"execution":"executed"' \
    | grep -oP '"totalMs":\K\d+' \
    | sort -n \
    | awk 'BEGIN{min=99999;max=0;sum=0;cnt=0}
           {sum+=$1;cnt++;if($1<min)min=$1;if($1>max)max=$1}
           END{if(cnt>0)printf "    count=%d  min=%dms  max=%dms  avg=%dms\n",cnt,min,max,sum/cnt;
               else print "    No executed trades found"}' \
    || echo "    No latency data"
else
  warn "Laravel log not found or date command unavailable"
fi

# =============================================================================
section "11b. live-feed BACKOFF Diagnosis"
# If supervisor shows BACKOFF, dig into why
if command -v supervisorctl &>/dev/null; then
  FEED_STATUS="$(supervisorctl status autofinx-trading:live-feed 2>/dev/null || true)"
  if echo "$FEED_STATUS" | grep -qiE "BACKOFF|FATAL|STOPPED"; then
    fail "live-feed is in BACKOFF/FATAL — checking crash reason..."
    # Supervisor log
    SUP_LOG="$(supervisorctl tail autofinx-trading:live-feed 2>/dev/null | tail -20 || true)"
    if [ -n "$SUP_LOG" ]; then
      info "Supervisor tail for live-feed:"
      echo "$SUP_LOG" | sed 's/^/  /'
    fi
    # Stderr log
    FEED_ERR="$(tail -30 storage/logs/future-trade-live-feed.error.log 2>/dev/null || true)"
    [ -n "$FEED_ERR" ] && { info "live-feed stderr:"; echo "$FEED_ERR" | sed 's/^/  /'; }
    # Quick PHP syntax check
    if [ -n "$PHP" ]; then
      info "PHP syntax check on live-feed command..."
      "$PHP" -l app/Console/Commands/FutureTraceLiveFeedCommand.php 2>&1 || \
      "$PHP" -l app/Console/Commands/FutureTradeLiveFeedCommand.php 2>&1 || \
      find app/Console/Commands -name "*live*feed*" -o -name "*LiveFeed*" 2>/dev/null | while read -r f; do
        "$PHP" -l "$f" 2>&1
      done
      info "PHP syntax check on all recently-changed files..."
      for f in \
        app/Domain/Exchange/BinanceFuturesClient.php \
        app/Domain/FutureTrade/FutureTradeUserStreamService.php \
        app/Console/Commands/FutureTradeUserStreamCommand.php \
        app/Domain/FutureTrade/FutureTradeBotService.php; do
        [ -f "$f" ] && "$PHP" -l "$f" 2>&1 | sed 's/^/  /'
      done
    fi
  else
    pass "live-feed not in BACKOFF state"
  fi
fi

section "12. Live Feed Heartbeat"
# =============================================================================
HB_FILE="storage/framework/autofinx-live-feed-heartbeat.json"
if [ -f "$HB_FILE" ]; then
  HB_EPOCH="$("$PHP" -r '
    $j=json_decode(file_get_contents($argv[1]),true);
    echo (int)($j["last_message_epoch"]??0);
  ' "$HB_FILE" 2>/dev/null || echo 0)"
  HB_AGE=$(( NOW_EPOCH - HB_EPOCH ))
  cat "$HB_FILE" | "$PHP" -r 'echo json_encode(json_decode(stream_get_contents(STDIN)),JSON_PRETTY_PRINT).PHP_EOL;' 2>/dev/null || cat "$HB_FILE"
  [ "$HB_AGE" -le 30 ] && pass "Live-feed heartbeat fresh: ${HB_AGE}s ago" \
                        || fail "Live-feed heartbeat STALE: ${HB_AGE}s ago (should be <30s)"
else
  fail "Live-feed heartbeat file missing: $HB_FILE"
fi

# =============================================================================
section "13. Code Fix Verification (our session fixes deployed?)"
# =============================================================================
BINANCE_CLIENT="app/Domain/Exchange/BinanceFuturesClient.php"
USER_STREAM_SVC="app/Domain/FutureTrade/FutureTradeUserStreamService.php"
USER_STREAM_CMD="app/Console/Commands/FutureTradeUserStreamCommand.php"
BOT_SERVICE="app/Domain/FutureTrade/FutureTradeBotService.php"

# Fix 1: Fast-fail on -1003
if grep -q 'binanceCode.*-1003\|Fast-fail on IP ban' "$BINANCE_CLIENT" 2>/dev/null; then
  pass "Fix#1 deployed: -1003 fast-fail in BinanceFuturesClient"
else
  fail "Fix#1 MISSING: -1003 fast-fail not found in $BINANCE_CLIENT"
fi

# Fix 2: Fast-fail on -2015
if grep -q '\-2015' "$BINANCE_CLIENT" 2>/dev/null; then
  pass "Fix#2 deployed: -2015 fast-fail in BinanceFuturesClient"
else
  fail "Fix#2 MISSING: -2015 fast-fail not in $BINANCE_CLIENT"
fi

# Fix 3: Key health cache
if grep -q 'KEY_HEALTH_DEAD\|key_health\|markKeyDead' "$BINANCE_CLIENT" 2>/dev/null; then
  pass "Fix#3 deployed: per-key health cache in BinanceFuturesClient"
else
  fail "Fix#3 MISSING: key health cache not found in $BINANCE_CLIENT"
fi

# Fix 4: assertKeyHealthy in signedRequest
if grep -q 'assertKeyHealthy' "$BINANCE_CLIENT" 2>/dev/null; then
  pass "Fix#4 deployed: assertKeyHealthy() guard in signedRequest/keyedRequest"
else
  fail "Fix#4 MISSING: assertKeyHealthy() not found in $BINANCE_CLIENT"
fi

# Fix 5: Timeout reduced (6s or less)
TIMEOUT_VAL="$(grep -oP '->timeout\(\K\d+' "$BINANCE_CLIENT" 2>/dev/null | head -1)"
if [ -n "$TIMEOUT_VAL" ] && [ "$TIMEOUT_VAL" -le 8 ]; then
  pass "Fix#5 deployed: HTTP timeout = ${TIMEOUT_VAL}s (≤8s)"
else
  warn "Fix#5 check: HTTP timeout = ${TIMEOUT_VAL:-unknown}s (expected ≤8s)"
fi

# Fix 6: 2 retry attempts (was 4)
if grep -q 'delaysMs.*=.*\[0.*400\]' "$BINANCE_CLIENT" 2>/dev/null; then
  pass "Fix#6 deployed: retry slots reduced to 2"
else
  warn "Fix#6 check: [0,400] retry pattern not found"
fi

# Fix 7: Global createListenKey throttle
if grep -q 'CLK_THROTTLE_KEY\|CLK_MIN_INTERVAL' "$USER_STREAM_SVC" 2>/dev/null; then
  pass "Fix#7 deployed: createListenKey global throttle in UserStreamService"
else
  fail "Fix#7 MISSING: createListenKey throttle not found in $USER_STREAM_SVC"
fi

# Fix 8: Ban check before createListenKey
if grep -q 'BinanceRestCircuitBreaker\|global ban active' "$USER_STREAM_SVC" 2>/dev/null; then
  pass "Fix#8 deployed: ban check before createListenKey"
else
  fail "Fix#8 MISSING: ban check not found in $USER_STREAM_SVC"
fi

# Fix 9: Stagger in command
if grep -q 'staggerMs\|Stagger.*config' "$USER_STREAM_CMD" 2>/dev/null; then
  pass "Fix#9 deployed: per-config stagger in UserStreamCommand"
else
  fail "Fix#9 MISSING: stagger not found in $USER_STREAM_CMD"
fi

# Fix 10: clearKeyHealth on config save
if grep -q 'clearKeyHealth' "$BOT_SERVICE" 2>/dev/null; then
  pass "Fix#10 deployed: clearKeyHealth() on config save in BotService"
else
  fail "Fix#10 MISSING: clearKeyHealth() not in $BOT_SERVICE"
fi

# =============================================================================
section "14. Slot Map (open positions)"
# =============================================================================
if [ -n "$PHP" ]; then
  tinker_run "
    try {
      \$slots = App\\Models\\FutureTradeBotConfig::where('workspace_id','$WS_ID')
        ->orderBy('slot_index')->get();
      if (\$slots->isEmpty()) { echo 'No slots found'.PHP_EOL; return; }
      \$open = 0;
      foreach (\$slots as \$s) {
        \$st   = \$s->state;
        \$qty  = \$st ? round((float)(\$st->quantity ?? 0), 6) : 0;
        \$side = \$st ? strtoupper((string)(\$st->position_side ?? '-')) : '-';
        \$sym  = \$st ? strtoupper((string)(\$st->current_symbol ?? '-'))  : '-';
        \$state= \$st ? (string)(\$st->state ?? 'IDLE') : 'NO_STATE';
        \$en   = \$s->enabled ? 'ON ' : 'OFF';
        \$live = \$s->enable_live_trading ? 'LIVE' : 'PAPER';
        \$posOpen = \$qty > 0 || in_array(\$state, ['IN_POSITION','LIVE_IN_POSITION']);
        if (\$posOpen) \$open++;
        \$flag = \$posOpen ? '>>> OPEN POSITION' : '    flat';
        echo sprintf('Slot%d | %-3s | %-5s | sym=%-10s side=%-5s qty=%-12s state=%-20s %s'.PHP_EOL,
          (int)\$s->slot_index, \$en, \$live, \$sym, \$side, \$qty, \$state, \$flag);
      }
      echo PHP_EOL.'Active positions: '.\$open.'/'.count(\$slots).PHP_EOL;
    } catch (\\Throwable \$e) { echo 'Slot map error: '.\$e->getMessage().PHP_EOL; }
  "
fi

# =============================================================================
section "15. Recent Laravel Errors (last 50 lines, filtered)"
# =============================================================================
if [ -f storage/logs/laravel.log ]; then
  ERRORS="$(grep -E "\.ERROR:|\.CRITICAL:|SQLSTATE|Uncaught|Fatal error" storage/logs/laravel.log \
    | grep -v "stty\|Terminal\.php" \
    | tail -10 || true)"
  if [ -n "$ERRORS" ]; then
    warn "Recent error lines found:"
    echo "$ERRORS" | sed 's/^/  /'
  else
    pass "No critical errors in recent Laravel log"
  fi
fi

# =============================================================================
section "16. Crontab Check"
# =============================================================================
CRON="$(crontab -l 2>/dev/null | grep -E "autofinx|future-trade|heartbeat|maintenance|schedule:run" || true)"
if [ -n "$CRON" ]; then
  pass "AutoFinX cron entries found:"
  echo "$CRON" | sed 's/^/  /'
else
  warn "No AutoFinX cron entries found — make sure heartbeat/maintenance crons are set"
fi

# =============================================================================
section "FINAL SUMMARY"
# =============================================================================
TOTAL=$(( PASS + WARN + FAIL ))
printf "\n${BOLD}%-12s %d / %d checks${RESET}\n" "PASS" "$PASS" "$TOTAL"
printf "%-12s %d\n" "WARN" "$WARN"
printf "%-12s %d\n" "FAIL" "$FAIL"
printf "%-12s %s\n" "Report" "$REPORT_FILE"

if [ "${#SECTION_FAILS[@]}" -gt 0 ]; then
  printf "\n${RED}${BOLD}FAILURES SUMMARY:${RESET}\n"
  for f in "${SECTION_FAILS[@]}"; do
    printf "  ${RED}✗${RESET} %s\n" "$f"
  done
fi

echo ""
if [ "$FAIL" -eq 0 ] && [ "$WARN" -eq 0 ]; then
  printf "${GREEN}${BOLD}✓ ALL CHECKS PASSED — SYSTEM FULLY HEALTHY${RESET}\n"
  exit 0
elif [ "$FAIL" -eq 0 ]; then
  printf "${YELLOW}${BOLD}⚠ PASSES WITH WARNINGS — review WARN items above${RESET}\n"
  exit 0
else
  printf "${RED}${BOLD}✗ $FAIL FAILURE(S) — review FAIL items above${RESET}\n"
  exit 1
fi
