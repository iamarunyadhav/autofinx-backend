<?php

namespace App\Console\Commands;

use App\Domain\MarketData\Enums\StreamType;
use App\Domain\MarketData\MarketFreshnessService;
use App\Domain\MarketData\MarketStateStore;
use Illuminate\Console\Command;

class AutoFinxMarketHealthCommand extends Command
{
    protected $signature = 'autofinx:market-health {--provider=} {--instrument=} {--details}';

    protected $description = 'Show unified AutoFinX market data health by provider and instrument.';

    public function handle(MarketFreshnessService $freshness, MarketStateStore $state): int
    {
        $providerFilter = strtolower(trim((string) $this->option('provider')));
        $instrumentFilter = strtoupper(trim((string) $this->option('instrument')));
        $providers = collect(config('market_data.providers', []))
            ->keys()
            ->when($providerFilter !== '', fn ($items) => $items->filter(fn ($provider) => strtolower((string) $provider) === $providerFilter));
        $instruments = collect(config('market_data.instruments', []))
            ->keys()
            ->when($instrumentFilter !== '', fn ($items) => $items->filter(fn ($instrument) => strtoupper((string) $instrument) === $instrumentFilter));

        $this->line('AutoFinX Unified Market Health');
        $this->newLine();

        foreach ($providers as $provider) {
            $this->line(strtoupper((string) $provider));
            foreach ($instruments as $instrument) {
                $marketType = (string) data_get(config('market_data.instruments', []), "{$instrument}.market_type", 'CRYPTO');
                $providerMarkets = (array) data_get(config('market_data.providers', []), "{$provider}.market_types", []);
                if (! in_array($marketType, $providerMarkets, true)) {
                    continue;
                }

                $health = $freshness->evaluate((string) $provider, (string) $instrument, StreamType::QUOTE);
                $quote = $state->quote((string) $provider, (string) $instrument);

                $this->line(sprintf(
                    '  %s / %s  Quote=%s  Age=%sms  Bid=%s Ask=%s Mid=%s Last=%s',
                    $marketType,
                    $instrument,
                    $health['state'] ?? 'UNKNOWN',
                    $health['data_age_ms'] ?? $health['received_age_ms'] ?? 'n/a',
                    $quote['bid'] ?? 'n/a',
                    $quote['ask'] ?? 'n/a',
                    $quote['mid'] ?? 'n/a',
                    $quote['last'] ?? 'n/a',
                ));

                if ((bool) $this->option('details')) {
                    $this->line('    '.json_encode($health, JSON_UNESCAPED_SLASHES));
                }
            }
            $this->newLine();
        }

        return self::SUCCESS;
    }
}
