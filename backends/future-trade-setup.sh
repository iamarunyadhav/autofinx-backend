#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="/home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend"
APP_DIR="/home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends"
PHP="/usr/local/bin/ea-php82"
INSTALL_CRON=0
SKIP_TESTS=0

for arg in "$@"; do
  case "$arg" in
    --install-cron) INSTALL_CRON=1 ;;
    --skip-tests) SKIP_TESTS=1 ;;
  esac
done

cd "$APP_ROOT"
git pull
cd "$APP_DIR"

"$PHP" -l app/Domain/FutureTrade/FutureTradeBotService.php
"$PHP" -l app/Domain/Market/FutureTradeLiveFeedManager.php
"$PHP" -l app/Http/Controllers/Api/FutureTradeBotController.php
"$PHP" -l app/Models/FutureTradeBotConfig.php

composer dump-autoload --no-interaction --optimize
"$PHP" artisan migrate --force
"$PHP" artisan optimize:clear

if [ "$SKIP_TESTS" -eq 0 ]; then
  "$PHP" artisan test --filter=FutureTrade
fi

chmod +x start-workers.sh stop-workers.sh restart-workers.sh future-trade-heartbeat.sh future-trade-maintenance.sh future-trade-setup.sh
./restart-workers.sh
"$PHP" artisan future-trade:tick --duration=10 --sleep-ms=1000
"$PHP" artisan future-trade:diagnostics --persist
"$PHP" artisan future-trade:queue-health

CRON_LINES=$(cat <<'CRON'
* * * * * cd /home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends && /usr/local/bin/ea-php82 artisan schedule:run >> storage/logs/cron-schedule-run.log 2>&1
* * * * * cd /home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends && /usr/local/bin/ea-php82 artisan future-trade:tick --duration=55 --sleep-ms=1000 >> storage/logs/future-trade-tick.log 2>&1
*/2 * * * * /home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends/future-trade-heartbeat.sh >> /home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends/storage/logs/future-trade-heartbeat.log 2>&1
*/5 * * * * /home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends/future-trade-maintenance.sh >> /home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends/storage/logs/future-trade-cron.log 2>&1
CRON
)

if [ "$INSTALL_CRON" -eq 1 ]; then
  (crontab -l 2>/dev/null | grep -v "future-trade:tick" | grep -v "future-trade-heartbeat.sh" | grep -v "future-trade-maintenance.sh" | grep -v "artisan schedule:run"; echo "$CRON_LINES") | crontab -
  echo "Crontab installed."
else
  echo "Add these cPanel cron lines:"
  echo "$CRON_LINES"
fi

echo "Future Trade setup complete."
