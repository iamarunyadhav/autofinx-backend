#!/usr/bin/env bash
# Separate, OPTIONAL step. Needs root ONLY to copy the service file. The service itself runs as a normal user.
# Usage (run with sudo; give the non-root user that ran install.sh):
#   sudo bash install_service.sh --user collector [--systemd | --supervisor] [--base /home/collector/autofinx-collector]
# Default is --systemd. Nothing is started unless you pass --start.
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENGINE_DIR="$(cd "$HERE/../../.." && pwd)"
USER_NAME=""; MODE="systemd"; BASE=""; START=0
while [ $# -gt 0 ]; do
  case "$1" in
    --user) USER_NAME="$2"; shift 2;;
    --base) BASE="$2"; shift 2;;
    --systemd) MODE="systemd"; shift;;
    --supervisor) MODE="supervisor"; shift;;
    --start) START=1; shift;;
    *) echo "bad arg $1"; exit 1;;
  esac
done
[ -n "$USER_NAME" ] || { echo "need --user <non-root user>"; exit 1; }
[ "$USER_NAME" != "root" ] || { echo "refusing to run the collector as root"; exit 1; }
HOME_DIR="$(getent passwd "$USER_NAME" | cut -d: -f6)"
[ -n "$HOME_DIR" ] || { echo "no such user $USER_NAME"; exit 1; }
BASE="${BASE:-$HOME_DIR/autofinx-collector}"
[ -x "$BASE/venv/bin/python" ] || { echo "run install.sh as $USER_NAME first (no venv at $BASE/venv)"; exit 1; }
mkdir -p "$BASE/data/logs"; chown -R "$USER_NAME": "$BASE/data" 2>/dev/null || true

render() { sed -e "s#@USER@#$USER_NAME#g" -e "s#@BASE@#$BASE#g" -e "s#@ENGINE@#$ENGINE_DIR#g" "$1"; }

if [ "$MODE" = "systemd" ]; then
  render "$HERE/autofinx-collector.service" > /etc/systemd/system/autofinx-collector.service
  systemctl daemon-reload
  systemctl enable autofinx-collector
  echo "installed /etc/systemd/system/autofinx-collector.service (enabled, not started unless --start)"
  [ "$START" = 1 ] && systemctl start autofinx-collector
  echo "status: systemctl status autofinx-collector   logs: journalctl -u autofinx-collector -f"
else
  DEST=/etc/supervisor/conf.d/autofinx-collector.conf
  [ -d /etc/supervisor/conf.d ] || DEST=/etc/supervisord.d/autofinx-collector.ini
  render "$HERE/autofinx-collector.supervisor.conf" > "$DEST"
  supervisorctl reread && supervisorctl update
  echo "installed $DEST"
  [ "$START" = 1 ] && supervisorctl start autofinx-collector
  echo "status: supervisorctl status autofinx-collector"
fi
