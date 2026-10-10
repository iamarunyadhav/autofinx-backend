#!/usr/bin/env bash
# AutoFinX collector - Linux install (NO root needed, never reads .env or secrets).
# Run as the normal user that will own the data:   bash install.sh
# Options (env vars):  BASE_DIR=$HOME/autofinx-collector   PYTHON=python3   MIN_FREE_GB=8   SKIP_SMOKE=1
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENGINE_DIR="$(cd "$HERE/../../.." && pwd)"          # .../external-predictive-engine  (contains the collector/ package)
BASE_DIR="${BASE_DIR:-$HOME/autofinx-collector}"
PYTHON="${PYTHON:-python3}"
MIN_FREE_GB="${MIN_FREE_GB:-8}"
VENV="$BASE_DIR/venv"
CFG="$BASE_DIR/collector.config.json"
WS_VERSION="16.1.1"                                   # same version as the laptop run

if [ "$(id -u)" = "0" ]; then
  echo "Do not run install.sh as root. Use a normal (dedicated) user."; exit 1
fi
[ -d "$ENGINE_DIR/collector" ] || { echo "collector package not found at $ENGINE_DIR/collector"; exit 1; }

echo "== 1. python check =="
"$PYTHON" - <<'PY'
import sys
assert sys.version_info >= (3, 10), "Python 3.10+ is required (websockets 16). Found %s" % sys.version
print("python", sys.version.split()[0])
PY

echo "== 2. venv + websockets==$WS_VERSION (+ pytest for the tests) =="
mkdir -p "$BASE_DIR"
[ -x "$VENV/bin/python" ] || "$PYTHON" -m venv "$VENV"
"$VENV/bin/python" -m pip install --quiet --upgrade pip
"$VENV/bin/python" -m pip install --quiet "websockets==$WS_VERSION" pytest

echo "== 3. config -> $CFG =="
if [ ! -f "$CFG" ]; then
  "$VENV/bin/python" - "$HERE/collector.config.linux.json" "$CFG" "$BASE_DIR/data" "$MIN_FREE_GB" <<'PY'
import json, sys
src, dst, data_dir, floor = sys.argv[1:5]
c = json.load(open(src))
c["data_dir"] = data_dir
c["min_free_gb"] = float(floor)
json.dump(c, open(dst, "w"), indent=2)
print("written; data_dir =", data_dir, " min_free_gb =", floor)
PY
else
  echo "config already exists, left untouched"
fi
mkdir -p "$BASE_DIR/data"

echo "== 4. unit tests =="
( cd "$ENGINE_DIR" && "$VENV/bin/python" -m pytest collector/tests -q )

if [ "${SKIP_SMOKE:-0}" != "1" ]; then
  echo "== 5. 60 s smoke test (public Binance endpoints, separate folder, real data untouched) =="
  SMOKE="$BASE_DIR/smoke-data"
  rm -rf "$SMOKE"
  "$VENV/bin/python" - "$CFG" "$BASE_DIR/smoke.config.json" "$SMOKE" <<'PY'
import json, sys
c = json.load(open(sys.argv[1])); c["data_dir"] = sys.argv[3]; c["min_free_gb"] = 0.5; c["resume_margin_gb"] = 0.2
json.dump(c, open(sys.argv[2], "w"), indent=2)
PY
  ( cd "$ENGINE_DIR" && "$VENV/bin/python" -m collector --config "$BASE_DIR/smoke.config.json" start --duration 60 ) || true
  SMOKE_OK=0
  "$VENV/bin/python" - "$SMOKE" <<'PY' || SMOKE_OK=1
import sys, pathlib, json
root = pathlib.Path(sys.argv[1]) / "raw"
need = ["aggTrade", "markPrice", "book1s", "depth", "openInterest"]
optional = ["forceOrder"]          # liquidations are rare; none in 60 s is normal
bad = False
for k in need + optional:
    files = list((root / k).rglob("*.jsonl.gz")) if (root / k).exists() else []
    size = sum(f.stat().st_size for f in files)
    tag = "OK" if files else ("MISSING" if k in need else "none (normal in 60 s)")
    if not files and k in need:
        bad = True
    print(f"  {k:<14}{len(files)} file(s) {size:>9,} bytes  {tag}")
gaps = pathlib.Path(sys.argv[1]) / "gaps.jsonl"
if gaps.exists():
    kinds = {}
    for line in gaps.read_text().splitlines():
        k = json.loads(line)["kind"]
        kinds[k] = kinds.get(k, 0) + 1
    print("  gap-log kinds:", kinds)
print("SMOKE TEST:", "FAILED" if bad else "PASSED")
sys.exit(1 if bad else 0)
PY
  rm -rf "$SMOKE" "$BASE_DIR/smoke.config.json"
  if [ "$SMOKE_OK" != "0" ]; then
    echo "Smoke test FAILED. Check outbound access to fstream.binance.com:443 and fapi.binance.com:443 (see README_DEPLOY.md)."
    exit 1
  fi
fi

echo "== 6. status =="
( cd "$ENGINE_DIR" && "$VENV/bin/python" -m collector --config "$CFG" status ) || true
cat <<MSG

Install OK.
  engine dir : $ENGINE_DIR
  venv       : $VENV
  config     : $CFG
  data dir   : $BASE_DIR/data
Start now (no service):   bash $HERE/run_nohup.sh
Or install the service:   see README_DEPLOY.md  (systemd or supervisor section)
MSG
