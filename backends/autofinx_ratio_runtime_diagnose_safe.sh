#!/usr/bin/env bash
set -u

# AutoFinX Ratio Bot / Scheduler / Supervisor / Queue / Feed — SAFE diagnostic.
# READ-ONLY. Does not restart, migrate, kill, or change any config.
# Does not print secrets (API keys/passwords/tokens are redacted).
#
# Usage (run as the app-owning user, e.g. zcadmin, from anywhere):
#   cd /home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends
#   bash autofinx_ratio_runtime_diagnose_safe.sh
#
# Paste the ENTIRE output back for analysis.

BACKEND_DIR="${BACKEND_DIR:-$(pwd)}"
STAMP="$(date +%Y%m%d-%H%M%S)"
REPORT_DIR="$BACKEND_DIR/autofinx-test-reports/ratio-runtime-diagnose-$STAMP"
REPORT="$REPORT_DIR/report.txt"
mkdir -p "$REPORT_DIR" 2>/dev/null
cd "$BACKEND_DIR" || { echo "FATAL: cannot cd to $BACKEND_DIR"; exit 1; }

log(){ echo -e "$*" | tee -a "$REPORT" 2>/dev/null || echo -e "$*"; }
section(){ log "\n===== $* =====\n"; }
run(){ log "\n\$ $*"; "$@" 2>&1 | tee -a "$REPORT" 2>/dev/null || "$@" 2>&1; }
mask_env(){ sed -E 's/(TOKEN|KEY|SECRET|PASSWORD|PASS)=.*/\1=***REDACTED***/gI'; }

section "0. Basic environment"
log "date: $(date)"
log "whoami: $(whoami)"
log "hostname: $(hostname)"
log "pwd: $(pwd)"

section "1. PHP binary detection (per this repo's own deploy scripts)"
PHP_BIN="${PHP_BIN:-}"
if [ -z "$PHP_BIN" ]; then
  for p in /opt/cpanel/ea-php83/root/usr/bin/php /opt/cpanel/ea-php82/root/usr/bin/php \
           /usr/local/bin/ea-php83 /usr/local/bin/ea-php82 \
           /usr/bin/php8.3 /usr/bin/php8.2 /usr/local/bin/php /usr/bin/php; do
    [ -x "$p" ] || continue
    vid="$($p -r 'echo PHP_VERSION_ID;' 2>/dev/null || true)"
    if [ -n "$vid" ] && [ "$vid" -ge 80200 ]; then PHP_BIN="$p"; break; fi
  done
fi
PHP_BIN="${PHP_BIN:-$(command -v php || echo php)}"
log "Selected PHP_BIN=$PHP_BIN"
log "Also checking what the bare 'php' command resolves to (mismatch risk):"
run command -v php
run php -v
run "$PHP_BIN" -v
run "$PHP_BIN" artisan --version

section "2. Laravel/env summary (php artisan about)"
run "$PHP_BIN" artisan about

section "3. Full scheduled command list + next run time (schedule:list)"
run "$PHP_BIN" artisan schedule:list

section "4. Crontab — current user ($(whoami))"
run crontab -l

section "4b. Crontab — zcadmin (app-owning user, in case this script runs as root)"
run crontab -l -u zcadmin

