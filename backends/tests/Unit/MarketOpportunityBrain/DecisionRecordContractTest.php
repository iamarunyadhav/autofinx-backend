<?php

namespace Tests\Unit\MarketOpportunityBrain;

use App\Domain\MarketOpportunityBrain\Data\DecisionRecord;
use App\Domain\MarketOpportunityBrain\Enums\ResearchDecision;
use Tests\TestCase;

/**
 * Covers PHASES_AND_TEST_GATES.md invariants:
 *  - test_opportunity_brain_cannot_submit_order_or_modify_execution_config (forbidden fields)
 *  - test_live_pnl_never_inferred_from_hypothetical_backtest (mutual exclusion)
 */
class DecisionRecordContractTest extends TestCase
{
    private function exampleJson(): array
    {
        $path = base_path('docs/market-opportunity-brain-v4/contracts/decision.v1.example.json');
        $this->assertFileExists($path);

        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @test */
    public function round_trips_the_example_contract_exactly(): void
    {
        $example = $this->exampleJson();

        $record = DecisionRecord::fromArray($example);

        $this->assertEquals($example, $record->toArray());
        $this->assertSame(ResearchDecision::WAIT, $record->decision);
        $this->assertSame('RESEARCH_ONLY', $record->toArray()['mode']);
        $this->assertFalse($record->toArray()['execution_authorized']);
    }

    /** @test */
    public function rejects_forbidden_order_fields(): void
    {
        foreach (['quantity', 'leverage', 'api_key', 'order_type'] as $field) {
            try {
                DecisionRecord::fromArray(array_merge($this->exampleJson(), [$field => 'x']));
                $this->fail("Expected DecisionRecord::fromArray to reject forbidden field: {$field}");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($field, $e->getMessage());
            }
        }
    }

    /** @test */
    public function cannot_tamper_execution_authorized_or_mode(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DecisionRecord::fromArray(array_merge($this->exampleJson(), ['execution_authorized' => true]));
    }

    /** @test */
    public function mode_must_stay_research_only(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DecisionRecord::fromArray(array_merge($this->exampleJson(), ['mode' => 'LIVE']));
    }

    /** @test */
    public function cannot_carry_both_realized_and_hypothetical_pnl_at_once(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $data = $this->exampleJson();
        $data['realized_live_pnl'] = 12.5;
        $data['hypothetical_net_return'] = 3.1;
        DecisionRecord::fromArray($data);
    }

    /** @test */
    public function decision_enum_rejects_a_raw_buy_sell_value(): void
    {
        $this->expectException(\ValueError::class);

        DecisionRecord::fromArray(array_merge($this->exampleJson(), ['decision' => 'BUY']));
    }
}
