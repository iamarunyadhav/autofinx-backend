<?php

namespace App\Http\Controllers\Api;

use App\Domain\Assets\AssetRegistryService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AssetController extends Controller
{
    public function index(Request $request, AssetRegistryService $assets)
    {
        $assetClass = strtoupper((string) $request->query('asset_class', ''));
        if (in_array($assetClass, ['CRYPTO', 'METALS'], true)) {
            return response()->json([
                'asset_class' => $assetClass,
                'symbols' => $assets->listSymbols($assetClass),
                'quote_currencies' => config('assets.quote_currencies', ['USD']),
                'providers' => $this->providersFor($assetClass),
            ]);
        }

        return response()->json([
            ...$assets->all(),
            'providers' => [
                'CRYPTO' => $this->providersFor('CRYPTO'),
                'METALS' => $this->providersFor('METALS'),
            ],
        ]);
    }

    private function providersFor(string $assetClass): array
    {
        return $assetClass === 'METALS'
            ? ['metal_broker']
            : ['binance'];
    }
}