section "5. Crontab — system-wide locations (best-effort; permission denied is fine, just note it)"
for f in /etc/crontab /etc/cron.d/*; do
  if [ -e "$f" ]; then
    log "--- $f ---"
    cat "$f" 2>&1 | tee -a "$REPORT" 2>/dev/null || cat "$f" 2>&1
  fi
done
run ls -la /etc/cron.d/

section "6. Supervisor — full status of every program"
run supervisorctl status

section "7. Supervisor — config file contents (autofinx programs only)"
FOUND_CONF=0
for dir in /etc/supervisor/conf.d /etc/supervisord.d /etc/supervisor.d; do
  if [ -d "$dir" ]; then
    for f in $(grep -rl "autofinx" "$dir" 2>/dev/null); do
      FOUND_CONF=1
      log "--- $f ---"
      cat "$f" 2>&1 | tee -a "$REPORT" 2>/dev/null || cat "$f" 2>&1
    done
  fi
done
if [ "$FOUND_CONF" = "0" ]; then
  log "No /etc/supervisor* conf files with 'autofinx' found (may need sudo, or confs live elsewhere). Showing repo's own supervisor/ dir as reference:"
  run ls -la "$BACKEND_DIR/supervisor/"
  for f in "$BACKEND_DIR"/supervisor/*.conf; do
    [ -e "$f" ] && log "--- $f (repo copy, may not be the deployed one) ---" && cat "$f" 2>&1 | tee -a "$REPORT" 2>/dev/null
  done
fi

section "8. Running processes — scheduler / queue / feed / websocket / user-stream / horizon"
run bash -c "ps -eo pid,etimes,cmd | grep -E 'artisan (schedule:work|schedule:run|queue:work|horizon|market:ws-consume|future-trade:live-feed|future-trade:tick|future-trade:user-stream|trading:binance-kline-stream|trading:binance-user-stream|trading:binance-futures-user-stream|fast2x:run|quick-trades:settle|robot-sessions:evaluate|ratio-bots:evaluate|runtime:repair)|autofinx-runtime-master' | grep -v grep"

section "9. autofinx-runtime-master.sh and deploy scripts — executable bits"
run ls -la "$BACKEND_DIR/autofinx-runtime-master.sh"
run ls -la "$BACKEND_DIR/autofinx_post_deploy_all.sh"
run bash -c "ls -la '$BACKEND_DIR'/database/scripts/*.sh 2>&1 | head -40"
run bash -c "ls -la '$BACKEND_DIR'/*.sh 2>&1"

section "10. Queue depth per queue (read-only count, no dequeue)"
"$PHP_BIN" artisan tinker --execute='
echo "queue.default = " . config("queue.default") . PHP_EOL;
$queues = ["critical","execution","market","signals","strategies","ratio_bot","ops","monitoring","monitoring_safe","reconcile","billing","intelligence","risk","analytics","copytrading","notifications","default"];
if (config("queue.default") === "redis") {
    $conn = config("queue.connections.redis.connection", "default");
    foreach ($queues as $q) {
        try { echo $q . ": " . \Illuminate\Support\Facades\Redis::connection($conn)->llen("queues:" . $q) . PHP_EOL; }
        catch (\Throwable $e) { echo $q . ": ERROR " . $e->getMessage() . PHP_EOL; }
    }
} else {
    foreach ($queues as $q) {
        try { echo $q . ": " . \Illuminate\Support\Facades\DB::table("jobs")->where("queue", $q)->count() . PHP_EOL; }
        catch (\Throwable $e) { echo $q . ": ERROR " . $e->getMessage() . PHP_EOL; }
    }
}
try { echo "failed_jobs total: " . \Illuminate\Support\Facades\DB::table("failed_jobs")->count() . PHP_EOL; } catch (\Throwable $e) { echo "failed_jobs: ERROR " . $e->getMessage() . PHP_EOL; }
' 2>&1 | tee -a "$REPORT" 2>/dev/null

section "11. Ratio Bot state snapshot (counts only, no secrets)"
"$PHP_BIN" artisan tinker --execute='
try {
    $rows = \App\Models\RatioBotConfig::with("state")->get();
    echo "total ratio bot configs: " . $rows->count() . PHP_EOL;
    echo "enabled=true: " . $rows->where("enabled", true)->count() . PHP_EOL;
    echo "enabled=false: " . $rows->where("enabled", false)->count() . PHP_EOL;
    $byState = $rows->groupBy(fn($c) => $c->state->position_state ?? "no_state_row");
    foreach ($byState as $state => $group) {
        echo "position_state=" . $state . ": " . $group->count() . PHP_EOL;
    }
    echo PHP_EOL . "-- up to 15 currently blocked/paused bots (id + reason, no credentials) --" . PHP_EOL;
    $rows->filter(fn($c) => in_array($c->state->position_state ?? null, ["blocked","paused"], true))
        ->take(15)
        ->each(function ($c) {
            echo "id=" . $c->id
                . " symbol=" . $c->symbol
                . " enabled=" . ((int) $c->enabled)
                . " state=" . ($c->state->position_state ?? "")
                . " reason=" . ($c->state->block_reason_code ?? "")
                . " detail=" . substr((string) ($c->state->block_reason_detail ?? ""), 0, 120)
                . PHP_EOL;
        });
} catch (\Throwable $e) { echo "ERROR: " . $e->getMessage() . PHP_EOL; }
' 2>&1 | tee -a "$REPORT" 2>/dev/null

section "12. Key operational env flags (safe, non-secret keys only — no passwords/API keys printed)"
if [ -f .env ]; then
  grep -E "^(APP_ENV|APP_DEBUG|QUEUE_CONNECTION|DB_QUEUE_CONNECTION|REDIS_QUEUE_CONNECTION|CACHE_STORE|CACHE_DRIVER|SESSION_DRIVER|BROADCAST_CONNECTION|BROADCAST_DRIVER|HORIZON_ENABLED|AUTOFINX_RUNTIME_OWNER|AUTOFINX_SUPERVISOR_RUNTIME|AUTOFINX_SCHEDULER_ENABLED|AUTOFINX_SCHEDULER_MARKET_CONSUMERS|AUTOFINX_SCHEDULER_QUICK_SETTLER|AUTOFINX_SCHEDULER_ROBOT_SESSIONS|AUTOFINX_SCHEDULER_RUNTIME_AUTO_REPAIR|AUTOFINX_SHARED_HOSTING_MODE|FUTURE_TRADE_SCHEDULE_RUNTIME_COMMANDS|FUTURE_TRADE_ENABLE_LIVE_TRADING|FUTURE_TRADE_ENABLE_TESTNET_TRADING|FUTURE_TRADE_FORCE_TESTNET|LIVE_TRADING_ENABLED|ENABLE_LIVE_TRADING|PAPER_TRADING|BINANCE_ENABLED|BINANCE_TESTNET)=" .env | mask_env | tee -a "$REPORT" 2>/dev/null
else
  log ".env not found at $BACKEND_DIR/.env"
fi

section "13. Effective config() values for the disputed/duplicate-key flags"
"$PHP_BIN" artisan tinker --execute='
echo "future_trade.schedule.runtime_commands = " . (config("future_trade.schedule.runtime_commands") ? "true" : "false") . PHP_EOL;
echo "future_trade.schedule_runtime_commands = " . (config("future_trade.schedule_runtime_commands") ? "true" : "false") . PHP_EOL;
echo "autofinx.scheduler.quick_settler = " . (config("autofinx.scheduler.quick_settler") ? "true" : "false") . PHP_EOL;
echo "autofinx.scheduler.robot_sessions = " . (config("autofinx.scheduler.robot_sessions") ? "true" : "false") . PHP_EOL;
echo "autofinx.scheduler.market_consumers = " . (config("autofinx.scheduler.market_consumers") ? "true" : "false") . PHP_EOL;
echo "autofinx.scheduler.ratio_bots = " . (config("autofinx.scheduler.ratio_bots") ? "true" : "false") . PHP_EOL;
echo "autofinx.supervisor_runtime = " . (config("autofinx.supervisor_runtime") ? "true" : "false") . PHP_EOL;
echo "autofinx.runtime_owner = " . (string) config("autofinx.runtime_owner") . PHP_EOL;
' 2>&1 | tee -a "$REPORT" 2>/dev/null

section "14. Built-in read-only diagnostic commands (safe — no --apply flags used)"
run "$PHP_BIN" artisan autofinx:config-doctor
run "$PHP_BIN" artisan autofinx:runtime-health
run "$PHP_BIN" artisan autofinx:market-health --details
run "$PHP_BIN" artisan trading:health-check --json
run "$PHP_BIN" artisan future-trade:runtime-status
run "$PHP_BIN" artisan future-trade:queue-health

section "15. Market feed heartbeat files"
run bash -c "ls -la '$BACKEND_DIR'/storage/framework/autofinx-market-ws-heartbeat-*.json 2>&1"
for f in "$BACKEND_DIR"/storage/framework/autofinx-market-ws-heartbeat-*.json; do
  [ -e "$f" ] && log "--- $f ---" && cat "$f" 2>&1 | tee -a "$REPORT" 2>/dev/null && log ""
done

section "16. Recent relevant log lines (last 250 matching lines, most recent log file)"
LOGFILE=$(ls -t storage/logs/laravel*.log 2>/dev/null | head -1)
log "using logfile: ${LOGFILE:-none found}"
if [ -n "${LOGFILE:-}" ]; then
  grep -iE "ratio.?bot|stale|blocked|RatioBotJob|schedule|runtime-master|autofinx-scheduler|RunRatioBotEvaluationJob" "$LOGFILE" | tail -250 | tee -a "$REPORT" 2>/dev/null
fi

section "17. Deploy script chmod-gate check (root cause of runtime-master FATAL)"
run bash -c "grep -n -B2 -A6 'chmod\|runtime-master' '$BACKEND_DIR/autofinx_post_deploy_all.sh'"

section "Done"
log "Report saved at: $REPORT"
log "Paste the FULL terminal output (or the contents of $REPORT) back for analysis."
