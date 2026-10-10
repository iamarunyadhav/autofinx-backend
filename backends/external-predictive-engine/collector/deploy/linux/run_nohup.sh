#!/usr/bin/env bash
# Start the collector in the background without systemd/supervisor (survives logout, NOT reboot).
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENGINE_DIR="$(cd "$HERE/../../.." && pwd)"
BASE_DIR="${BASE_DIR:-$HOME/autofinx-collector}"
PY="$BASE_DIR/venv/bin/python"; CFG="$BASE_DIR/collector.config.json"
cd "$ENGINE_DIR"
if "$PY" -m collector --config "$CFG" status >/dev/null 2>&1; then echo "collector already running"; exit 0; fi
mkdir -p "$BASE_DIR/data/logs"
IONICE=""; command -v ionice >/dev/null 2>&1 && IONICE="ionice -c3"
nohup nice -n 19 $IONICE "$PY" -m collector --config "$CFG" start >>"$BASE_DIR/data/logs/stdout.log" 2>&1 &
sleep 5
"$PY" -m collector --config "$CFG" status || true
