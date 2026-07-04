<?php

namespace App\Console\Commands;

use App\Models\FutureTradeBotConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FutureTradeLiveFeedCommand extends Command
{
    protected $signature = 'future-trade:live-feed
        {--symbols= : Comma-separated futures symbols. Defaults to all configured Future Trade symbols}
        {--streams=trade,kline_1m,kline_5m,kline_15m,kline_1h,kline_4h : Binance streams to subscribe to}
        {--reconnect-sleep=2 : Reconnect delay in seconds}
        {--max-runtime=0 : Max runtime in seconds (0 = infinite)}
        {--testnet : Use Binance futures testnet websocket}';

    protected $description = 'Run a dedicated shared websocket market feed for Future Trade futures symbols.';

    public function handle(): int
    {
        $heartbeatPath = storage_path('framework/autofinx-live-feed-heartbeat.json');
        $heartbeatDir = dirname($heartbeatPath);
        if (! is_dir($heartbeatDir) || ! is_writable($heartbeatDir)) {
            $message = "Live-feed heartbeat directory is not writable: {$heartbeatDir}";
            $this->error($message);
            Log::error('[FT LIVE FEED PREFLIGHT FAILED]', [
                'reason' => 'heartbeat_directory_not_writable',
                'path' => $heartbeatDir,
            ]);

            return self::FAILURE;
        }

        $symbols = $this->resolveSymbols();

        if ($symbols === []) {
            $symbols = ['BTCUSDT', 'ETHUSDT'];
        }

        $streams = (string) $this->option('streams');
        $maxRuntime = max(0, (int) $this->option('max-runtime'));
        Log::info('[FT LIVE FEED START]', [
            'symbols' => $symbols,
            'streams' => $streams,
            'maxRuntime' => $maxRuntime,
            'testnet' => (bool) $this->option('testnet'),
            'heartbeatPath' => $heartbeatPath,
        ]);

        $startedAt = time();
        $lockKey = 'autofinx:runtime:future-trade-live-feed:'.md5(implode(',', $symbols)).':'.md5($streams);
        $lock = Cache::lock($lockKey, $maxRuntime > 0 ? $maxRuntime + 60 : 86400);
        if (! $lock->get()) {
            if ($this->heartbeatFresh($heartbeatPath)) {
                $this->warn('Another fresh Future Trade live-feed is active; standing by until its heartbeat goes stale.');
                $this->waitUntilHeartbeatStale($heartbeatPath, $startedAt, $maxRuntime);
                $this->warn('Future Trade live-feed heartbeat is stale; force releasing stale lock and taking over.');
                Cache::lock($lockKey, 1)->forceRelease();
                $lock = Cache::lock($lockKey, $maxRuntime > 0 ? $maxRuntime + 60 : 86400);
            } else {
                $this->warn('Stale Future Trade live-feed lock detected without a fresh heartbeat; force releasing lock and retrying.');
                Cache::lock($lockKey, 1)->forceRelease();
                $lock = Cache::lock($lockKey, $maxRuntime > 0 ? $maxRuntime + 60 : 86400);
            }

            if (! $lock->get()) {
                $this->warn('Another Future Trade live-feed daemon is already running for this symbol/stream scope.');
                Log::warning('[FT LIVE FEED LOCKED]', [
                    'symbols' => $symbols,
                    'streams' => $streams,
                    'heartbeat_fresh' => $this->heartbeatFresh($heartbeatPath),
                ]);

                return self::SUCCESS;
            }
        }

        try {
            if (! $this->heartbeatFresh($heartbeatPath)) {
                @unlink($heartbeatPath);
            }
            $exitCode = $this->call('market:ws-consume', [
                '--market-type' => 'futures',
                '--symbols' => implode(',', $symbols),
                '--streams' => $streams,
                '--reconnect-sleep' => (string) $this->option('reconnect-sleep'),
                '--max-runtime' => (string) $maxRuntime,
                '--testnet' => (bool) $this->option('testnet'),
                '--no-dispatch' => true,
            ]);
            Log::info('[FT LIVE FEED EXIT]', [
                'exitCode' => $exitCode,
                'symbols' => $symbols,
                'streams' => $streams,
                'heartbeatExists' => file_exists($heartbeatPath),
            ]);

            return $exitCode;
        } catch (\Throwable $exception) {
            Log::error('[FT LIVE FEED FAILED]', [
                'class' => $exception::class,
                'message' => $exception->getMessage(),
                'symbols' => $symbols,
                'streams' => $streams,
            ]);
            $this->error('Future Trade live-feed failed: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * @return string[]
     */
    private function resolveSymbols(): array
    {
        $option = trim((string) $this->option('symbols'));
        if ($option !== '') {
            return collect(explode(',', $option))
                ->map(static fn (string $symbol) => strtoupper(trim($symbol)))
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        return FutureTradeBotConfig::query()
            ->with('state')
            ->get()
            ->flatMap(function (FutureTradeBotConfig $config): array {
                $settings = $config->mergedSettings();
                $symbols = array_map(
                    static fn ($symbol) => strtoupper(trim((string) $symbol)),
                    (array) ($settings['symbols'] ?? config('future_trade.defaults.symbols', []))
                );
                $currentSymbol = strtoupper(trim((string) ($config->state?->current_symbol ?? '')));
                if ($currentSymbol !== '') {
                    $symbols[] = $currentSymbol;
                }

                return array_values(array_filter($symbols));
            })
            ->unique()
            ->values()
            ->all();
    }

    private function heartbeatFresh(string $path): bool
    {
        if (! file_exists($path)) {
            return false;
        }

        $raw = @file_get_contents($path);
        $data = $raw !== false ? @json_decode($raw, true) : null;
        $epoch = is_array($data) ? (int) ($data['last_message_epoch'] ?? 0) : 0;

        return $epoch > 0 && (time() - $epoch) <= 30;
    }

    private function waitUntilHeartbeatStale(string $path, int $startedAt, int $maxRuntime): void
    {
        while ($this->heartbeatFresh($path)) {
            if ($maxRuntime > 0 && (time() - $startedAt) >= $maxRuntime) {
                return;
            }

            sleep(5);
        }
    }
}
