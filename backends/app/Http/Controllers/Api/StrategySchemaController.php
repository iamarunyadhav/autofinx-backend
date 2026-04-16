<?php

namespace App\Http\Controllers\Api;

use App\Domain\Strategies\StrategyParamSchemaService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class StrategySchemaController extends Controller
{
    public function index(Request $request, StrategyParamSchemaService $schemas)
    {
        $data = $request->validate([
            'strategy_type' => 'nullable|string|max:64',
            'asset_class' => 'nullable|string|in:CRYPTO,METALS,crypto,metals',
        ]);

        $strategyType = strtolower((string) ($data['strategy_type'] ?? ''));
        $assetClass = isset($data['asset_class']) ? strtoupper((string) $data['asset_class']) : null;

        if ($strategyType !== '') {
            if (! $schemas->isSupported($strategyType)) {
                return response()->json(['message' => "Unsupported strategy_type [{$strategyType}]"], 422);
            }

            $schema = $schemas->schema($strategyType);
            $defaults = $schemas->normalize($strategyType, [], $assetClass);

            return response()->json([
                'strategy_type' => $strategyType,
                'asset_class' => $assetClass,
                'schema' => $schema,
                'defaults' => $defaults,
            ]);
        }

        return response()->json([
            'strategy_types' => $schemas->allowedTypes(),
            'catalog' => $schemas->catalog(),
        ]);
    }
}
