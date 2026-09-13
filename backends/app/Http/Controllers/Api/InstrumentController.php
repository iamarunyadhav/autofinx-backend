<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Instrument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only instrument catalogue listing. Backs the Pepperstone Metals &
 * Energy Trading module's instrument picker (PepperstoneBotConfig.instrument_id
 * references this table -- see PepperstoneInstrumentSeeder for the seeded
 * Gold/Silver/Platinum/Crude Oil rows). Not scoped to a workspace: the
 * instrument catalogue is global, like Symbol is for the crypto side.
 */
class InstrumentController extends Controller
{
    /**
     * GET /instruments?provider=pepperstone&market_type=metal
     */
    public function index(Request $request): JsonResponse
    {
        $query = Instrument::query()->where('enabled', true);

        if ($request->filled('market_type')) {
            $query->where('market_type', (string) $request->query('market_type'));
        }

        if ($request->filled('provider')) {
            $provider = (string) $request->query('provider');
            $query->whereHas('mappings', function ($mappingQuery) use ($provider) {
                $mappingQuery->where('provider', $provider);
            });
        }

        return response()->json($query->orderBy('name')->get());
    }
}
