#!/usr/bin/env bash
# autofinx_ws_reset.sh
# Run this on the server as root to fix the Binance WebSocket reconnect loop.
# Usage: bash autofinx_ws_reset.sh

set -euo pipefail

APP_DIR="$(cd "$(dirname "$0")" && pwd)"
BLUE='\033[0;34m'; GREEN='\033[0;32m'; RED='\033[0;31m'; YELLOW='\033[1;33m'; NC='\033[0m'

step() { echo -e "\n${BLUE}[STEP $1]${NC} $2"; }
ok()   { echo -e "${GREEN}✓${NC} $1"; }
warn() { echo -e "${YELLOW}⚠${NC}  $1"; }
fail() { echo -e "${RED}✗${NC}  $1"; }

echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}  AutoFinX WebSocket Reset Script${NC}"
echo -e "${BLUE}========================================${NC}"

# ── STEP 1: Stop live-feed and kill rogue workers ────────────────────────────
step 1 "Stopping live-feed and cleaning rogue processes..."
supervisorctl stop autofinx-trading:live-feed 2>/dev/null && ok "live-feed stopped" || warn "live-feed was not running"

# Kill the non-supervisor queue worker reported in health check
pkill -f "artisan queue:work --queue=critical,execution,reconcile,monitoring,market,signals,strategies,ratio_bot,ops,intelligence,risk,billing,copytrading,analytics,predictions,notifications,default,monitoring_safe" 2>/dev/null \
  && ok "Rogue queue worker killed" \
  || ok "No rogue queue worker found"

sleep 2

# ── STEP 2: Clear caches ─────────────────────────────────────────────────────
step 2 "Clearing Laravel config + application cache..."
cd "$APP_DIR"
php artisan config:clear  && ok "Config cache cleared"
php artisan cache:clear   && ok "App cache cleared"

# ── STEP 3: Quick Binance connectivity test ──────────────────────────────────
step 3 "Testing Binance REST API..."
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" --max-time 5 "https://fapi.binance.com/fapi/v1/ping" 2>/dev/null || echo "000")
if [ "$HTTP_CODE" = "200" ]; then
  ok "Binance REST API reachable (HTTP $HTTP_CODE)"
else
  warn "Binance REST returned HTTP $HTTP_CODE — check network/firewall"
fi

