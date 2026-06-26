#!/usr/bin/env bash
set -euo pipefail

# AutoFinX one-shot runtime installer.
# Run as root from anywhere on the production server:
#   bash /home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends/scripts/root-install-autofinx-trading-runtime.sh

APP_DIR="${APP_DIR:-/home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends}"
PHP_BIN="${PHP_BIN:-/usr/local/bin/ea-php82}"
RUN_USER="${RUN_USER:-zcadmin}"
LOG_DIR="${LOG_DIR:-/var/log/autofinx}"
SUP_GROUP_CONF_NAME="autofinx-quick-robot"

if [ "$(id -u)" -ne 0 ]; then
  echo "Please run this script as root. Example:"
  echo "  sudo bash $0"
  exit 1
fi

if [ ! -d "$APP_DIR" ]; then
  echo "APP_DIR not found: $APP_DIR"
  exit 1
fi

if [ ! -x "$PHP_BIN" ]; then
  PHP_BIN="$(command -v php || true)"
fi

if [ -z "$PHP_BIN" ] || [ ! -x "$PHP_BIN" ]; then
  echo "PHP binary not found. Expected /usr/local/bin/ea-php82."
  exit 1
fi

if ! command -v supervisorctl >/dev/null 2>&1; then
  echo "supervisorctl not found. Install/enable Supervisor first."
  exit 1
fi

if ! id "$RUN_USER" >/dev/null 2>&1; then
  echo "Runtime user not found: $RUN_USER"
  exit 1
fi

if [ -d /etc/supervisord.d ]; then
  SUP_CONF="/etc/supervisord.d/${SUP_GROUP_CONF_NAME}.ini"
elif [ -d /etc/supervisor/conf.d ]; then
  SUP_CONF="/etc/supervisor/conf.d/${SUP_GROUP_CONF_NAME}.conf"
else
  echo "Supervisor config directory not found. Expected /etc/supervisord.d or /etc/supervisor/conf.d."
  exit 1
fi

echo "== AutoFinX Trading Runtime Installer =="
echo "APP_DIR : $APP_DIR"
echo "PHP_BIN : $PHP_BIN"
echo "RUN_USER: $RUN_USER"
echo "LOG_DIR : $LOG_DIR"
echo "SUP_CONF: $SUP_CONF"

mkdir -p /usr/local/bin "$LOG_DIR"
chown -R "$RUN_USER:$RUN_USER" "$LOG_DIR" || true

echo "== Writing wrapper scripts =="
cat >/usr/local/bin/autofinx-quick-settler.sh <<EOF
#!/usr/bin/env bash
set -euo pipefail

APP_DIR="$APP_DIR"
PHP_BIN="$PHP_BIN"
SLEEP_SECONDS="\${QUICK_SETTLER_SLEEP_SECONDS:-5}"

cd "\$APP_DIR"
echo "[quick-settler] started at \$(date -Is)"

while true; do
  echo "[quick-settler] tick at \$(date -Is)"
  printf '{"at":"%s","epoch":%s,"pid":%s}\n' "\$(date -Is)" "\$(date +%s)" "\$\$" > "\$APP_DIR/storage/framework/autofinx-quick-settler-heartbeat.json" || true
  "\$PHP_BIN" artisan quick-trades:settle --no-interaction || echo "[quick-settler] command failed at \$(date -Is)"
  printf '{"at":"%s","epoch":%s,"pid":%s}\n' "\$(date -Is)" "\$(date +%s)" "\$\$" > "\$APP_DIR/storage/framework/autofinx-quick-settler-heartbeat.json" || true
  sleep "\$SLEEP_SECONDS"
done
EOF

cat >/usr/local/bin/autofinx-robot-evaluator.sh <<EOF
#!/usr/bin/env bash
set -euo pipefail

APP_DIR="$APP_DIR"
PHP_BIN="$PHP_BIN"
SLEEP_SECONDS="\${ROBOT_EVALUATOR_SLEEP_SECONDS:-10}"

cd "\$APP_DIR"
echo "[robot-evaluator] started at \$(date -Is)"

