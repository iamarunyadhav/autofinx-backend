#!/usr/bin/env bash
set -euo pipefail

pkill -f "queue:work --queue=critical,execution,reconcile,monitoring" || true
pkill -f "queue:work --queue=market,signals,strategies,ratio_bot,ops,intelligence,risk,billing,copytrading" || true
pkill -f "queue:work --queue=analytics,notifications,default,monitoring_safe" || true
pkill -f "queue:work database --queue=critical,execution,reconcile,monitoring" || true
pkill -f "queue:work database --queue=market,signals,strategies,ratio_bot,ops,intelligence,risk,billing,copytrading" || true
pkill -f "queue:work database --queue=analytics,notifications,default,monitoring_safe" || true
pkill -f "artisan schedule:work" || true
pkill -f "future-trade:live-feed" || true
pkill -f "future-trade:user-stream" || true
pkill -f "future-trade:tick" || true
pkill -f "future-trade:scan-loop" || true

echo "AutoFinX workers stopped."
