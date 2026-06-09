#!/usr/bin/env bash
set -u

# AutoFinX Future Trade root diagnostic runner.
# Default mode is read-only. Set TEST_WEBHOOK=1 only when you intentionally
# want to send a TradingView-style webhook into this server.

BACKEND_DIR="${BACKEND_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)}"
WORKSPACE_ID="${WORKSPACE_ID:-}"
BASE_URL="${BASE_URL:-}"
TEST_WEBHOOK="${TEST_WEBHOOK:-0}"
TEST_SIGNAL="${TEST_SIGNAL:-BUY}"
TEST_SYMBOL="${TEST_SYMBOL:-BTCUSDT.P}"
TEST_CONFIDENCE="${TEST_CONFIDENCE:-99}"
RUN_PHP_TESTS="${RUN_PHP_TESTS:-0}"
RUN_TICK="${RUN_TICK:-1}"
RUN_USER_STREAM_PROBE="${RUN_USER_STREAM_PROBE:-0}"
REPORT_ROOT="${REPORT_ROOT:-$BACKEND_DIR/autofinx-test-reports}"
STAMP="$(date +%Y%m%d-%H%M%S)"
REPORT_DIR="$REPORT_ROOT/future-trade-root-diagnose-$STAMP"
REPORT_MD="$REPORT_DIR/report.md"
REPORT_JSON="$REPORT_DIR/report.json"

mkdir -p "$REPORT_DIR"
cd "$BACKEND_DIR" || exit 1

PASS_COUNT=0
WARN_COUNT=0
FAIL_COUNT=0
INFO_COUNT=0

mask() {
  local value="${1:-}"
  if [ -z "$value" ]; then
    printf ""
  elif [ "${#value}" -le 8 ]; then
    printf "***"
  else
    printf "%s***%s" "${value:0:4}" "${value: -4}"
  fi
}

md() { printf "%s\n" "$*" >> "$REPORT_MD"; }
json_escape() {
  php -r 'echo json_encode($argv[1], JSON_UNESCAPED_SLASHES);' "$1" 2>/dev/null || printf '"%s"' "$1"
}

add_result() {
  local level="$1"; shift
  local title="$1"; shift
  local detail="${*:-}"
  case "$level" in
    PASS) PASS_COUNT=$((PASS_COUNT + 1)) ;;
    WARN) WARN_COUNT=$((WARN_COUNT + 1)) ;;
    FAIL) FAIL_COUNT=$((FAIL_COUNT + 1)) ;;
    INFO) INFO_COUNT=$((INFO_COUNT + 1)) ;;
  esac
  md "- **$level**: $title${detail:+ - $detail}"
  printf "[%s] %s%s\n" "$level" "$title" "${detail:+ - $detail}"
}

section() {
  md ""
  md "## $1"
  printf "\n== %s ==\n" "$1"
}

run_capture() {
  local name="$1"; shift
  local outfile="$REPORT_DIR/$name.txt"
  md ""
  md "### $name"
  md '```text'
  {
    printf "$ %s\n" "$*"
    "$@"
  } > "$outfile" 2>&1
  local code=$?
  sed -n '1,220p' "$outfile" >> "$REPORT_MD"
  md '```'
  if [ "$code" -eq 0 ]; then
    add_result PASS "$name completed" "exit=$code"
  else
    add_result WARN "$name returned non-zero" "exit=$code, see $outfile"
  fi
  return "$code"
}

detect_base_url() {
  if [ -n "$BASE_URL" ]; then
    return
  fi
  local app_url
  app_url="$(php -r '
    $env = @parse_ini_file(".env", false, INI_SCANNER_RAW);
    echo trim((string)($env["APP_URL"] ?? ""));
  ' 2>/dev/null)"
  if [ -n "$app_url" ]; then
    BASE_URL="$app_url"
  else
    BASE_URL="http://127.0.0.1"
  fi
}

read_env_value() {
  local key="$1"
  php -r '
    $env = @parse_ini_file(".env", false, INI_SCANNER_RAW);
    echo trim((string)($env[$argv[1]] ?? ""));
  ' "$key" 2>/dev/null
}

