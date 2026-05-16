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
# 2. Autoloader
# ----------------------------------------------------------
echo "[2/7] composer dump-autoload..."
"$PHP" /usr/local/bin/composer dump-autoload --no-interaction --optimize --quiet
echo "   Done."

# ----------------------------------------------------------
# 3. Migrations
# ----------------------------------------------------------
echo "[3/7] Running migrations..."
"$PHP" artisan migrate --force
echo "   Done."

# ----------------------------------------------------------
# 4. Cache clear
# ----------------------------------------------------------
echo "[4/7] Clearing caches..."
"$PHP" artisan optimize:clear
echo "   Done."

# ----------------------------------------------------------
# 5. Stop old workers
# ----------------------------------------------------------
echo "[5/7] Stopping workers..."
pkill -f "artisan queue:work"    || true
pkill -f "artisan schedule:work" || true
pkill -f "future-trade:live-feed" || true
sleep 2
echo "   Stopped."

# ----------------------------------------------------------
# 6. Start workers
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

nohup "$PHP" artisan schedule:work \
  >> storage/logs/schedule-work.log 2>&1 &

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
# Crontab
# ----------------------------------------------------------
CRON_LINES=$(cat <<'CRON'
* * * * * cd /home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends && /usr/local/bin/ea-php82 artisan schedule:run >> storage/logs/cron-schedule-run.log 2>&1
* * * * * cd /home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends && /usr/local/bin/ea-php82 artisan future-trade:tick --duration=55 --sleep-ms=1000 >> storage/logs/future-trade-tick.log 2>&1
*/2 * * * * /home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends/future-trade-heartbeat.sh >> /home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends/storage/logs/future-trade-heartbeat.log 2>&1
*/5 * * * * /home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends/future-trade-maintenance.sh >> /home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends/storage/logs/future-trade-cron.log 2>&1
CRON
)

if [ "$INSTALL_CRON" -eq 1 ]; then
  echo ""
  echo "Installing crontab..."
  (crontab -l 2>/dev/null \
    | grep -v "future-trade:tick" \
    | grep -v "future-trade-heartbeat.sh" \
    | grep -v "future-trade-maintenance.sh" \
    | grep -v "artisan schedule:run"
   echo "$CRON_LINES") | crontab -
  echo "Crontab installed. Verify with: crontab -l"
else
  echo ""
  echo "============================================"
  echo " ADD THESE 4 LINES IN cPanel > Cron Jobs:"
  echo "============================================"
  echo "$CRON_LINES"
  echo "============================================"
  echo " Or run:  bash deploy-no-pull.sh --cron"
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
