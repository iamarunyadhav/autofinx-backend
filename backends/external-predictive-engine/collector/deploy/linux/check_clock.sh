#!/usr/bin/env bash
# Clock sync check. The laptop was ~6 s off; a server should be < 50 ms with chrony / systemd-timesyncd.
echo "--- timedatectl ---"; timedatectl 2>/dev/null | grep -E "Local time|Universal|synchronized|NTP" || echo "timedatectl not available"
echo "--- chrony ---";      (chronyc tracking 2>/dev/null | grep -E "System time|Leap status|Stratum") || echo "chronyc not installed"
BASE_DIR="${BASE_DIR:-$HOME/autofinx-collector}"
echo "--- last collector clock checks vs Binance (offset_ms > 0 = server clock behind) ---"
tail -n 3 "$BASE_DIR/data/clock.jsonl" 2>/dev/null || echo "no clock.jsonl yet"
