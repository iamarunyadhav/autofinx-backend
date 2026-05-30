#!/usr/bin/env bash
# ============================================================
#  AutoFinX — one-shot deploy script (no git pull)
#  Copy the changed files to the server first, then run this.
#
#  Usage:
#    bash deploy-no-pull.sh          # deploy only
#    bash deploy-no-pull.sh --cron   # deploy + install crontab
# ============================================================
set -euo pipefail

PHP="/usr/local/bin/ea-php82"
APP_DIR="/home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends"
INSTALL_CRON=0

for arg in "$@"; do
  case "$arg" in
    --cron) INSTALL_CRON=1 ;;
  esac
done

cd "$APP_DIR"
mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views

echo "============================================"
echo " AutoFinX deploy — $(date '+%Y-%m-%d %H:%M:%S')"
echo "============================================"

# ----------------------------------------------------------
# 1. PHP syntax check — only the files changed in this batch
# ----------------------------------------------------------
echo "[1/7] Syntax check..."
CHANGED_FILES=(
  "app/Domain/FutureTrade/FutureTradeBotService.php"
  "app/Domain/FutureTrade/FutureTradeBacktestEngine.php"
  "app/Console/Commands/FutureTradeTickCommand.php"
  "app/Console/Commands/FutureTradeBacktestCommand.php"
  "app/Http/Controllers/Api/FutureTradeBotController.php"
  "app/Models/FutureTradeBotConfig.php"
  "app/Models/FutureTradeBacktestRun.php"
  "app/Models/FutureTradeBacktestTrade.php"
)
for f in "${CHANGED_FILES[@]}"; do
  "$PHP" -l "$f"
done
echo "   Syntax OK."

# ----------------------------------------------------------
# 2. Stop old workers FIRST — free process slots before composer
#    Shared hosts cap ulimit-u ~30-50; composer needs 2-3 forks.
#    Running while workers are alive causes "Unable to launch a new process."
# ----------------------------------------------------------
echo "[2/7] Stopping workers (pre-deploy)..."
pkill -f "artisan queue:work"     2>/dev/null || true
pkill -f "artisan schedule:work"  2>/dev/null || true
pkill -f "future-trade:live-feed" 2>/dev/null || true
pkill -f "future-trade:tick"      2>/dev/null || true
pkill -f "future-trade:user-stream" 2>/dev/null || true
sleep 3
echo "   Stopped (process slots freed)."

# ----------------------------------------------------------
# 3. Autoloader — NO --optimize (avoids subprocess spawning on shared host)
#    --no-scripts --no-plugins prevents any post-install hooks from forking.
# ----------------------------------------------------------
echo "[3/7] composer dump-autoload..."
COMPOSER_CMD=""
for c in /usr/local/bin/composer /usr/bin/composer /usr/local/cpanel/3rdparty/lib/cpan/scripts/composer; do
  [ -f "$c" ] && { COMPOSER_CMD="$c"; break; }
done
if [ -n "$COMPOSER_CMD" ]; then
  "$PHP" "$COMPOSER_CMD" dump-autoload --no-interaction --no-scripts --no-plugins --quiet 2>/dev/null \
    && echo "   Autoload OK." \
    || echo "   Autoload WARN (non-fatal — existing vendor/ will be used)."
else
  echo "   Composer not found — using existing vendor/ (non-fatal)."
fi

# ----------------------------------------------------------
# 4. Migrations
# ----------------------------------------------------------
echo "[4/7] Running migrations..."
"$PHP" artisan migrate --force
echo "   Done."

# ----------------------------------------------------------
# 5. Cache clear + rebuild
# ----------------------------------------------------------
echo "[5/7] Clearing and rebuilding caches..."
"$PHP" artisan config:clear  2>/dev/null || true
"$PHP" artisan route:clear   2>/dev/null || true
"$PHP" artisan view:clear    2>/dev/null || true
"$PHP" artisan cache:clear   2>/dev/null || true
"$PHP" artisan config:cache  2>/dev/null || true
"$PHP" artisan route:cache   2>/dev/null || true
echo "   Done."

# ----------------------------------------------------------
# 6. Start workers
#    NOTE: schedule:work is NOT started here — shared hosting uses
#    cron `schedule:run` every minute instead (heartbeat handles it).
#    Running schedule:work as a daemon would double-fire scheduled jobs.
# ----------------------------------------------------------
echo "[6/7] Starting workers..."
nohup "$PHP" artisan queue:work \
  --queue=critical,execution,reconcile,monitoring \
  --sleep=1 --tries=3 --timeout=120 --max-time=3600 \
  >> storage/logs/worker-critical.log 2>&1 &

