#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends}"
PHP_BIN="${PHP_BIN:-/usr/local/bin/ea-php82}"

if [ ! -x "$PHP_BIN" ]; then
  PHP_BIN="$(command -v php || true)"
fi

if [ -z "$PHP_BIN" ] || [ ! -x "$PHP_BIN" ]; then
  echo "PHP binary not found. Set PHP_BIN=/usr/local/bin/ea-php82 and retry."
  exit 1
fi

if [ ! -d "$APP_DIR" ]; then
  echo "APP_DIR not found: $APP_DIR"
  exit 1
fi

if [ "$(id -u)" -ne 0 ]; then
  SUDO="sudo"
else
  SUDO=""
fi

echo "Using APP_DIR=$APP_DIR"
echo "Using PHP_BIN=$PHP_BIN"

$SUDO mkdir -p /usr/local/bin
$SUDO mkdir -p /var/log/autofinx

$SUDO tee /usr/local/bin/autofinx-quick-settler.sh >/dev/null <<EOF
#!/usr/bin/env bash
set -euo pipefail

APP_DIR="$APP_DIR"
PHP_BIN="$PHP_BIN"
SLEEP_SECONDS="\${QUICK_SETTLER_SLEEP_SECONDS:-5}"

cd "\$APP_DIR"

echo "[quick-settler] started at \$(date -Is)"

while true; do
  echo "[quick-settler] tick at \$(date -Is)"
  "\$PHP_BIN" artisan quick-trades:settle --no-interaction || echo "[quick-settler] command failed at \$(date -Is)"
  sleep "\$SLEEP_SECONDS"
done
EOF

$SUDO tee /usr/local/bin/autofinx-robot-evaluator.sh >/dev/null <<EOF
#!/usr/bin/env bash
set -euo pipefail

APP_DIR="$APP_DIR"
PHP_BIN="$PHP_BIN"
SLEEP_SECONDS="\${ROBOT_EVALUATOR_SLEEP_SECONDS:-10}"

cd "\$APP_DIR"

echo "[robot-evaluator] started at \$(date -Is)"

while true; do
  echo "[robot-evaluator] tick at \$(date -Is)"
  "\$PHP_BIN" artisan robot-sessions:evaluate --no-interaction || echo "[robot-evaluator] command failed at \$(date -Is)"
  sleep "\$SLEEP_SECONDS"
done
EOF

$SUDO chmod +x /usr/local/bin/autofinx-quick-settler.sh
$SUDO chmod +x /usr/local/bin/autofinx-robot-evaluator.sh

if [ -d /etc/supervisord.d ]; then
  SUP_CONF="/etc/supervisord.d/autofinx-quick-robot.ini"
elif [ -d /etc/supervisor/conf.d ]; then
  SUP_CONF="/etc/supervisor/conf.d/autofinx-quick-robot.conf"
else
  echo "Supervisor config directory not found"
  exit 1
fi

$SUDO tee "$SUP_CONF" >/dev/null <<EOF
[program:autofinx-quick-settler]
process_name=%(program_name)s
command=/usr/local/bin/autofinx-quick-settler.sh
directory=$APP_DIR
autostart=true
autorestart=true
startsecs=5
startretries=10
stopwaitsecs=30
killasgroup=true
stopasgroup=true
user=zcadmin
redirect_stderr=false
stdout_logfile=/var/log/autofinx/quick-settler.out.log
stdout_logfile_maxbytes=20MB
stdout_logfile_backups=5
stderr_logfile=/var/log/autofinx/quick-settler.err.log
stderr_logfile_maxbytes=20MB
stderr_logfile_backups=5
environment=QUICK_SETTLER_SLEEP_SECONDS="5"

[program:autofinx-robot-evaluator]
process_name=%(program_name)s
command=/usr/local/bin/autofinx-robot-evaluator.sh
directory=$APP_DIR
autostart=true
autorestart=true
startsecs=5
startretries=10
stopwaitsecs=30
killasgroup=true
stopasgroup=true
user=zcadmin
redirect_stderr=false
stdout_logfile=/var/log/autofinx/robot-evaluator.out.log
stdout_logfile_maxbytes=20MB
stdout_logfile_backups=5
stderr_logfile=/var/log/autofinx/robot-evaluator.err.log
stderr_logfile_maxbytes=20MB
stderr_logfile_backups=5
environment=ROBOT_EVALUATOR_SLEEP_SECONDS="10"
EOF

$SUDO chown -R zcadmin:zcadmin /var/log/autofinx || true

cd "$APP_DIR"

echo "Clearing Laravel cache"
"$PHP_BIN" artisan optimize:clear || true
"$PHP_BIN" artisan queue:restart || true

echo "Reloading supervisor"
$SUDO supervisorctl reread
$SUDO supervisorctl update

echo "Starting programs"
$SUDO supervisorctl restart autofinx-quick-settler || $SUDO supervisorctl start autofinx-quick-settler || true
$SUDO supervisorctl restart autofinx-robot-evaluator || $SUDO supervisorctl start autofinx-robot-evaluator || true

sleep 10

echo "Supervisor status"
$SUDO supervisorctl status

echo "Runtime status"
"$PHP_BIN" artisan future-trade:runtime-status || true

echo "Doctor"
"$PHP_BIN" artisan future-trade:doctor || true

echo "Quick settler log"
$SUDO tail -n 50 /var/log/autofinx/quick-settler.out.log || true
$SUDO tail -n 50 /var/log/autofinx/quick-settler.err.log || true

echo "Robot evaluator log"
$SUDO tail -n 50 /var/log/autofinx/robot-evaluator.out.log || true
$SUDO tail -n 50 /var/log/autofinx/robot-evaluator.err.log || true
