#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_ARTISAN="$ROOT/scripts/php-artisan"
cd "$ROOT"

echo "AUTOFINX RUNTIME RESTART"
"$PHP_ARTISAN" config:clear
"$PHP_ARTISAN" cache:clear
"$PHP_ARTISAN" queue:restart || true

supervisor_program="autofinx-trading:live-feed"
if command -v supervisorctl >/dev/null 2>&1 && supervisorctl status "$supervisor_program" >/dev/null 2>&1; then
  supervisorctl reread || true
  supervisorctl update || true
  supervisorctl restart "$supervisor_program"
else
  echo "WARN: $supervisor_program not detected; not spawning a manual websocket worker."
fi

echo "Checking for duplicate AutoFinix feed processes scoped to $ROOT"
mapfile -t feed_pids < <(ps -eo pid=,cmd= 2>/dev/null | grep "$ROOT" | grep -E "artisan (future-trade:live-feed|market:ws-consume)" | grep -v grep | awk '{print $1}' || true)
if [ "${#feed_pids[@]}" -gt 1 ]; then
  echo "WARN: duplicate scoped feed processes detected: ${feed_pids[*]}"
fi

for i in $(seq 1 20); do
  if "$PHP_ARTISAN" autofinx:runtime-health >/tmp/autofinx-runtime-health.$$ 2>&1; then
    cat /tmp/autofinx-runtime-health.$$
    rm -f /tmp/autofinx-runtime-health.$$
    echo "PASS"
    exit 0
  fi
  sleep 3
done

cat /tmp/autofinx-runtime-health.$$ 2>/dev/null || true
rm -f /tmp/autofinx-runtime-health.$$
echo "FAIL: runtime did not become healthy"
exit 2
