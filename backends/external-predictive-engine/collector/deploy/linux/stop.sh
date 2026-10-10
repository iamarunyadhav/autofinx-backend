#!/usr/bin/env bash
# Clean stop via STOP file; waits up to 40 s for the process to close its files.
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENGINE_DIR="$(cd "$HERE/../../.." && pwd)"
BASE_DIR="${BASE_DIR:-$HOME/autofinx-collector}"
cd "$ENGINE_DIR"
exec "$BASE_DIR/venv/bin/python" -m collector --config "$BASE_DIR/collector.config.json" stop --wait 40
