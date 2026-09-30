<?php

namespace Tests\Unit\Prediction;

use App\Domain\Prediction\Data\PredictionRequest;
use App\Domain\Prediction\Providers\ExternalMlPredictionProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ExternalMlPredictionProviderTest extends TestCase
{
    public function test_service_response_is_normalized_without_enabling_execution(): void
    {
        Http::fake(['127.0.0.1:8091/*' => Http::response(['prediction' => ['status' => 'ready', 'model_version' => 'btc-v1', 'direction' => 'WAIT', 'reason' => 'predicted_edge_below_estimated_costs', 'predicted_return_pct' => 0.4, 'confidence' => 67, 'holdout_direction_accuracy_pct' => 58.5, 'holdout_rows' => 240, 'model_evaluation' => ['walk_forward' => ['fold_count' => 3, 'trade_fold_count' => 2], 'cost_assumptions' => ['estimated_round_trip_cost_pct' => 0.16], 'expected_net_return_pct' => 0.24]]], 200)]);
        config(['prediction_observatory.providers.external_ml.url' => 'http://127.0.0.1:8091']);
        $candles = array_map(static fn (int $i): array => ['timestamp' => $i, 'close' => 100 + $i, 'volume' => 10], range(1, 20));
        $result = (new ExternalMlPredictionProvider)->predict(new PredictionRequest('BTCUSDT', 'crypto', '15m', 900, 108, $candles));

        self::assertTrue($result->available);
        self::assertSame('WAIT', $result->direction);
        self::assertSame(67.0, $result->confidence);
        self::assertSame(58.5, $result->evidence['model_holdout_direction_accuracy_pct']);
        self::assertSame(240, $result->evidence['model_holdout_rows']);
        self::assertSame(3, $result->evidence['model_evaluation']['walk_forward']['fold_count']);
        self::assertSame('predicted_edge_below_estimated_costs', $result->reason);
    }
}
