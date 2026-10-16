<?php

namespace Tests\Unit\MarketOpportunityBrain\Adapters;

use App\Domain\MarketOpportunityBrain\Adapters\BinancePublicDataGate;
use App\Domain\MarketOpportunityBrain\Adapters\MarketDataDisabledException;
use Tests\TestCase;

class BinancePublicDataGateTest extends TestCase
{
    /** @test */
    public function disabled_by_default_configuration_refuses_every_call(): void
    {
        $this->assertFalse(config('market_opportunity_brain.public_data_enabled'));
        $this->assertFalse(BinancePublicDataGate::isEnabled());
        $this->expectException(MarketDataDisabledException::class);
        BinancePublicDataGate::assertEnabled();
    }

    /** @test */
    public function still_refuses_if_only_public_data_enabled_is_flipped(): void
    {
        config(['market_opportunity_brain.public_data_enabled' => true]);
        $this->expectException(MarketDataDisabledException::class);
        BinancePublicDataGate::assertEnabled();
    }

    /** @test */
    public function allows_only_when_every_budget_is_positive(): void
    {
        config([
            'market_opportunity_brain.public_data_enabled' => true,
            'market_opportunity_brain.max_api_weight_per_minute' => 100,
            'market_opportunity_brain.max_discovery_symbols' => 10,
        ]);
        BinancePublicDataGate::assertEnabled(); // must not throw
        $this->assertTrue(BinancePublicDataGate::isEnabled());
    }
}
