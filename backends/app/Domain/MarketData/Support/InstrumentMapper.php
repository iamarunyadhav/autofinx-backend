<?php

namespace App\Domain\MarketData\Support;

class InstrumentMapper
{
    public function canonical(string $provider, string $providerSymbol): string
    {
        $provider = strtolower(trim($provider));
        $symbol = strtoupper(trim($providerSymbol));

        foreach ((array) config('market_data.instruments', []) as $canonical => $definition) {
            $mapped = strtoupper((string) data_get($definition, "provider_symbols.{$provider}", ''));
            if ($mapped !== '' && $mapped === $symbol) {
                return strtoupper((string) $canonical);
            }
        }

        return match ($symbol) {
            'GOLD', 'XAUUSD.A' => 'XAUUSD',
            'SILVER', 'XAGUSD.A' => 'XAGUSD',
            default => $symbol,
        };
    }

    public function providerSymbol(string $provider, string $instrument): string
    {
        $provider = strtolower(trim($provider));
        $instrument = strtoupper(trim($instrument));

        return strtoupper((string) data_get(config('market_data.instruments', []), "{$instrument}.provider_symbols.{$provider}", $instrument));
    }

    public function marketType(string $instrument, string $fallback = 'CRYPTO'): string
    {
        return strtoupper((string) data_get(config('market_data.instruments', []), strtoupper(trim($instrument)).'.market_type', $fallback));
    }

    public function splitAssets(string $instrument): array
    {
        $instrument = strtoupper(trim($instrument));
        $definition = (array) data_get(config('market_data.instruments', []), $instrument, []);
        if ($definition !== []) {
            return [$definition['base_asset'] ?? null, $definition['quote_asset'] ?? null];
        }

        foreach (['USDT', 'USDC', 'BUSD', 'USD', 'BTC', 'ETH', 'EUR', 'LKR'] as $quote) {
            if (str_ends_with($instrument, $quote) && strlen($instrument) > strlen($quote)) {
                return [substr($instrument, 0, -strlen($quote)), $quote];
            }
        }

        return [null, null];
    }
}