php_probe_file="$REPORT_DIR/ft_probe.php"
cat > "$php_probe_file" <<'PHP'
<?php
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Domain\Exchange\BinanceFuturesClient;
use App\Domain\Exchange\BinanceRestCircuitBreaker;
use App\Domain\FutureTrade\FutureTradeBotService;
use App\Models\FutureTradeBotConfig;
use App\Models\FutureTradeBotLog;
use App\Models\FutureTradeLogicalTrade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

$workspace = trim((string) getenv('WORKSPACE_ID'));
$out = [
    'generated_at' => date('c'),
    'workspace_filter' => $workspace ?: null,
    'environment' => [
        'app_env' => config('app.env'),
        'app_debug' => config('app.debug'),
        'queue_connection' => config('queue.default'),
        'cache_store' => config('cache.default'),
        'future_trade' => [
            'pine_token_configured' => trim((string) config('future_trade.pine_webhook_token')) !== '',
            'shared_hosting_mode' => (bool) config('future_trade.shared_hosting_mode'),
            'schedule_runtime_commands' => (bool) config('future_trade.schedule.runtime_commands'),
            'schedule_reconcile' => (bool) config('future_trade.schedule.reconcile'),
            'schedule_diagnostics' => (bool) config('future_trade.schedule.diagnostics'),
            'schedule_audit_missed_signals' => (bool) config('future_trade.schedule.audit_missed_signals'),
            'pine_webhook_live_enabled' => (bool) config('future_trade.live.pine_webhook_enabled'),
            'permissive_symbol_mode' => (bool) config('future_trade.permissive_symbol_mode'),
        ],
        'binance_env' => [
            'futures_key_configured' => trim((string) config('services.binance.futures.key', env('BINANCE_FUTURES_KEY', ''))) !== '',
            'futures_secret_configured' => trim((string) config('services.binance.futures.secret', env('BINANCE_FUTURES_SECRET', ''))) !== '',
            'force_testnet' => (bool) config('future_trade.force_testnet'),
            'rest_min_interval_ms' => (int) config('future_trade.binance_rest.min_interval_ms'),
            'recv_window_ms' => (int) config('future_trade.binance_rest.recv_window_ms'),
        ],
    ],
    'schema' => [],
    'queues' => [],
    'configs' => [],
    'latest_logs' => [],
    'latest_trades' => [],
    'blocker_summary' => [],
    'binance_public' => [],
    'binance_circuit' => [],
];

foreach (['jobs','failed_jobs','future_trade_bot_configs','future_trade_bot_states','future_trade_bot_logs','future_trade_logical_trades','future_trade_logical_trade_events','future_trade_runtime_instances','market_feed_statuses','scheduler_runs'] as $table) {
    $out['schema'][$table] = Schema::hasTable($table);
}

if (Schema::hasTable('jobs')) {
    foreach (['critical','execution','reconcile','monitoring','market','signals','strategies','ratio_bot','ops','intelligence','risk','analytics','notifications','default','monitoring_safe'] as $queue) {
        $pending = DB::table('jobs')->where('queue', $queue)->count();
        $oldest = DB::table('jobs')->where('queue', $queue)->min('created_at');
        $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->where('queue', $queue)->count() : 0;
        $out['queues'][$queue] = ['pending' => $pending, 'failed' => $failed, 'oldest_created_at' => $oldest];
    }
}

$configQuery = FutureTradeBotConfig::query()->with('state')->orderBy('workspace_id')->orderBy('slot_index');
if ($workspace !== '') {
    $configQuery->where('workspace_id', $workspace);
}
$configs = $configQuery->get();

