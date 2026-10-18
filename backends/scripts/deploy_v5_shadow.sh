#!/usr/bin/env bash
set -Eeuo pipefail

# V5 research/shadow deployment preflight.
# Safe defaults: no live execution, no auto-promotion, no migrations.

RUN_MIGRATIONS="${RUN_MIGRATIONS:-0}"
cd "${BACKEND_DIR:-$(dirname "$(dirname "$(realpath "$0")")")}" 
test -f artisan || { echo "artisan not found"; exit 1; }

echo "== AUTOFINX V5 shadow preflight =="
php artisan --version
php artisan autofinx:config-doctor || true

if [[ "$RUN_MIGRATIONS" == "1" ]]; then
  php artisan migrate --force
else
  echo "Skipping migrations; set RUN_MIGRATIONS=1 only after backup/review."
fi

php artisan optimize:clear
php artisan config:cache
php artisan route:cache

echo "== V5 scheduler entries =="
php artisan schedule:list | grep -E 'v5:research|training' || true
echo "== V5 shadow routes =="
php artisan route:list --path=quick-trade/ml-shadow | grep -E 'history|research|holdout' || true

echo "== Safety =="
echo "LLM is disabled by default; live execution and automatic promotion remain disabled."
echo "Run scheduler and queue through Supervisor/systemd in production."
echo "php artisan schedule:run"
echo "php artisan queue:work --queue=default,nexus --sleep=1 --tries=1 --timeout=120"
echo "V5 shadow preflight complete."
