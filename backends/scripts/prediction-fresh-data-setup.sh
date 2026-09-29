#!/usr/bin/env bash
# Safe-by-default installation helper. Run with bash; it does nothing until
# --apply is supplied. It never starts a queue worker, changes Supervisor, or
# points the predictor at a model.
set -euo pipefail

APPLY=0
DATASET=""
SYMBOLS="BTCUSDT,ETHUSDT,SOLUSDT,BNBUSDT"
TIMEFRAMES="5m,15m,1h"
PHP_BIN="${PHP_BIN:-php}"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --apply) APPLY=1 ;;
    --dataset) DATASET="${2:?missing dataset name}"; shift ;;
    --symbols) SYMBOLS="${2:?missing symbols}"; shift ;;
    --timeframes) TIMEFRAMES="${2:?missing timeframes}"; shift ;;
    --php) PHP_BIN="${2:?missing PHP binary}"; shift ;;
    *) echo "Unknown option: $1" >&2; exit 2 ;;
  esac
  shift
done

if [[ -z "$DATASET" || ! "$DATASET" =~ ^[A-Za-z0-9_-]+$ ]]; then
  echo "Use: bash scripts/prediction-fresh-data-setup.sh --dataset fresh-2026-09 [--apply]" >&2
  exit 2
fi
if [[ ! "$SYMBOLS" =~ ^[A-Za-z0-9,]+$ || ! "$TIMEFRAMES" =~ ^[0-9A-Za-z,]+$ ]]; then
  echo "Symbols/timeframes may contain only letters, digits and commas." >&2
  exit 2
fi

echo "Dataset: $DATASET"
echo "Symbols: $SYMBOLS"
echo "Timeframes: $TIMEFRAMES"
echo "Migration: database/migrations/2026_09_29_070000_create_prediction_fresh_datasets_tables.php"
echo "Execution/influence: false (hard design invariant)"
if [[ "$APPLY" != "1" ]]; then
  echo "Dry run only. Re-run with --apply after reviewing the migration."
  exit 0
fi

"$PHP_BIN" artisan migrate --force --path=database/migrations/2026_09_29_070000_create_prediction_fresh_datasets_tables.php
"$PHP_BIN" artisan prediction:fresh-dataset:start "$DATASET" --symbols="$SYMBOLS" --timeframes="$TIMEFRAMES"
"$PHP_BIN" artisan prediction:fresh-dataset:readiness "$DATASET"

echo "Setup complete. Add the single cron line from deploy/prediction-fresh-data-cron.example after replacing DATASET_NAME."