# ── STEP 4: Test WebSocket handshake ─────────────────────────────────────────
step 4 "Testing Binance WebSocket handshake..."
WS_RESULT=$(php -r '
$socket = @stream_socket_client("tls://fstream.binance.com:443", $e, $m, 10);
if (!$socket) { echo "CONNECT_FAIL: " . $m; exit; }
$key = base64_encode(random_bytes(16));
fwrite($socket, "GET /stream?streams=btcusdt@trade HTTP/1.1\r\nHost: fstream.binance.com\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n\r\n");
$buf = ""; $t = microtime(true) + 5;
while (microtime(true) < $t && !str_contains($buf, "\r\n\r\n")) {
    $r = [$socket]; $w = null; $x = null;
    if (@stream_select($r, $w, $x, 0, 200000) > 0) {
        $c = fread($socket, 4096);
        if ($c === false || ($c === "" && feof($socket))) { echo "EOF_DURING_HANDSHAKE"; break; }
        $buf .= $c;
    }
}
if (!str_contains($buf, " 101 ")) { echo "HANDSHAKE_FAIL: " . trim(explode("\r\n", $buf)[0] ?? ""); fclose($socket); exit; }
echo "HANDSHAKE_OK";
// Wait up to 3 s for first frame
$r = [$socket]; $w = null; $x = null;
$ready = @stream_select($r, $w, $x, 3, 0);
if ($ready === 0) { echo ":TIMEOUT_NO_DATA"; fclose($socket); exit; }
$byte1 = fread($socket, 1);
if ($byte1 === false || $byte1 === "" || feof($socket)) { echo ":IMMEDIATE_CLOSE"; fclose($socket); exit; }
$opcode = ord($byte1) & 0x0F;
echo ":FIRST_FRAME_OPCODE_" . $opcode;
fclose($socket);
' 2>/dev/null)

echo "   Result: $WS_RESULT"

if [[ "$WS_RESULT" == "HANDSHAKE_OK:FIRST_FRAME_OPCODE_1" ]]; then
  ok "WebSocket stable — Binance is sending text (market) frames"
  BINANCE_READY=1
elif [[ "$WS_RESULT" == "HANDSHAKE_OK:FIRST_FRAME_OPCODE_8" ]]; then
  warn "Binance accepted handshake but sent CLOSE frame (still rate-limited)"
  BINANCE_READY=0
elif [[ "$WS_RESULT" == "HANDSHAKE_OK:IMMEDIATE_CLOSE" ]]; then
  warn "Binance accepted handshake but closed TCP immediately (IP rate-limited)"
  BINANCE_READY=0
elif [[ "$WS_RESULT" == "HANDSHAKE_OK:TIMEOUT_NO_DATA" ]]; then
  warn "Connected but no data in 3 s — may be fine, starting anyway"
  BINANCE_READY=1
else
  fail "Connection problem: $WS_RESULT"
  BINANCE_READY=0
fi

# ── STEP 5: Wait if rate-limited ─────────────────────────────────────────────
if [ "${BINANCE_READY:-0}" = "0" ]; then
  step 5 "Waiting 8 minutes for Binance connection-rate limit to clear..."
  echo "   (Binance soft-bans IPs that make 300+ WebSocket connections per 5 min)"
  for i in $(seq 8 -1 1); do
    echo -ne "   ${i} minute(s) remaining...\r"
    sleep 60
  done
  echo ""
  ok "Wait complete"

  # Re-test
  echo "   Re-testing WebSocket..."
  WS_RESULT2=$(php -r '
$socket = @stream_socket_client("tls://fstream.binance.com:443", $e, $m, 10);
if (!$socket) { echo "CONNECT_FAIL"; exit; }
$key = base64_encode(random_bytes(16));
fwrite($socket, "GET /stream?streams=btcusdt@trade HTTP/1.1\r\nHost: fstream.binance.com\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n\r\n");
$buf = ""; $t = microtime(true) + 5;
while (microtime(true) < $t && !str_contains($buf, "\r\n\r\n")) {
    $r = [$socket]; $w = null; $x = null;
    if (@stream_select($r, $w, $x, 0, 200000) > 0) { $buf .= (string)fread($socket, 4096); }
}
if (!str_contains($buf, " 101 ")) { echo "HANDSHAKE_FAIL"; fclose($socket); exit; }
$r = [$socket]; $w = null; $x = null;
$ready = @stream_select($r, $w, $x, 3, 0);
if ($ready === 0) { echo "HANDSHAKE_OK:NO_DATA"; fclose($socket); exit; }
$b = fread($socket, 1);
if ($b === false || $b === "" || feof($socket)) { echo "HANDSHAKE_OK:STILL_BANNED"; fclose($socket); exit; }
echo "HANDSHAKE_OK:DATA_" . (ord($b) & 0x0F);
fclose($socket);
' 2>/dev/null)
  echo "   Re-test result: $WS_RESULT2"
  if [[ "$WS_RESULT2" == *"DATA_1"* ]] || [[ "$WS_RESULT2" == *"NO_DATA"* ]]; then
    ok "Binance is now accepting connections"
  else
    warn "Still getting closed — will start anyway (circuit breaker in code will manage)"
  fi
else
  step 5 "Skipped (Binance already accepting connections)"
fi

# ── STEP 6: Start live-feed ──────────────────────────────────────────────────
step 6 "Starting live-feed..."
supervisorctl start autofinx-trading:live-feed && ok "live-feed started" || fail "Failed to start live-feed"

sleep 5

# ── STEP 7: Verify all processes ─────────────────────────────────────────────
step 7 "Verifying all AutoFinX supervisor processes..."
supervisorctl status autofinx-trading:

# ── STEP 8: Watch for 30 seconds ─────────────────────────────────────────────
step 8 "Watching live-feed log for 30 seconds..."
echo "   (Ctrl+C to stop watching — processes will keep running)"
timeout 30 supervisorctl tail -f autofinx-trading:live-feed 2>/dev/null || true

echo ""
echo -e "${GREEN}==============================${NC}"
echo -e "${GREEN}  Reset complete${NC}"
echo -e "${GREEN}==============================${NC}"
echo ""
echo "After 60+ seconds, run this to check market feed freshness:"
echo "  php ${APP_DIR}/artisan future-trade:runtime-status"
