<?php

namespace App\Http\Controllers\Api;

use App\Domain\Market\FxRateService;
use App\Domain\Market\MetalsMarketDataService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class MetalsController extends Controller
{
    public function rates(Request $request, MetalsMarketDataService $metals)
    {
        $provider = strtolower((string) $request->query('provider', config('assets.metals.default_provider', 'metal_broker')));

        return response()->json([
            'provider' => $provider,
            'rates' => $metals->ratesSummary($provider),
        ]);
    }

    public function convert(Request $request, FxRateService $fx)
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0',
            'base' => 'required|string|max:8',
            'quote' => 'required|string|max:8',
        ]);

        $amount = (float) $data['amount'];
        $base = strtoupper((string) $data['base']);
        $quote = strtoupper((string) $data['quote']);
        $rate = $fx->getRate($base, $quote);

        return response()->json([
            'amount' => $amount,
            'base' => $base,
            'quote' => $quote,
            'rate' => $rate,
            'converted' => $amount * $rate,
            'ts' => now()->toIso8601String(),
        ]);
    }
}
