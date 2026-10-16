<?php

namespace Tests\Unit\MarketOpportunityBrain;

use Tests\TestCase;

/**
 * PHASES_AND_TEST_GATES.md invariant: test_all_new_feature_flags_default_disabled.
 */
class ConfigDisabledByDefaultTest extends TestCase
{
    /** @test */
    public function every_flag_defaults_off_or_zero(): void
    {
        $this->assertFalse(config('market_opportunity_brain.enabled'));
        $this->assertFalse(config('market_opportunity_brain.public_data_enabled'));
        $this->assertFalse(config('market_opportunity_brain.scheduler_enabled'));
        $this->assertFalse(config('market_opportunity_brain.forecast_enabled'));
        $this->assertFalse(config('market_opportunity_brain.external_model_download_enabled'));
        $this->assertFalse(config('market_opportunity_brain.backtest_enabled'));
        $this->assertSame(0, config('market_opportunity_brain.max_discovery_symbols'));
        $this->assertSame(0, config('market_opportunity_brain.max_api_weight_per_minute'));
    }

    /** @test */
    public function config_never_reuses_an_existing_module_flag_name(): void
    {
        // CONFIG_PROPOSAL.md:27 -- this module must never be gated by another module's flag.
        $this->assertNotNull(config('market_opportunity_brain'));
        $keys = array_keys(config('market_opportunity_brain'));
        foreach (['pine_webhook_live_enabled', 'prime_mirror_live_enabled', 'ai_mode', 'nexus_autonomy_level'] as $foreignKey) {
            $this->assertNotContains($foreignKey, $keys);
        }
    }
}
