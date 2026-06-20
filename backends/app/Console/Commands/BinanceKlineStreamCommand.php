<?php

namespace App\Console\Commands;

use App\Domain\Trading\KlineBrainService;
use App\Support\WebSocket\SimpleWebSocketClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Streams Binance public kline (candlestick) WebSocket for one or more symbols
 * and persists closed candles to cache so KlineBrainService can compute signals.
 *
 * No API key required — uses Binance's public stream endpoint.
 *
 * Usage (single symbol):
 *   php artisan trading:binance-kline-stream --symbol=BTCUSDT --timeframe=15m
 *
 * Usage (multi-symbol via combined stream):
 *   php artisan trading:binance-kline-stream --symbols=BTCUSDT,ETHUSDT,SOLUSDT --timeframe=15m
 *
 * Supervisor should run one process per timeframe, passing all symbols.
 */
class BinanceKlineStreamCommand extends Command
{
    protected $signature = 'trading:binance-kline-stream
        {--symbol=BTCUSDT        : Single symbol (ignored when --symbols is set)}
        {--symbols=              : Comma-separated symbols list}
        {--timeframe=15m         : Kline interval (1m|5m|15m|1h|4h)}
        {--max-runtime=0         : Max runtime in seconds (0 = infinite)}
        {--reconnect-sleep=5     : Seconds between reconnect attempts}
        {--backfill-limit=250    : Min candles needed before signals are live}';

    protected $description = 'Stream Binance public kline WS and store closed candles for KlineBrainService';

    private const WS_BASE = 'wss://stream.binance.com:9443';

    public function handle(KlineBrainService $brain): int
    {
        $symbolsOpt   = $this->option('symbols');
        $timeframe    = strtolower(trim((string) $this->option('timeframe')));
        $maxRuntime   = max(0, (int) $this->option('max-runtime'));
        $reconnect    = max(1, (int) $this->option('reconnect-sleep'));
        $startedAt    = time();

        $symbols = $symbolsOpt
            ? array_map('strtoupper', array_filter(explode(',', (string) $symbolsOpt)))
            : [strtoupper(trim((string) $this->option('symbol')))];

        if (empty($symbols)) {
            $this->error('No symbols provided. Use --symbol=BTCUSDT or --symbols=BTCUSDT,ETHUSDT');
            return self::FAILURE;
        }

        $wsUrl = $this->buildUrl($symbols, $timeframe);
        $this->info(sprintf('[kline-stream] Connecting for %s @ %s → %s', implode(',', $symbols), $timeframe, $wsUrl));

        $client = new SimpleWebSocketClient();

        while (true) {
            if ($maxRuntime > 0 && (time() - $startedAt) >= $maxRuntime) {
                $this->info('[kline-stream] Max runtime reached, stopping.');
                break;
            }

            try {
                $client->connect($wsUrl);
                $this->line(sprintf('[kline-stream] Connected — streaming %s @ %s', implode(',', $symbols), $timeframe));

                $msgCount = 0;

                while (true) {
                    if ($maxRuntime > 0 && (time() - $startedAt) >= $maxRuntime) {
                        $client->close();
                        return self::SUCCESS;
                    }

                    $frame = $client->readFrame(1); // 1s timeout
                    if ($frame === null) {
                        continue; // idle poll
                    }

                    if (($frame['type'] ?? '') === 'close') {
                        $this->warn('[kline-stream] Server sent close frame, reconnecting.');
                        break;
                    }

                    if (($frame['type'] ?? '') !== 'text') {
                        continue;
                    }

                    $msgCount++;
                    $raw = $frame['payload'] ?? '';
                    $msg = json_decode($raw, true);
                    if (! is_array($msg)) {
                        continue;
                    }

                    // Combined stream wraps in { stream: '...@kline_15m', data: {...} }
                    $event = isset($msg['data']) ? $msg['data'] : $msg;
                    if (($event['e'] ?? '') !== 'kline') {
                        continue;
                    }

                    $k        = $event['k'];
                    $isClosed = (bool) ($k['x'] ?? false);
                    $sym      = strtoupper((string) ($k['s'] ?? ''));

                    $candle = [
                        'ts'     => (int) ($k['t'] ?? 0),
                        'open'   => (float) ($k['o'] ?? 0),
                        'high'   => (float) ($k['h'] ?? 0),
                        'low'    => (float) ($k['l'] ?? 0),
                        'close'  => (float) ($k['c'] ?? 0),
                        'volume' => (float) ($k['v'] ?? 0),
                    ];

                    $brain->pushCandle($sym, $timeframe, $candle, $isClosed);

                    if ($isClosed && $this->getOutput()->isVerbose()) {
                        $this->line(sprintf(
                            '[kline-stream] %-12s %s c=%.4f v=%.2f [%d msg]',
                            $sym, $timeframe, $candle['close'], $candle['volume'], $msgCount
                        ));
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('[kline-stream] WS error', ['error' => $e->getMessage(), 'symbols' => $symbols, 'tf' => $timeframe]);
                $this->warn(sprintf('[kline-stream] %s — reconnecting in %ds', $e->getMessage(), $reconnect));
                try { $client->close(); } catch (\Throwable) {}
                sleep($reconnect);
            }
        }

        return self::SUCCESS;
    }

    private function buildUrl(array $symbols, string $timeframe): string
    {
        if (count($symbols) === 1) {
            $sym = strtolower($symbols[0]);
            return self::WS_BASE . "/ws/{$sym}@kline_{$timeframe}";
        }

        // Combined stream for multiple symbols
        $streams = implode('/', array_map(
            fn ($s) => strtolower($s) . "@kline_{$timeframe}",
            $symbols
        ));

        return self::WS_BASE . "/stream?streams={$streams}";
    }
}
