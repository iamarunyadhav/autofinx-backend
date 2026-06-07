#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${APP_DIR:-$SCRIPT_DIR}"
PHP="${PHP:-$(command -v php || true)}"
if { [ -z "$PHP" ] || [ ! -x "$PHP" ]; } && [ -x "/usr/local/bin/ea-php82" ]; then
  PHP="/usr/local/bin/ea-php82"
fi
if { [ -z "$PHP" ] || [ ! -x "$PHP" ]; } && [ -x "/opt/cpanel/ea-php82/root/usr/bin/php" ]; then
  PHP="/opt/cpanel/ea-php82/root/usr/bin/php"
fi
if [ -z "$PHP" ] || [ ! -x "$PHP" ]; then
  echo "PHP binary not found. Set PHP=/path/to/php and retry." >&2
  exit 1
fi
RUN_TESTS=1
RUN_BACKTEST=0
WORKSPACE_ID=""
FROM_DATE=""
TO_DATE=""
CAPITAL="1000"

for arg in "$@"; do
  case "$arg" in
    --skip-tests) RUN_TESTS=0 ;;
    --backtest) RUN_BACKTEST=1 ;;
    --workspace=*) WORKSPACE_ID="${arg#*=}" ;;
    --from=*) FROM_DATE="${arg#*=}" ;;
    --to=*) TO_DATE="${arg#*=}" ;;
    --capital=*) CAPITAL="${arg#*=}" ;;
  esac
done

cd "$APP_DIR"

echo "[FT REFRESH] syntax check"
"$PHP" -l app/Domain/FutureTrade/FutureTradeBotService.php
"$PHP" -l app/Domain/Market/FutureTradeLiveFeedManager.php
"$PHP" -l app/Http/Controllers/Api/FutureTradeBotController.php
"$PHP" -l app/Console/Commands/FutureTradeTickCommand.php

echo "[FT REFRESH] optimize clear + migrate"
"$PHP" artisan optimize:clear
"$PHP" artisan migrate --force
composer dump-autoload --no-interaction --optimize

if [ "$RUN_TESTS" -eq 1 ]; then
  echo "[FT REFRESH] run FutureTrade tests"
  "$PHP" artisan test --filter=FutureTrade
fi

echo "[FT REFRESH] restart workers"
chmod +x start-workers.sh stop-workers.sh restart-workers.sh future-trade-heartbeat.sh future-trade-maintenance.sh future-trade-refresh.sh
./restart-workers.sh

echo "[FT REFRESH] short tick"
"$PHP" artisan future-trade:tick --duration=10 --sleep-ms=1000 --no-execute

echo "[FT REFRESH] diagnostics + queue health"
"$PHP" artisan future-trade:diagnostics --persist
"$PHP" artisan future-trade:queue-health

if [ "$RUN_BACKTEST" -eq 1 ]; then
  if [ -z "$WORKSPACE_ID" ]; then
    echo "[FT REFRESH] --workspace is required with --backtest" >&2
    exit 1
  fi

  BACKTEST_ARGS=(artisan future-trade:backtest "--workspace=$WORKSPACE_ID" "--capital=$CAPITAL")
  if [ -n "$FROM_DATE" ]; then
    BACKTEST_ARGS+=("--from=$FROM_DATE")
  fi
  if [ -n "$TO_DATE" ]; then
    BACKTEST_ARGS+=("--to=$TO_DATE")
  fi

  echo "[FT REFRESH] backtest"
  "$PHP" "${BACKTEST_ARGS[@]}"
fi

echo "[FT REFRESH] done"