nohup "$PHP" artisan queue:work \
  --queue=market,signals,strategies,ratio_bot,ops,intelligence,risk,billing,copytrading \
  --sleep=1 --tries=3 --timeout=120 --max-time=3600 \
  >> storage/logs/worker-market.log 2>&1 &

nohup "$PHP" artisan queue:work \
  --queue=analytics,notifications,default,monitoring_safe \
  --sleep=1 --tries=3 --timeout=120 --max-time=3600 \
  >> storage/logs/worker-safe.log 2>&1 &

nohup "$PHP" artisan future-trade:live-feed --reconnect-sleep=2 \
  >> storage/logs/future-trade-live-feed.log \
  2>> storage/logs/future-trade-live-feed.error.log &

echo "   Workers started."
sleep 3

# ----------------------------------------------------------
# 7. Quick smoke test
# ----------------------------------------------------------
echo "[7/7] Smoke tests..."
"$PHP" artisan future-trade:tick --duration=5 --sleep-ms=1000 \
  >> storage/logs/future-trade-tick.log 2>&1 && echo "   tick  OK" || echo "   tick  WARN (check logs)"

"$PHP" artisan future-trade:diagnostics --persist \
  >> storage/logs/future-trade-diagnostics.log 2>&1 && echo "   diag  OK" || echo "   diag  WARN"

"$PHP" artisan future-trade:queue-health \
  >> storage/logs/future-trade-queue-health.log 2>&1 && echo "   queue OK" || echo "   queue WARN"

# ----------------------------------------------------------
# Crontab — 6 clean entries, no duplicates
#   1. Heartbeat (1 min) — manages ALL workers + schedule:run
#   2. Maintenance (5 min) — diagnostics + missed-signal audit
#   3. Reconcile (5 min) — closes phantom OPEN logical trades
#   4. Master check (15 min) — health check + auto-recovery
#   5. Log rotation (3 AM daily) — prevents disk fill
#   6. @reboot — restart workers after server restart
# ----------------------------------------------------------
WS_ID="26a36b56-4383-4edd-99d1-7622e7c191ef"

install_clean_crontab() {
  TMPFILE=$(mktemp)
  # Strip all existing AutoFinX entries (old or new format)
  crontab -l 2>/dev/null \
    | grep -vE "future-trade|autofinx|heartbeat\.sh|maintenance\.sh|master_check|schedule:run.*artisan|start-workers|reconcile.*artisan" \
    > "$TMPFILE" || true

  cat >> "$TMPFILE" <<CRON
# === AutoFinX Trading Bot — managed by deploy-no-pull.sh ===
* * * * * flock -n $APP_DIR/storage/framework/heartbeat.lock bash $APP_DIR/future-trade-heartbeat.sh >> $APP_DIR/storage/logs/future-trade-heartbeat.log 2>&1
*/5 * * * * flock -n $APP_DIR/storage/framework/maintenance.lock bash $APP_DIR/future-trade-maintenance.sh >> $APP_DIR/storage/logs/future-trade-maintenance.log 2>&1
*/5 * * * * flock -n $APP_DIR/storage/framework/reconcile.lock $PHP $APP_DIR/artisan future-trade:reconcile --workspace=$WS_ID >> $APP_DIR/storage/logs/future-trade-reconcile.log 2>&1
*/15 * * * * flock -n $APP_DIR/storage/framework/master-check.lock bash $APP_DIR/autofinx_master_check.sh >> $APP_DIR/storage/logs/master-check.log 2>&1
0 3 * * * find $APP_DIR/storage/logs -name "*.log" -size +50M -exec truncate -s 10485760 {} \;
@reboot bash $APP_DIR/start-workers.sh >> $APP_DIR/storage/logs/start-workers.log 2>&1
CRON

  crontab "$TMPFILE"
  rm -f "$TMPFILE"
}

if [ "$INSTALL_CRON" -eq 1 ]; then
  echo ""
  echo "Installing crontab (6 entries, no duplicates)..."
  install_clean_crontab
  echo "Crontab installed:"
  crontab -l 2>/dev/null | grep -E "future-trade|autofinx|heartbeat|maintenance|master_check|reconcile|truncate|start-workers" || true
else
  echo ""
  echo "============================================"
  echo " Crontab not installed. To install run:"
  echo "   bash deploy-no-pull.sh --cron"
  echo " Or use autofinx_setup.sh for full setup."
  echo "============================================"
fi

echo ""
echo "============================================"
echo " DONE — $(date '+%Y-%m-%d %H:%M:%S')"
echo " Worker status:"
echo "   queue:work     : $(pgrep -fc 'artisan queue:work' || echo 0) process(es)"
echo "   schedule:work  : $(pgrep -fc 'artisan schedule:work' || echo 0) process(es)"
echo "   live-feed      : $(pgrep -fc 'future-trade:live-feed' || echo 0) process(es)"
echo "============================================"
