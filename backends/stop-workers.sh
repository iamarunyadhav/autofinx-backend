#!/usr/bin/env bash
set -euo pipefail

pkill -f "artisan queue:work" || true
pkill -f "artisan schedule:work" || true
pkill -f "future-trade:live-feed" || true
pkill -f "future-trade:scan-loop" || true

echo "AutoFinX workers stopped."