while true; do
  echo "[robot-evaluator] tick at \$(date -Is)"
  printf '{"at":"%s","epoch":%s,"pid":%s}\n' "\$(date -Is)" "\$(date +%s)" "\$\$" > "\$APP_DIR/storage/framework/autofinx-robot-evaluator-heartbeat.json" || true
  "\$PHP_BIN" artisan robot-sessions:evaluate --no-interaction || echo "[robot-evaluator] command failed at \$(date -Is)"
  printf '{"at":"%s","epoch":%s,"pid":%s}\n' "\$(date -Is)" "\$(date +%s)" "\$\$" > "\$APP_DIR/storage/framework/autofinx-robot-evaluator-heartbeat.json" || true
  sleep "\$SLEEP_SECONDS"
done
EOF

chmod +x /usr/local/bin/autofinx-quick-settler.sh /usr/local/bin/autofinx-robot-evaluator.sh
chown "$RUN_USER:$RUN_USER" /usr/local/bin/autofinx-quick-settler.sh /usr/local/bin/autofinx-robot-evaluator.sh || true
touch "$APP_DIR/storage/framework/autofinx-quick-settler-heartbeat.json" "$APP_DIR/storage/framework/autofinx-robot-evaluator-heartbeat.json"
chown "$RUN_USER:$RUN_USER" "$APP_DIR/storage/framework/autofinx-quick-settler-heartbeat.json" "$APP_DIR/storage/framework/autofinx-robot-evaluator-heartbeat.json" || true

if [ -f "$SUP_CONF" ]; then
  cp -a "$SUP_CONF" "${SUP_CONF}.bak.$(date +%Y%m%d%H%M%S)"
fi

echo "== Writing Supervisor config =="
cat >"$SUP_CONF" <<EOF
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
user=$RUN_USER
redirect_stderr=false
stdout_logfile=$LOG_DIR/quick-settler.out.log
stdout_logfile_maxbytes=20MB
stdout_logfile_backups=5
stderr_logfile=$LOG_DIR/quick-settler.err.log
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
user=$RUN_USER
redirect_stderr=false
stdout_logfile=$LOG_DIR/robot-evaluator.out.log
stdout_logfile_maxbytes=20MB
stdout_logfile_backups=5
stderr_logfile=$LOG_DIR/robot-evaluator.err.log
stderr_logfile_maxbytes=20MB
stderr_logfile_backups=5
environment=ROBOT_EVALUATOR_SLEEP_SECONDS="10"
EOF

chown "$RUN_USER:$RUN_USER" "$SUP_CONF" || true

cd "$APP_DIR"

echo "== Laravel cache and queues =="
"$PHP_BIN" artisan optimize:clear || true
"$PHP_BIN" artisan queue:restart || true

echo "== Registering Supervisor programs =="
supervisorctl reread
supervisorctl update

echo "== Starting/restarting AutoFinX Quick/Robot daemons =="
supervisorctl restart autofinx-quick-settler || supervisorctl start autofinx-quick-settler || true
supervisorctl restart autofinx-robot-evaluator || supervisorctl start autofinx-robot-evaluator || true

sleep 10

echo "== Supervisor status =="
supervisorctl status

echo "== Process check =="
ps -ef | egrep "future-trade:live-feed|future-trade:tick|future-trade:user-stream|quick-trades:settle|robot-sessions:evaluate|queue:work" | grep -v grep || true

echo "== Runtime health =="
"$PHP_BIN" artisan future-trade:runtime-status || true
"$PHP_BIN" artisan future-trade:doctor || true
"$PHP_BIN" artisan quick-trades:doctor || true

echo "== Last Quick settler log =="
tail -n 80 "$LOG_DIR/quick-settler.out.log" || true
tail -n 80 "$LOG_DIR/quick-settler.err.log" || true

echo "== Last Robot evaluator log =="
tail -n 80 "$LOG_DIR/robot-evaluator.out.log" || true
tail -n 80 "$LOG_DIR/robot-evaluator.err.log" || true

echo "== DONE =="
echo "Expected:"
echo "  autofinx-quick-settler    RUNNING"
echo "  autofinx-robot-evaluator  RUNNING"
echo ""
echo "Verify:"
echo "  cd $APP_DIR"
echo "  $PHP_BIN artisan future-trade:runtime-status --workspace=\"26a36b56-4383-4edd-99d1-7622e7c191ef\""
echo "  $PHP_BIN artisan quick-trades:doctor --workspace=\"26a36b56-4383-4edd-99d1-7622e7c191ef\""
