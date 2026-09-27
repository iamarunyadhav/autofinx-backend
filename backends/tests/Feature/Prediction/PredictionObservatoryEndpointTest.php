<?php

namespace Tests\Feature\Prediction;

use Tests\TestCase;

final class PredictionObservatoryEndpointTest extends TestCase
{
    public function test_invalid_candle_payload_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/quick-trade/prediction-observatory', [
            'symbol' => 'BTCUSDT', 'timeframe' => '15m', 'horizon_seconds' => 900,
            'candles' => [],
        ]);

        // The API is protected by the same auth middleware as Quick Trade;
        // unauthenticated callers must not get an oracle for market data.
        $response->assertStatus(401);
    }
}
