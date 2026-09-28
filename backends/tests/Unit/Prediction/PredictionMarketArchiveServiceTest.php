<?php

namespace Tests\Unit\Prediction;

use App\Domain\Prediction\PredictionMarketArchiveService;
use Tests\TestCase;

class PredictionMarketArchiveServiceTest extends TestCase
{
    public function test_invalid_snapshot_is_rejected_without_a_database_write(): void
    {
        self::assertFalse(app(PredictionMarketArchiveService::class)->capture([
            'symbol' => 'BTCUSDT',
            'exchange' => 'binance',
            'timeframe' => '1m',
        ]));
    }
}
