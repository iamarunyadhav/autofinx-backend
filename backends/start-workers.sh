#!/usr/bin/env bash
set -euo pipefail

APP_DIR="/home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends"
PHP="/usr/local/bin/ea-php82"

cd "$APP_DIR"
mkdir -p storage/logs

nohup "$PHP" artisan queue:work --queue=critical,execution,reconcile,monitoring --sleep=1 --tries=3 --timeout=120 --max-time=3600 >> storage/logs/worker-critical.log 2>&1 &
nohup "$PHP" artisan queue:work --queue=market,signals,strategies,ratio_bot,ops,intelligence,risk,billing,copytrading --sleep=1 --tries=3 --timeout=120 --max-time=3600 >> storage/logs/worker-market.log 2>&1 &
nohup "$PHP" artisan queue:work --queue=analytics,notifications,default,monitoring_safe --sleep=1 --tries=3 --timeout=120 --max-time=3600 >> storage/logs/worker-safe.log 2>&1 &
nohup "$PHP" artisan schedule:work >> storage/logs/schedule-work.log 2>&1 &
nohup "$PHP" artisan future-trade:live-feed --reconnect-sleep=2 >> storage/logs/future-trade-live-feed.log 2>> storage/logs/future-trade-live-feed.error.log &

echo "AutoFinX workers started."
