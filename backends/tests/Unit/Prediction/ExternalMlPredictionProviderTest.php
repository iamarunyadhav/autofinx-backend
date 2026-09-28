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
        Http::fake(['127.0.0.1:8091/*' => Http::response(['prediction' => ['status' => 'ready', 'model_version' => 'btc-v1', 'direction' => 'BUY', 'predicted_return_pct' => 0.4, 'confidence' => 67]], 200)]);
        config(['prediction_observatory.providers.external_ml.url' => 'http://127.0.0.1:8091']);
        $candles = array_map(static fn (int $i): array => ['timestamp' => $i, 'close' => 100 + $i, 'volume' => 10], range(1, 8));
        $result = (new ExternalMlPredictionProvider)->predict(new PredictionRequest('BTCUSDT', 'crypto', '15m', 900, 108, $candles));

        self::assertTrue($result->available);
        self::assertSame('BUY', $result->direction);
        self::assertSame(67.0, $result->confidence);
    }
}
