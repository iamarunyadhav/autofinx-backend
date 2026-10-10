#!/usr/bin/env bash
# Shows the three streams that the legacy Binance URL silently dropped (aggTrade, markPrice, forceOrder),
# plus the freshest data files. aggTrade/markPrice must show a small "last ... s ago"; forceOrder is rare
# (minutes to hours between messages is normal; the collector alarms itself after 1800 s of silence).
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENGINE_DIR="$(cd "$HERE/../../.." && pwd)"
BASE_DIR="${BASE_DIR:-$HOME/autofinx-collector}"
cd "$ENGINE_DIR"
"$BASE_DIR/venv/bin/python" -m collector --config "$BASE_DIR/collector.config.json" status | grep -E "collector:|aggTrade@|markPrice@|forceOrder|connections" || true
echo "--- newest file per kind (epoch size path) ---"
for k in aggTrade markPrice forceOrder book1s depth openInterest; do
  f=$(find "$BASE_DIR/data/raw/$k" -name '*.jsonl.gz' -printf '%T@ %s %p\n' 2>/dev/null | sort -n | tail -1 || true)
  echo "$k: ${f:-NO FILES YET}"
done
echo "--- number of silent_stream alarms in gaps.jsonl (should be 0 or rare) ---"
grep -c '"kind":"silent_stream"' "$BASE_DIR/data/gaps.jsonl" 2>/dev/null || true
