<?php

// CLI-only, read-only diagnostics. No ingest(), orders, queue dispatch, or resets.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__, 2);
chdir($root);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

function report(string $label, mixed $value): void {
    echo json_encode(['section' => $label, 'data' => $value], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL;
}
function attempt(string $label, callable $read): void {
    try { report($label, $read()); }
    catch (Throwable $e) { report($label, ['error_class' => get_class($e), 'error_code' => $e->getCode()]); }
}
function command(string $label, array $args): void {
    attempt($label, function () use ($args) {
        $p = new \Symfony\Component\Process\Process($args);
        $p->setTimeout(12);
        $p->run();
        // Only fixed commands; output is still private operational evidence.
        return ['exit' => $p->getExitCode(), 'stdout' => substr($p->getOutput(), -18000), 'stderr' => substr($p->getErrorOutput(), -2000)];
    });
}

report('runtime', ['utc' => gmdate('c'), 'php' => PHP_VERSION, 'binary' => PHP_BINARY, 'os' => PHP_OS_FAMILY]);
command('revision', ['git', 'log', '-1', '--format=%h %cs']);
command('tracked_changes', ['git', 'status', '--short', '--untracked-files=no']);
command('supervisor', ['supervisorctl', 'status']);
foreach (['live-feed', 'user-stream-loop'] as $program) {
    // Supervisor -N means bytes, NOT lines. Earlier -100 hid the useful errors.
    command('log_'.$program, ['supervisorctl', 'tail', '-16000', 'autofinx-trading:'.$program, 'stdout']);
}
command('predictions_log', ['supervisorctl', 'tail', '-4000', 'autofinx-predictions', 'stdout']);
command('load', ['uptime']);
command('memory', ['free', '-m']);
command('cpu_io', ['vmstat', '1', '3']);
command('clock_sync', ['timedatectl', 'show', '-p', 'NTPSynchronized', '-p', 'Timezone']);
command('php_processes', ['ps', '-C', 'ea-php82,php', '-o', 'pid,ppid,etime,pcpu,pmem,stat,comm']);
command('schedule', [PHP_BINARY, 'artisan', 'schedule:list', '--no-ansi']);
report('effective_config', [
    'debug' => config('app.debug'), 'queue' => config('queue.default'),
    'retry_after' => config('queue.connections.redis.retry_after'),
    'runtime' => config('autofinx.runtime'), 'scheduler' => config('autofinx.scheduler'),
]);
foreach (['app/Support/WebSocket/SimpleWebSocketClient.php', 'app/Console/Commands/MarketWsConsumerCommand.php', 'app/Domain/Market/RealtimeMarketFeedService.php'] as $file) {
    report('source_hash', ['file' => $file, 'sha256' => hash_file('sha256', $file)]);
}
attempt('database_latency', function () {
    $t = microtime(true); \Illuminate\Support\Facades\DB::select('SELECT 1');
    return ['ms' => round((microtime(true) - $t) * 1000, 2)];
});
attempt('redis_latency', function () {
    $times = [];
    for ($i = 0; $i < 10; $i++) {
        $t = microtime(true); \Illuminate\Support\Facades\Redis::ping();
        $times[] = round((microtime(true) - $t) * 1000, 2);
    }
    return ['ping_ms' => $times];
});
// Measure clock offset against request midpoint, not two manually run commands.
attempt('binance_clock', function () {
    $start = microtime(true) * 1000;
    $response = \Illuminate\Support\Facades\Http::connectTimeout(3)->timeout(6)->get('https://fapi.binance.com/fapi/v1/time');
    $end = microtime(true) * 1000;
    return ['http_status' => $response->status(), 'rtt_ms' => round($end - $start),
        'midpoint_offset_ms' => is_numeric($response->json('serverTime')) ? round($response->json('serverTime') - ($start + $end) / 2) : null];
});
$symbols = ['BTCUSDT', 'ETHUSDT', 'SOLUSDT', 'BNBUSDT'];
for ($sample = 0; $sample < 3; $sample++) {
    attempt('heartbeat_'.$sample, function () use ($root) {
        $path = $root.'/storage/framework/autofinx-market-ws-heartbeat-futures.json';
        $data = is_file($path) ? json_decode(file_get_contents($path), true) : null;
        return ['read_at' => gmdate('c'), 'computed_age_s' => isset($data['last_message_epoch']) ? time() - $data['last_message_epoch'] : null, 'heartbeat' => $data];
    });
    foreach ($symbols as $symbol) {
        attempt('redis_'.$sample.'_'.$symbol, function () use ($symbol) {
            $key = 'live-feed:binance_futures:'.$symbol;
            $raw = \Illuminate\Support\Facades\Redis::get($key);
            $data = is_string($raw) ? json_decode($raw, true) : null;
            $now = microtime(true) * 1000;
            $result = ['quote_ttl' => \Illuminate\Support\Facades\Redis::ttl($key),
                'receive_age_ms' => isset($data['received_at_ms']) ? round($now - $data['received_at_ms']) : null,
                'event_age_ms' => isset($data['event_timestamp_ms']) ? round($now - $data['event_timestamp_ms']) : null,
                'ingest_lag_ms' => $data['event_lag_ms'] ?? null, 'stream' => $data['stream'] ?? null];
            foreach (['1m', '5m'] as $tf) {
                $ck = 'live-candles:binance_futures:'.$symbol.':'.$tf;
                $result['candles_'.$tf.'_ttl'] = \Illuminate\Support\Facades\Redis::ttl($ck);
            }
            return $result;
        });
    }
    if ($sample < 2) { sleep(5); }
}
// One short public connection using the application's client, with NO ingest.
// Comparing this to the worker isolates ingestion work from transport parsing.
attempt('isolated_socket', function () use ($app, $symbols) {
    $url = $app->make(\App\Domain\Market\RealtimeMarketFeedService::class)->buildStreamUrl($symbols, 'futures', ['trade', 'kline_1m', 'kline_5m', 'bookTicker']);
    $client = new \App\Support\WebSocket\SimpleWebSocketClient;
    $counts = []; $lags = []; $error = null;
    try {
        $client->connect($url, 5);
        $until = microtime(true) + 12;
        while (microtime(true) < $until) {
            $frame = $client->readFrame(1);
            if ($frame === null) { continue; }
            if ($frame['type'] === 'close') { $error = 'remote_close'; break; }
            if ($frame['type'] !== 'text') { continue; }
            $p = json_decode($frame['payload'], true);
            $d = $p['data'] ?? $p;
            $stream = $p['stream'] ?? 'unknown';
            $counts[$stream] = ($counts[$stream] ?? 0) + 1;
            if (isset($d['E'])) {
                $lag = round(microtime(true) * 1000 - $d['E']);
                $lags[$stream] = ['last_ms' => $lag, 'max_ms' => max($lags[$stream]['max_ms'] ?? $lag, $lag)];
            }
        }
    } catch (Throwable $e) { $error = get_class($e).': '.substr($e->getMessage(), 0, 300); }
    finally { $client->close(); }
    return ['host' => parse_url($url, PHP_URL_HOST), 'frame_counts' => $counts, 'lags' => $lags, 'error' => $error];
});
report('complete', ['utc' => gmdate('c'), 'production_state_modified' => false]);
