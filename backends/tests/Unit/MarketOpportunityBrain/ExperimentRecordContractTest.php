<?php

namespace Tests\Unit\MarketOpportunityBrain;

use App\Domain\MarketOpportunityBrain\Data\ExperimentRecord;
use Tests\TestCase;

class ExperimentRecordContractTest extends TestCase
{
    private function exampleJson(): array
    {
        $path = base_path('docs/market-opportunity-brain-v4/contracts/experiment.v1.example.json');
        $this->assertFileExists($path);

        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @test */
    public function round_trips_the_example_contract_exactly(): void
    {
        $example = $this->exampleJson();

        $record = ExperimentRecord::fromArray($example);

        $this->assertEquals($example, $record->toArray());
        $this->assertSame('SEALED_NO_ACCESS', $record->holdoutPolicy);
    }

    /** @test */
    public function rejects_a_near_miss_sealed_holdout_string(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ExperimentRecord::fromArray(array_merge($this->exampleJson(), ['holdout_policy' => 'sealed-ish']));
    }
}
