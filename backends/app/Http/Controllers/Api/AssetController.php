<?php

namespace App\Http\Controllers\Api;

use App\Domain\Assets\AssetRegistryService;
use App\Domain\Trading\Providers\LiveTradeProviderRegistry;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AssetController extends Controller
{
    public function index(Request $request, AssetRegistryService $assets, LiveTradeProviderRegistry $providers)
    {
        $assetClass = strtoupper((string) $request->query('asset_class', ''));
        if (in_array($assetClass, ['CRYPTO', 'METALS', 'FOREX'], true)) {
            return response()->json([
                'asset_class' => $assetClass,
                'symbols' => $assets->listSymbols($assetClass),
                'quote_currencies' => config('assets.quote_currencies', ['USD']),
                'providers' => $this->providersFor($assetClass, $providers),
            ]);
        }

        return response()->json([
            ...$assets->all(),
            'providers' => [
                'CRYPTO' => $this->providersFor('CRYPTO', $providers),
                'METALS' => $this->providersFor('METALS', $providers),
                'FOREX' => $this->providersFor('FOREX', $providers),
            ],
        ]);
    }

    /**
     * Derives providers for an asset class from the LiveTradeProviderRegistry
     * catalog (config/live_providers.php) — the single source of truth, so
     * this never drifts into its own separate hardcoded provider list.
     */
    private function providersFor(string $assetClass, LiveTradeProviderRegistry $providers): array
    {
        return array_values(array_filter(
            $providers->catalog(),
            fn (array $entry) => strtoupper((string) ($entry['asset_class'] ?? '')) === $assetClass
        ));
    }
}
