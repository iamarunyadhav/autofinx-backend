#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_ARTISAN="$ROOT/scripts/php-artisan"
cd "$ROOT"

echo "AUTOFINX PRODUCTION VERIFY"
"$PHP_ARTISAN" autofinx:config-doctor

count="$(ps -eo cmd= 2>/dev/null | grep "$ROOT" | grep -E "artisan (future-trade:live-feed|market:ws-consume)" | grep -v grep | wc -l | tr -d ' ')"
echo "feed_worker_count=$count"
[ "$count" -le 1 ] || { echo "FAIL: duplicate feed workers"; exit 2; }

"$PHP_ARTISAN" autofinx:runtime-health --symbols="${AUTOFINX_VERIFY_SYMBOLS:-BTCUSDT,ETHUSDT}"

echo "Taking two freshness observations..."
a="$("$PHP_ARTISAN" tinker --execute='echo json_encode(Illuminate\Support\Facades\Redis::get("market:live:BTCUSDT"));' 2>/dev/null || true)"
sleep 3
b="$("$PHP_ARTISAN" tinker --execute='echo json_encode(Illuminate\Support\Facades\Redis::get("market:live:BTCUSDT"));' 2>/dev/null || true)"

echo "snapshot_a_present=$([ -n "$a" ] && echo yes || echo no)"
echo "snapshot_b_present=$([ -n "$b" ] && echo yes || echo no)"
if [ "$a" = "$b" ]; then
  echo "FAIL: canonical BTCUSDT snapshot did not change across observations"
  exit 2
fi

echo "PASS"