foreach ($configs as $config) {
    $settings = $config->mergedSettings();
    $state = $config->state;
    $openCount = FutureTradeLogicalTrade::query()
        ->where('future_trade_bot_config_id', $config->id)
        ->whereIn('status', FutureTradeLogicalTrade::ACTIVE_STATUSES)
        ->count();

    $hardSoft = [
        'signal_source' => $settings['signalSource'] ?? null,
        'timeframe' => $settings['timeframe'] ?? null,
        'min_confidence' => $settings['minConfidence'] ?? null,
        'trade_score_threshold' => $settings['tradeScoreThreshold'] ?? null,
        'strict_entry_guards' => $settings['strictEntryGuards'] ?? null,
        'avoid_choppy_market' => $settings['avoidChoppyMarket'] ?? null,
        'use_vwap' => $settings['useVWAP'] ?? null,
        'use_volume' => $settings['useVolume'] ?? null,
        'use_volatility_filter' => $settings['useVolatilityFilter'] ?? null,
        'use_chop_filter' => $settings['useChopFilter'] ?? null,
        'use_momentum_breakout_filter' => $settings['useMomentumBreakoutFilter'] ?? null,
        'max_daily_loss_percent' => $settings['maxDailyLossPercent'] ?? null,
        'soft_daily_loss_warn_percent' => $settings['softDailyLossWarnPercent'] ?? null,
        'hard_daily_loss_block_percent' => $settings['hardDailyLossBlockPercent'] ?? null,
        'cooldown_seconds' => $settings['cooldownSeconds'] ?? null,
        'max_open_positions' => $settings['maxOpenPositions'] ?? null,
        'max_trades_per_day' => $settings['maxTradesPerDay'] ?? null,
        'max_spread_percent' => $settings['maxSpreadPercent'] ?? null,
        'restrict_to_workspace_symbols' => $settings['restrictToWorkspaceSymbols'] ?? null,
        'permissive_symbol_mode' => $settings['permissiveSymbolMode'] ?? null,
    ];

    $possibleBlockers = [];
    if (! $config->enabled) $possibleBlockers[] = 'bot_disabled';
    if ($state && in_array((string) $state->state, ['EMERGENCY_STOP','STOPPED','ERROR'], true)) $possibleBlockers[] = 'state_'.$state->state;
    if (($settings['emergencyStop'] ?? false) === true) $possibleBlockers[] = 'emergency_stop_setting';
    if ($openCount >= (int)($settings['maxOpenPositions'] ?? 1)) $possibleBlockers[] = 'max_open_positions_reached';
    if (($settings['signalSource'] ?? '') !== 'webhook') $possibleBlockers[] = 'signal_source_not_webhook';
    if (! (bool) config('future_trade.live.pine_webhook_enabled')) $possibleBlockers[] = 'pine_webhook_live_disabled';
    if ((float)($settings['minConfidence'] ?? 0) > 1 && (float)($settings['minConfidence'] ?? 0) > 100) $possibleBlockers[] = 'invalid_min_confidence';
    if ((float)($settings['tradeScoreThreshold'] ?? 0) > 100) $possibleBlockers[] = 'invalid_trade_score_threshold';

    $out['configs'][] = [
        'id' => (string) $config->id,
        'workspace_id' => $config->workspace_id,
        'slot_index' => $config->slot_index,
        'enabled' => (bool) $config->enabled,
        'execution_mode' => $config->executionMode(),
        'paper_trading' => (bool) $config->paper_trading,
        'enable_live_trading' => (bool) $config->enable_live_trading,
        'state' => $state?->state,
        'status' => $state?->status,
        'connection_status' => $state?->connection_status,
        'current_symbol' => $state?->current_symbol,
        'current_signal' => $state?->current_signal,
        'confidence' => $state?->confidence,
        'last_scan_at' => $state?->last_scan_at?->toIso8601String(),
        'last_trade_at' => $state?->last_trade_at?->toIso8601String(),
        'trading_day' => $state?->trading_day,
        'open_count_for_slot' => $openCount,
        'settings' => $hardSoft,
        'possible_static_blockers' => $possibleBlockers,
    ];
}

