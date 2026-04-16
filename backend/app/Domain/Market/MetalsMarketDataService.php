<?php

namespace App\Domain\Market;

use App\Domain\Assets\AssetRegistryService;
use App\Domain\Venue\TradingVenueClientFactory;
use Illuminate\Support\Facades\Redis;

class MetalsMarketDataService
{
    public function __construct(
        private readonly TradingVenueClientFactory $venues,
        private readonly AssetRegistryService $assets,
        private readonly MarketPriceCacheService $cache,
        private readonly FxRateService $fx,
    ) {
    }

    public function getPrice(string $assetCode, string $quote = 'USD', string $provider = 'metal_broker'): array
    {
        $assetCode = strtoupper($assetCode);
        $quote = strtoupper($quote);
        $client = $this->venues->forProvider($provider);
        $usdQuote = $client->getLatestPrice($assetCode);
        $midUsd = (float) ($usdQuote['mid'] ?? 0);
        $bidUsd = (float) ($usdQuote['bid'] ?? $midUsd);
        $askUsd = (float) ($usdQuote['ask'] ?? $midUsd);
        $spreadPct = (float) ($usdQuote['spread_pct'] ?? 0);

        if ($midUsd > 0) {
            $this->cache->setLatestPrice($provider, $assetCode, $midUsd);
        }

        $usdLkr = $this->fx->getRate('USD', 'LKR');
        $midLkr = $midUsd * $usdLkr;
        $bidLkr = $bidUsd * $usdLkr;
        $askLkr = $askUsd * $usdLkr;

        try {
            $base = substr($assetCode, 0, 3);
            Redis::set("price_lkr:{$base}", (string) $midLkr);
            Redis::set('fx:USD:LKR', (string) $usdLkr);
        } catch (\Throwable) {
            // non-fatal
        }

        return [
            'asset_class' => 'METALS',
            'asset_code' => $assetCode,
            'provider' => $provider,
            'usd' => [
                'bid' => $bidUsd,
                'ask' => $askUsd,
                'mid' => $midUsd,
            ],
            'lkr' => [
                'bid' => $bidLkr,
                'ask' => $askLkr,
                'mid' => $midLkr,
            ],
            'spread_pct' => $spreadPct,
            'fx_used_usd_lkr' => $usdLkr,
            'quote_currency' => $quote,
            'ts' => now()->toIso8601String(),
            'raw' => $usdQuote['raw'] ?? null,
        ];
    }

    public function ratesSummary(string $provider = 'metal_broker'): array
    {
        $metals = $this->assets->listSymbols('METALS');
        $rows = [];
        foreach ($metals as $metalType => $meta) {
            $assetCode = (string) ($meta['asset_code'] ?? '');
            if ($assetCode === '') {
                continue;
            }

            $rows[] = [
                'metal_type' => strtoupper((string) $metalType),
                'asset_code' => $assetCode,
                'price' => $this->getPrice($assetCode, 'LKR', $provider),
            ];
        }

        return $rows;
    }
}

