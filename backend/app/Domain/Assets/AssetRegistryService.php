<?php

namespace App\Domain\Assets;

class AssetRegistryService
{
    public function all(): array
    {
        return [
            'classes' => config('assets.classes', []),
            'quote_currencies' => config('assets.quote_currencies', []),
            'crypto' => config('assets.crypto', []),
            'metals' => config('assets.metals', []),
        ];
    }

    public function listSymbols(string $assetClass): array
    {
        $assetClass = strtoupper($assetClass);
        if ($assetClass === 'METALS') {
            return config('assets.metals.symbols', []);
        }

        return config('assets.crypto.symbols', []);
    }

    public function find(string $assetClass, string $key): ?array
    {
        $symbols = $this->listSymbols($assetClass);
        $key = strtoupper($key);

        if (isset($symbols[$key])) {
            return $symbols[$key];
        }

        foreach ($symbols as $entry) {
            if (strtoupper((string) ($entry['asset_code'] ?? '')) === $key) {
                return $entry;
            }
        }

        return null;
    }
}

