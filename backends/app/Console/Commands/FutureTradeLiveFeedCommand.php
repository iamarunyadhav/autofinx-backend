<?php

namespace App\Console\Commands;

use App\Models\FutureTradeBotConfig;
use Illuminate\Console\Command;

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
        $symbols = $this->resolveSymbols();

        if ($symbols === []) {
            $symbols = ['BTCUSDT', 'ETHUSDT'];
        }

        return $this->call('market:ws-consume', [
            '--market-type' => 'futures',
            '--symbols' => implode(',', $symbols),
            '--streams' => (string) $this->option('streams'),
            '--reconnect-sleep' => (string) $this->option('reconnect-sleep'),
            '--max-runtime' => (string) $this->option('max-runtime'),
            '--testnet' => (bool) $this->option('testnet'),
            '--no-dispatch' => true,
        ]);
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
