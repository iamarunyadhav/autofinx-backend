#!/usr/bin/env bash
set -u

# AutoFinX Future Trade SAFE live diagnostics.
# Read-only by default. It does NOT place orders and does NOT print API secrets.
# Usage:
#   cd /home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends
#   WORKSPACE_ID="26a36b56-4383-4edd-99d1-7622e7c191ef" bash autofinx_trade_live_diagnose_safe.sh

BACKEND_DIR="${BACKEND_DIR:-$(pwd)}"
WORKSPACE_ID="${WORKSPACE_ID:-}"
RUN_USER_STREAM_PROBE="${RUN_USER_STREAM_PROBE:-0}"
STAMP="$(date +%Y%m%d-%H%M%S)"
REPORT_DIR="$BACKEND_DIR/autofinx-test-reports/live-diagnose-safe-$STAMP"
REPORT="$REPORT_DIR/report.txt"
mkdir -p "$REPORT_DIR"
cd "$BACKEND_DIR" || exit 1

log(){ echo -e "$*" | tee -a "$REPORT"; }
section(){ log "\n================ $* ================\n"; }
run(){ log "\n$ $*"; "$@" 2>&1 | tee -a "$REPORT"; return ${PIPESTATUS[0]}; }
mask_env(){ sed -E 's/(TOKEN|KEY|SECRET)=.*/\1=***REDACTED***/g'; }

section "PHP / Laravel"
PHP_BIN="${PHP_BIN:-}"
if [ -z "$PHP_BIN" ]; then
  for p in /opt/cpanel/ea-php83/root/usr/bin/php /opt/cpanel/ea-php82/root/usr/bin/php /usr/local/bin/ea-php83 /usr/local/bin/ea-php82 /usr/bin/php8.3 /usr/bin/php8.2 /usr/local/bin/php /usr/bin/php; do
    [ -x "$p" ] || continue
    vid="$($p -r 'echo PHP_VERSION_ID;' 2>/dev/null || true)"
    if [ -n "$vid" ] && [ "$vid" -ge 80200 ]; then PHP_BIN="$p"; break; fi
  done
fi
PHP_BIN="${PHP_BIN:-$(command -v php || echo php)}"
log "Selected PHP_BIN=$PHP_BIN"
run "$PHP_BIN" -v
run "$PHP_BIN" artisan --version

section "Redacted .env important values"
if [ -f .env ]; then
  grep -nE 'APP_URL|QUEUE_CONNECTION|FUTURE_TRADE_(PINE|PRIME|SCHEDULE|ENABLE|FORCE|PAPER|SIGNAL)|BINANCE_(FUTURES|REST)' .env | mask_env | tee -a "$REPORT" || true
else
  log ".env missing"
fi

section "Laravel cache clear and config sanity"
run "$PHP_BIN" artisan optimize:clear
run "$PHP_BIN" artisan config:clear

section "Supervisor / process check"
run supervisorctl status || true
ps -eo pid,ppid,lstart,cmd | grep -E 'future-trade:live-feed|future-trade:user-stream|future-trade:tick|queue:work' | grep -v grep | tee -a "$REPORT" || true

section "Runtime status"
if [ -n "$WORKSPACE_ID" ]; then
  run "$PHP_BIN" artisan future-trade:runtime-status --json --workspace="$WORKSPACE_ID" || true
else
  run "$PHP_BIN" artisan future-trade:runtime-status --json || true
fi

section "Queue health / failed jobs"
run "$PHP_BIN" artisan future-trade:queue-health || true
run "$PHP_BIN" artisan queue:failed || true

section "Bot config credential mode check (redacted)"
if [ -n "$WORKSPACE_ID" ]; then
  "$PHP_BIN" artisan tinker --execute='
$ws=getenv("WORKSPACE_ID");
App\Models\FutureTradeBotConfig::with("state")->where("workspace_id",$ws)->orderBy("slot_index")->get()->each(function($c){
  $s=$c->mergedSettings();
  echo "slot=".($c->slot_index ?? 0)
    ." enabled=".((int)$c->enabled)
    ." table_live=".((int)$c->enable_live_trading)
    ." exec_mode=".$c->executionMode()
    ." envMode=".($s["envMode"] ?? "")
    ." liveTrading=".((int)($s["liveTrading"] ?? false))
    ." forceTestnet=".((int)($s["forceTestnet"] ?? true))
    ." envLiveEnabled=".((int)($s["envLiveEnabled"] ?? false))
    ." envTestnetEnabled=".((int)($s["envTestnetEnabled"] ?? false))
    ." liveKey=".(((string)($s["liveApiKey"] ?? "") !== "") ? "YES" : "NO")
    ." liveSecret=".(((string)($s["liveApiSecret"] ?? "") !== "") ? "YES" : "NO")
    ." testnetKey=".(((string)($s["testnetApiKey"] ?? "") !== "") ? "YES" : "NO")
    ." testnetSecret=".(((string)($s["testnetApiSecret"] ?? "") !== "") ? "YES" : "NO")
    ." state=".($c->state->state ?? "")
    ." current_symbol=".($c->state->current_symbol ?? "")
    .PHP_EOL;
});
' 2>&1 | tee -a "$REPORT" || true
else
  log "WORKSPACE_ID not set, skipping config credential check."
fi

section "Recent failed job exceptions"
"$PHP_BIN" artisan tinker --execute='
use Illuminate\Support\Facades\DB;
DB::table("failed_jobs")->orderByDesc("failed_at")->limit(8)->get()->each(function($r){
  echo "\n==== failed_job id={$r->id} queue={$r->queue} failed_at={$r->failed_at} ====\n";
  echo substr($r->exception ?? "", 0, 3500)."\n";
});
' 2>&1 | tee -a "$REPORT" || true

section "Recent trading logs"
if [ -f storage/logs/laravel.log ]; then
  grep -Ei 'PINE_WEBHOOK|LIVE_ENTRY_ERROR|LIVE_ENTRY_FAILED|LIVE_BUY|LIVE_SELL|TRADE_BLOCKED|SIGNAL_SKIPPED|BINANCE|ORDER|USER_STREAM|state_sync|exception|failed|1008|429|418|timestamp|recvWindow|insufficient|notional|leverage|margin' storage/logs/laravel.log | tail -n 350 | tee -a "$REPORT" || true
else
  log "storage/logs/laravel.log missing"
fi

section "Optional user-stream probe"
if [ "$RUN_USER_STREAM_PROBE" = "1" ]; then
  if [ -n "$WORKSPACE_ID" ]; then
    run "$PHP_BIN" artisan future-trade:user-stream --workspace="$WORKSPACE_ID" --duration=10 || true
  else
    run "$PHP_BIN" artisan future-trade:user-stream --duration=10 || true
  fi
else
  log "Skipped. Run with RUN_USER_STREAM_PROBE=1 only after verifying correct testnet/live credentials."
fi

section "Done"
log "Report: $REPORT"
