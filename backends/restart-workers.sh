#!/usr/bin/env bash
set -euo pipefail

APP_DIR="/home/zcadmin/public_html/api.autofinx.arunyadhav.dev/autofinx-backend/backends"

"$APP_DIR/stop-workers.sh"
sleep 2
"$APP_DIR/start-workers.sh"

echo "AutoFinX workers restarted."
