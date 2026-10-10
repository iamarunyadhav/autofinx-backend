#!/usr/bin/env bash
# SERVER-SIDE retention. Deletes only raw/<kind>/<YYYYMMDD> folders OLDER than N days.
# Never touches gaps.jsonl, clock.jsonl, stats/, reports/ (tiny, and needed for the integrity record).
# DRY RUN by default. Only use --yes after you have verified the data is on your PC (pull_data.sh) and backed up.
# Usage: bash cleanup_old.sh --days 14 [--yes]
set -euo pipefail
DAYS=""; YES=0
while [ $# -gt 0 ]; do
  case "$1" in
    --days) DAYS="$2"; shift 2;;
    --yes) YES=1; shift;;
    *) echo "bad arg $1"; exit 1;;
  esac
done
[ -n "$DAYS" ] && [ "$DAYS" -ge 3 ] || { echo "need --days N (N >= 3)"; exit 1; }
BASE_DIR="${BASE_DIR:-$HOME/autofinx-collector}"
CUT=$(date -u -d "-$DAYS days" +%Y%m%d)
echo "cutoff (UTC day, exclusive): $CUT"
find "$BASE_DIR/data/raw" -mindepth 2 -maxdepth 2 -type d -regex '.*/[0-9]\{8\}' | sort | while read -r d; do
  day="$(basename "$d")"
  if [ "$day" -lt "$CUT" ]; then
    if [ "$YES" = 1 ]; then echo "DELETE $d"; rm -rf -- "$d"; else echo "would delete $d (dry run)"; fi
  fi
done