$ids = $configs->pluck('id');
if ($ids->isNotEmpty()) {
    $logs = FutureTradeBotLog::query()
        ->whereIn('future_trade_bot_config_id', $ids)
        ->latest('occurred_at')
        ->limit(80)
        ->get(['event','symbol','signal','confidence','reason','context','occurred_at','future_trade_bot_config_id']);
    foreach ($logs as $log) {
        $context = is_array($log->context) ? $log->context : [];
        $out['latest_logs'][] = [
            'occurred_at' => $log->occurred_at?->toIso8601String(),
            'config_id' => (string) $log->future_trade_bot_config_id,
            'event' => $log->event,
            'symbol' => $log->symbol,
            'signal' => $log->signal,
            'confidence' => $log->confidence,
            'reason' => $log->reason,
            'execution' => $context['execution'] ?? null,
            'blockers' => $context['blockers'] ?? $context['freshnessBlockers'] ?? null,
            'latency' => $context['latency'] ?? null,
            'binance' => $context['binance'] ?? $context['binanceError'] ?? null,
        ];
        $reason = trim((string) ($log->reason ?: $log->event));
        if ($reason !== '') {
            $out['blocker_summary'][$reason] = ($out['blocker_summary'][$reason] ?? 0) + 1;
        }
    }

    $trades = FutureTradeLogicalTrade::query()
        ->whereIn('future_trade_bot_config_id', $ids)
        ->latest('created_at')
        ->limit(30)
        ->get(['future_trade_bot_config_id','symbol','side','status','entry_price','quantity','gross_pnl','net_pnl','opened_at','closed_at','signal_key','meta','created_at']);
    foreach ($trades as $trade) {
        $out['latest_trades'][] = [
            'created_at' => $trade->created_at?->toIso8601String(),
            'config_id' => (string) $trade->future_trade_bot_config_id,
            'symbol' => $trade->symbol,
            'side' => $trade->side,
            'status' => $trade->status,
            'entry_price' => $trade->entry_price,
            'quantity' => $trade->quantity,
            'gross_pnl' => $trade->gross_pnl,
            'net_pnl' => $trade->net_pnl,
            'opened_at' => $trade->opened_at?->toIso8601String(),
            'closed_at' => $trade->closed_at?->toIso8601String(),
            'signal_key' => $trade->signal_key,
            'meta_reason' => is_array($trade->meta) ? ($trade->meta['reason'] ?? null) : null,
        ];
    }
}

try {
    $started = microtime(true);
    $response = Http::timeout(8)->get('https://fapi.binance.com/fapi/v1/time');
    $out['binance_public']['time'] = [
        'ok' => $response->successful(),
        'status' => $response->status(),
        'latency_ms' => (int) round((microtime(true) - $started) * 1000),
        'body' => $response->json(),
    ];
} catch (Throwable $e) {
    $out['binance_public']['time'] = ['ok' => false, 'error' => $e->getMessage()];
}

try {
    $started = microtime(true);
    $response = Http::timeout(12)->get('https://fapi.binance.com/fapi/v1/exchangeInfo', ['symbol' => 'BTCUSDT']);
    $json = $response->json();
    $filters = $json['symbols'][0]['filters'] ?? [];
    $out['binance_public']['exchange_info_btcusdt'] = [
        'ok' => $response->successful(),
        'status' => $response->status(),
        'latency_ms' => (int) round((microtime(true) - $started) * 1000),
        'filters' => $filters,
    ];
} catch (Throwable $e) {
    $out['binance_public']['exchange_info_btcusdt'] = ['ok' => false, 'error' => $e->getMessage()];
}

