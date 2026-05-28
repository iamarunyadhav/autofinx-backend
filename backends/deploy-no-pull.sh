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

APP_DIR="/home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends"
PHP="/usr/local/bin/ea-php82"
if [ ! -x "$PHP" ] && [ -x "/opt/cpanel/ea-php82/root/usr/bin/php" ]; then
  PHP="/opt/cpanel/ea-php82/root/usr/bin/php"
fi
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
chmod +x start-workers.sh future-trade-heartbeat.sh future-trade-maintenance.sh
./start-workers.sh

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
* * * * * /home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends/future-trade-heartbeat.sh >> /home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends/storage/logs/future-trade-heartbeat.log 2>&1
*/5 * * * * /home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends/future-trade-maintenance.sh >> /home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends/storage/logs/future-trade-cron.log 2>&1
CRON
)

if [ "$INSTALL_CRON" -eq 1 ]; then
  echo ""
  echo "Installing crontab..."
  (crontab -l 2>/dev/null \
    | grep -v "future-trade:tick" \
    | grep -v "future-trade:user-stream" \
    | grep -v "future-trade-heartbeat.sh" \
    | grep -v "future-trade-maintenance.sh" \
    | grep -v "artisan schedule:run"
   echo "$CRON_LINES") | crontab -
  echo "Crontab installed. Verify with: crontab -l"
else
  echo ""
  echo "============================================"
  echo " ADD THESE 2 LINES IN cPanel > Cron Jobs:"
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
