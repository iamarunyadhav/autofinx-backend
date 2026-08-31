#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

echo "AUTOFINX RUNTIME AUDIT (READ ONLY)"
echo
echo "PROJECT=$ROOT"
echo "DATE_UTC=$(date -u +%FT%TZ)"
echo

echo "PHP"
echo "which php: $(command -v php 2>/dev/null || echo missing)"
php -v 2>/dev/null | head -n 1 || true
for bin in /opt/cpanel/ea-php82/root/usr/bin/php /opt/cpanel/ea-php83/root/usr/bin/php /opt/cpanel/ea-php84/root/usr/bin/php; do
  if [ -x "$bin" ]; then
    echo "$bin: $("$bin" -r 'echo PHP_VERSION;' 2>/dev/null || true)"
  fi
done
echo "composer requirement: $(grep -E '"php"[[:space:]]*:' composer.json || true)"
echo

echo "PROCESSES"
ps -eo pid,ppid,user,lstart,cmd 2>/dev/null | grep -E "$ROOT|artisan (future-trade:live-feed|market:ws-consume|queue:work|horizon|schedule:work|schedule:run)" | grep -v grep || true
echo

echo "SUPERVISOR"
if command -v supervisorctl >/dev/null 2>&1; then
  supervisorctl status 2>/dev/null | grep -Ei 'autofinx|future-trade|market|queue|horizon|schedule' || true
else
  echo "supervisorctl not found"
fi
echo

echo "CRON"
(crontab -l 2>/dev/null || true) | grep -Ei 'autofinx|artisan|schedule|queue|future-trade|market:ws-consume' || true
for d in /etc/cron.d /etc/supervisor /etc/supervisor/conf.d /etc/supervisord.d; do
  [ -d "$d" ] && grep -RIlE 'autofinx|future-trade|market:ws-consume|queue:work|schedule:(run|work)' "$d" 2>/dev/null || true
done
echo

echo "TIME"
date -u
timedatectl 2>/dev/null | sed -n '1,8p' || true
echo

echo "ARTISAN HEALTH"
"$ROOT/scripts/php-artisan" autofinx:config-doctor || true
"$ROOT/scripts/php-artisan" autofinx:runtime-health || true
