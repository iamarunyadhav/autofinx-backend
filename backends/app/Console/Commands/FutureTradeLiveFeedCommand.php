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

        $lock = Cache::lock(
            'autofinx:runtime:future-trade-live-feed:'.md5(implode(',', $symbols)).':'.md5($streams),
            $maxRuntime > 0 ? $maxRuntime + 60 : 86400
        );
        if (! $lock->get()) {
            $this->warn('Another Future Trade live-feed daemon is already running for this symbol/stream scope.');
            Log::warning('[FT LIVE FEED LOCKED]', [
                'symbols' => $symbols,
                'streams' => $streams,
            ]);

            return self::SUCCESS;
        }

        try {
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
}