try {
    $out['binance_circuit'] = app(BinanceRestCircuitBreaker::class)->status();
} catch (Throwable $e) {
    $out['binance_circuit'] = ['error' => $e->getMessage()];
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
PHP

{
  md "# AutoFinX Future Trade Root Diagnostic Report"
  md ""
  md "- Generated: $(date -Iseconds)"
  md "- Backend: \`$BACKEND_DIR\`"
  md "- Workspace filter: \`${WORKSPACE_ID:-all}\`"
  md "- Default mode: read-only unless \`TEST_WEBHOOK=1\`"
} > "$REPORT_MD"

section "Root And Binary Checks"
if [ "$(id -u)" -eq 0 ]; then
  add_result PASS "Running as root"
else
  add_result WARN "Not running as root" "Some supervisor/nginx/process checks may be limited."
fi

for bin in php composer curl awk sed grep date ps; do
  if command -v "$bin" >/dev/null 2>&1; then
    add_result PASS "Binary found: $bin" "$(command -v "$bin")"
  else
    add_result FAIL "Binary missing: $bin"
  fi
done

run_capture "php-version" php -v
run_capture "laravel-version" php artisan --version

section "Environment And Flow"
PINE_TOKEN="$(read_env_value FUTURE_TRADE_PINE_WEBHOOK_TOKEN)"
APP_URL_ENV="$(read_env_value APP_URL)"
QUEUE_CONNECTION="$(read_env_value QUEUE_CONNECTION)"
detect_base_url

add_result INFO "APP_URL" "${APP_URL_ENV:-not_set}"
add_result INFO "BASE_URL used for HTTP probes" "$BASE_URL"
add_result INFO "QUEUE_CONNECTION" "${QUEUE_CONNECTION:-not_set}"
if [ -n "$PINE_TOKEN" ]; then
  add_result PASS "FUTURE_TRADE_PINE_WEBHOOK_TOKEN configured" "$(mask "$PINE_TOKEN")"
else
  add_result FAIL "FUTURE_TRADE_PINE_WEBHOOK_TOKEN missing" "TradingView webhook will return invalid_or_missing_webhook_token."
fi

md ""
md "### Actual Trade Flow"
md "1. TradingView sends \`POST /api/v1/future-trade/pine-webhook/{workspace}\`."
md "2. Controller validates token, stores raw webhook audit, resolves symbol slot."
md "3. Controller dispatches \`ProcessPineWebhookSignalJob\` onto queue \`execution\` and returns fast \`queued\` response."
md "4. Queue worker runs the job, then \`FutureTradeBotService::ingestPineWebhook\` applies hard/soft guards."
md "5. If allowed, paper/testnet/live execution creates logical trade and/or Binance Futures order."
md ""
md "TradingView timeout usually means step 1-3 took too long. Trade not opening usually means step 3 job did not run, or step 4/5 blocked."

section "Laravel Runtime Probes"
WORKSPACE_ID="$WORKSPACE_ID" php "$php_probe_file" > "$REPORT_JSON" 2> "$REPORT_DIR/php-probe-error.txt"
if [ "$?" -eq 0 ]; then
  add_result PASS "PHP runtime probe completed" "$REPORT_JSON"
else
  add_result FAIL "PHP runtime probe failed" "see $REPORT_DIR/php-probe-error.txt"
fi

run_capture "future-trade-runtime-status-json" php artisan future-trade:runtime-status --json ${WORKSPACE_ID:+--workspace="$WORKSPACE_ID"}
if [ -n "$WORKSPACE_ID" ]; then
  run_capture "future-trade-diagnostics-json" php artisan future-trade:diagnostics "$WORKSPACE_ID" --json
else
  run_capture "future-trade-diagnostics-json" php artisan future-trade:diagnostics --json
fi
run_capture "future-trade-queue-health" php artisan future-trade:queue-health

section "Process Supervisor Scheduler"
run_capture "processes-autofinx" bash -lc "ps aux | grep -E 'artisan|queue:work|horizon|future-trade|supervisord|nginx|apache|php-fpm' | grep -v grep || true"
if command -v supervisorctl >/dev/null 2>&1; then
  run_capture "supervisor-status" supervisorctl status
else
  add_result WARN "supervisorctl not found" "If workers are managed another way, ignore this."
fi
run_capture "crontab-root" bash -lc "crontab -l 2>/dev/null || true"
run_capture "systemctl-relevant" bash -lc "systemctl --no-pager --type=service --state=running 2>/dev/null | grep -Ei 'nginx|apache|php|redis|mysql|mariadb|supervisor|queue|autofinx' || true"

section "HTTP Latency And Webhook Endpoint"
run_capture "http-health-root" bash -lc "curl -k -sS -o /tmp/autofinx_http_root.$$ -w 'http_code=%{http_code} total=%{time_total} connect=%{time_connect} starttransfer=%{time_starttransfer}\n' '$BASE_URL' ; head -c 400 /tmp/autofinx_http_root.$$ ; rm -f /tmp/autofinx_http_root.$$"

if [ -n "$WORKSPACE_ID" ]; then
  LAST_WEBHOOK_URL="$BASE_URL/api/v1/workspaces/$WORKSPACE_ID/future-trade/webhook/last"
  run_capture "http-last-webhook" bash -lc "curl -k -sS -o /tmp/autofinx_last_webhook.$$ -w 'http_code=%{http_code} total=%{time_total} starttransfer=%{time_starttransfer}\n' '$LAST_WEBHOOK_URL' ; cat /tmp/autofinx_last_webhook.$$ ; rm -f /tmp/autofinx_last_webhook.$$"
else
  add_result WARN "WORKSPACE_ID not set" "Skipping workspace API probes. Run with WORKSPACE_ID=your-id."
fi

if [ "$TEST_WEBHOOK" = "1" ]; then
  if [ -z "$WORKSPACE_ID" ]; then
    add_result FAIL "TEST_WEBHOOK requested but WORKSPACE_ID missing"
  elif [ -z "$PINE_TOKEN" ]; then
    add_result FAIL "TEST_WEBHOOK requested but FUTURE_TRADE_PINE_WEBHOOK_TOKEN missing"
  else
    WEBHOOK_URL="$BASE_URL/api/v1/future-trade/pine-webhook/$WORKSPACE_ID"
    PAYLOAD="$REPORT_DIR/test-webhook-payload.json"
    cat > "$PAYLOAD" <<JSON
{"token":"$PINE_TOKEN","profile":"autofinx_prime_v6_1_7_crypto","symbol":"$TEST_SYMBOL","timeframe":"15m","signal":"$TEST_SIGNAL","confidence":$TEST_CONFIDENCE,"price":0,"source":"pine_webhook","debug":{"diagnosticRun":"$STAMP"}}
JSON
    run_capture "http-test-pine-webhook" bash -lc "curl -k -sS -X POST '$WEBHOOK_URL' -H 'Content-Type: application/json' --data-binary '@$PAYLOAD' -o /tmp/autofinx_test_webhook.$$ -w 'http_code=%{http_code} total=%{time_total} starttransfer=%{time_starttransfer}\n' ; cat /tmp/autofinx_test_webhook.$$ ; rm -f /tmp/autofinx_test_webhook.$$"
    add_result WARN "Webhook test was sent" "Check execution queue/job logs after this point. Signal=$TEST_SIGNAL symbol=$TEST_SYMBOL confidence=$TEST_CONFIDENCE"
    run_capture "queue-after-test-webhook" php artisan future-trade:queue-health
    run_capture "runtime-after-test-webhook" php artisan future-trade:runtime-status --json --workspace="$WORKSPACE_ID"
  fi
else
  add_result INFO "Webhook send skipped" "Set TEST_WEBHOOK=1 WORKSPACE_ID=... to send a TradingView-style webhook."
fi

section "Scanner And Stream Probes"
if [ "$RUN_TICK" = "1" ]; then
  if [ -n "$WORKSPACE_ID" ]; then
    run_capture "future-trade-tick-no-execute" php artisan future-trade:tick --workspace="$WORKSPACE_ID" --duration=6 --sleep-ms=1000 --no-execute
  else
    run_capture "future-trade-tick-no-execute" php artisan future-trade:tick --duration=6 --sleep-ms=1000 --no-execute
  fi
else
  add_result INFO "Tick probe skipped" "RUN_TICK=0"
fi

if [ "$RUN_USER_STREAM_PROBE" = "1" ]; then
  if [ -n "$WORKSPACE_ID" ]; then
    run_capture "future-trade-user-stream-probe" php artisan future-trade:user-stream --workspace="$WORKSPACE_ID" --duration=8
  else
    run_capture "future-trade-user-stream-probe" php artisan future-trade:user-stream --duration=8
  fi
else
  add_result INFO "User-stream active probe skipped" "Set RUN_USER_STREAM_PROBE=1 only if Binance futures credentials are expected to work."
fi

section "Binance Network Checks"
run_capture "binance-futures-time-curl" bash -lc "curl -sS -w '\nhttp_code=%{http_code} total=%{time_total} starttransfer=%{time_starttransfer}\n' --max-time 10 https://fapi.binance.com/fapi/v1/time"
run_capture "binance-futures-exchange-info-btcusdt-curl" bash -lc "curl -sS -w '\nhttp_code=%{http_code} total=%{time_total} starttransfer=%{time_starttransfer}\n' --max-time 15 'https://fapi.binance.com/fapi/v1/exchangeInfo?symbol=BTCUSDT' | head -c 4000"

section "Recent Logs"
for log in storage/logs/laravel.log storage/logs/queue-worker.log storage/logs/future-trade-worker.log; do
  if [ -f "$log" ]; then
    run_capture "tail-$(basename "$log")" bash -lc "tail -n 260 '$log' | grep -Ei 'PINE_WEBHOOK|TRADE_BLOCKED|SIGNAL_SKIPPED|BINANCE|USER_STREAM|FT TICK|QUEUE|ERROR|timeout|latency|blocked|EXECUTED' || tail -n 160 '$log'"
  else
    add_result WARN "Log file missing: $log"
  fi
done

section "Optional Laravel Tests"
if [ "$RUN_PHP_TESTS" = "1" ]; then
  run_capture "phpunit-future-trade-focused" php artisan test \
    --filter='FutureTrade|BinanceFutures|WebhookIdempotency|UserStreamGate|TradeNotBlockedSoftGuard|FreshnessGateFallback|ExecutionModeRouting'
else
  add_result INFO "PHP tests skipped" "Set RUN_PHP_TESTS=1 to run focused FutureTrade/Binance suites. Avoid on production unless test DB is isolated."
fi

section "Automated Diagnosis Summary"
php -r '
$json = json_decode(file_get_contents($argv[1]), true) ?: [];
$configs = $json["configs"] ?? [];
$queues = $json["queues"] ?? [];
$logs = $json["latest_logs"] ?? [];
$env = $json["environment"] ?? [];
$reasons = [];
foreach (($json["blocker_summary"] ?? []) as $reason => $count) {
    $reasons[] = [$count, $reason];
}
rsort($reasons);
echo "Configs found: ".count($configs).PHP_EOL;
foreach ($configs as $cfg) {
    echo "- workspace={$cfg["workspace_id"]} slot={$cfg["slot_index"]} enabled=".($cfg["enabled"] ? "yes" : "no")." mode={$cfg["execution_mode"]} state={$cfg["state"]} signal_source=".($cfg["settings"]["signal_source"] ?? "")." confidence=".($cfg["confidence"] ?? "null").PHP_EOL;
    $blockers = $cfg["possible_static_blockers"] ?? [];
    echo "  static blockers: ".($blockers ? implode(", ", $blockers) : "none").PHP_EOL;
    echo "  hard/soft: minConfidence=".($cfg["settings"]["min_confidence"] ?? "null").", tradeScoreThreshold=".($cfg["settings"]["trade_score_threshold"] ?? "null").", strictEntryGuards=".json_encode($cfg["settings"]["strict_entry_guards"] ?? null).", avoidChoppyMarket=".json_encode($cfg["settings"]["avoid_choppy_market"] ?? null).", maxDailyLoss=".($cfg["settings"]["max_daily_loss_percent"] ?? "null").", hardDailyLoss=".($cfg["settings"]["hard_daily_loss_block_percent"] ?? "null").PHP_EOL;
}
echo "Execution queue: pending=".(int)($queues["execution"]["pending"] ?? 0)." failed=".(int)($queues["execution"]["failed"] ?? 0).PHP_EOL;
echo "Binance public time ok=".json_encode($json["binance_public"]["time"]["ok"] ?? null)." latency_ms=".json_encode($json["binance_public"]["time"]["latency_ms"] ?? null).PHP_EOL;
echo "Pine token configured=".json_encode($env["future_trade"]["pine_token_configured"] ?? null).PHP_EOL;
echo "Top recent reasons/events:".PHP_EOL;
foreach (array_slice($reasons, 0, 15) as [$count, $reason]) {
    echo "- $reason: $count".PHP_EOL;
}
echo "Latest log event: ";
if ($logs) {
    $l = $logs[0];
    echo ($l["occurred_at"] ?? "")." ".($l["event"] ?? "")." symbol=".($l["symbol"] ?? "")." signal=".($l["signal"] ?? "")." reason=".($l["reason"] ?? "").PHP_EOL;
} else {
    echo "none".PHP_EOL;
}
' "$REPORT_JSON" | tee "$REPORT_DIR/diagnosis-summary.txt" | sed -n '1,120p' >> "$REPORT_MD"

section "Final Counts"
add_result INFO "Report directory" "$REPORT_DIR"
add_result INFO "Markdown report" "$REPORT_MD"
add_result INFO "JSON probe" "$REPORT_JSON"
md ""
md "- PASS: $PASS_COUNT"
md "- WARN: $WARN_COUNT"
md "- FAIL: $FAIL_COUNT"
md "- INFO: $INFO_COUNT"

printf '{"report_dir":%s,"report_md":%s,"report_json":%s,"pass":%d,"warn":%d,"fail":%d,"info":%d}\n' \
  "$(json_escape "$REPORT_DIR")" \
  "$(json_escape "$REPORT_MD")" \
  "$(json_escape "$REPORT_JSON")" \
  "$PASS_COUNT" "$WARN_COUNT" "$FAIL_COUNT" "$INFO_COUNT" > "$REPORT_DIR/summary.json"

printf "\nDONE\nReport: %s\nJSON: %s\nSummary: %s\n" "$REPORT_MD" "$REPORT_JSON" "$REPORT_DIR/summary.json"
