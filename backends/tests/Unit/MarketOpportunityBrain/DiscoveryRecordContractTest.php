<?php

namespace Tests\Unit\MarketOpportunityBrain;

use App\Domain\MarketOpportunityBrain\Data\DiscoveryRecord;
use Tests\TestCase;

/**
 * Round-trip parity against the actual example contract file (MASTER_SPEC.md Section M test #23:
 * "schema contract snapshots PHP/Python/TS"). This test fails the moment DiscoveryRecord drifts
 * from docs/market-opportunity-brain-v4/contracts/discovery.v1.example.json.
 */
class DiscoveryRecordContractTest extends TestCase
{
    private function exampleJson(): array
    {
        $path = base_path('docs/market-opportunity-brain-v4/contracts/discovery.v1.example.json');
        $this->assertFileExists($path);

        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @test */
    public function round_trips_the_example_contract_exactly(): void
    {
        $example = $this->exampleJson();

        $record = DiscoveryRecord::fromArray($example);

        $this->assertEquals($example, $record->toArray());
        $this->assertSame(DiscoveryRecord::SCHEMA, $record->toArray()['schema']);
        $this->assertSame('mob.discovery.v1', $example['schema']);
    }

    /** @test */
    public function execution_authorized_is_always_false_even_if_tampered_input_tries_to_flip_it(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DiscoveryRecord::fromArray(['execution_authorized' => true, 'symbol' => 'FAKEUSDT']);
    }

    /** @test */
    public function to_array_always_emits_execution_authorized_false_regardless_of_constructor_bypass(): void
    {
        $record = new DiscoveryRecord(
            snapshotId: 's1', exchange: 'BINANCE', market: 'USDM_PERPETUAL', symbol: 'FAKEUSDT',
            workspaceId: 'w1', asOfUtc: '2026-10-14T00:00:00Z', lastClosedCandleUtc: null,
            listedAsOf: true, eligibleForAnalysis: false, rank: null, opportunityScore: null,
            direction: null, sourceFreshnessSeconds: null, reasonCodes: [], featureSchemaVersion: 'v1',
            featureWindowHashSha256: null,
        );

        $this->assertFalse($record->toArray()['execution_authorized']);
    }
}
