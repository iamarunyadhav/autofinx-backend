<?php

namespace Tests\Unit\MarketOpportunityBrain;

use App\Domain\MarketOpportunityBrain\Data\ForecastRecord;
use App\Domain\MarketOpportunityBrain\Enums\ForecastStatus;
use Tests\TestCase;

class ForecastRecordContractTest extends TestCase
{
    private function exampleJson(): array
    {
        $path = base_path('docs/market-opportunity-brain-v4/contracts/forecast.v1.example.json');
        $this->assertFileExists($path);

        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @test */
    public function round_trips_the_example_contract_exactly(): void
    {
        $example = $this->exampleJson();

        $record = ForecastRecord::fromArray($example);

        $this->assertEquals($example, $record->toArray());
        $this->assertSame(ForecastStatus::UNAVAILABLE, $record->status);
    }

    /** @test */
    public function rejects_out_of_order_quantiles(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ForecastRecord(
            forecastId: 'f1', workspaceId: 'w1', exchange: 'BINANCE', market: 'USDM_PERPETUAL',
            symbol: 'FAKEUSDT', timeframe: '15m', horizonBars: 4, asOfUtc: '2026-10-14T00:00:00Z',
            knownByUtc: '2026-10-14T00:00:00Z', labelAvailableAtUtc: null, modelName: 'naive_last',
            modelRevision: 'v1', featureSchemaVersion: 'v1', datasetHashSha256: null,
            predictionQuantiles: ['p10' => 100.0, 'p50' => 90.0, 'p90' => 110.0], // p10 > p50 -- invalid
            probabilities: ['up' => null, 'neutral' => null, 'down' => null],
            probabilityCalibrated: false, status: ForecastStatus::OK, reason: null,
        );
    }

    /** @test */
    public function rejects_probabilities_summing_above_one(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ForecastRecord(
            forecastId: 'f1', workspaceId: 'w1', exchange: 'BINANCE', market: 'USDM_PERPETUAL',
            symbol: 'FAKEUSDT', timeframe: '15m', horizonBars: 4, asOfUtc: '2026-10-14T00:00:00Z',
            knownByUtc: '2026-10-14T00:00:00Z', labelAvailableAtUtc: null, modelName: 'naive_last',
            modelRevision: 'v1', featureSchemaVersion: 'v1', datasetHashSha256: null,
            predictionQuantiles: ['p10' => 90.0, 'p50' => 100.0, 'p90' => 110.0],
            probabilities: ['up' => 0.7, 'neutral' => 0.2, 'down' => 0.3], // sums to 1.2
            probabilityCalibrated: false, status: ForecastStatus::OK, reason: null,
        );
    }

    /** @test */
    public function execution_authorized_cannot_be_tampered_true_via_from_array(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ForecastRecord::fromArray(['execution_authorized' => true, 'status' => 'OK']);
    }
}
