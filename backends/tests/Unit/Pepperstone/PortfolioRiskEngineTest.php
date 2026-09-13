<?php

namespace Tests\Unit\Pepperstone;

use App\Domain\Pepperstone\PortfolioRiskEngine;
use App\Models\Instrument;
use App\Models\PepperstoneBasket;
use App\Models\PepperstoneBasketSlot;
use App\Models\PepperstoneBotConfig;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortfolioRiskEngineTest extends TestCase
{
    use RefreshDatabase;

    private function makeConfig(string $workspaceId, string $symbol): PepperstoneBotConfig
    {
        $instrument = Instrument::create([
            'canonical_symbol' => $symbol,
            'market_type' => 'metal',
            'name' => $symbol,
            'quote_currency' => 'USD',
            'enabled' => true,
        ]);

        return PepperstoneBotConfig::create([
            'workspace_id' => $workspaceId,
            'instrument_id' => $instrument->id,
            'lot_per_level' => 0.10,
            'max_total_exposure_lots' => 100,
            'max_position_lots' => 100,
            'basket_stop_loss_pct' => 5,
        ]);
    }

    private function makeBasket(PepperstoneBotConfig $config, string $status, float $lots): PepperstoneBasket
    {
        $basket = PepperstoneBasket::create([
            'pepperstone_bot_config_id' => $config->id,
            'direction' => PepperstoneBasket::DIRECTION_BUY,
            'status' => $status,
            'max_levels' => 8,
            'opened_levels' => 1,
            'lot_per_level' => $lots,
            'average_entry' => 2000.0,
        ]);

        PepperstoneBasketSlot::create([
            'pepperstone_basket_id' => $basket->id,
            'level_index' => 0,
            'entry_price' => 2000.0,
            'lot_size' => $lots,
            'status' => PepperstoneBasketSlot::STATUS_FILLED,
        ]);

        return $basket;
    }

    /** @test */
    public function sums_exposure_across_multiple_instruments_active_baskets_in_the_same_workspace(): void
    {
        $workspace = Workspace::factory()->create();

        $goldConfig = $this->makeConfig((string) $workspace->id, 'XAUUSD');
        $silverConfig = $this->makeConfig((string) $workspace->id, 'XAGUSD');

        $this->makeBasket($goldConfig, PepperstoneBasket::STATUS_ACTIVE, 0.30);
        $this->makeBasket($silverConfig, PepperstoneBasket::STATUS_ACTIVE, 0.20);

        $engine = new PortfolioRiskEngine();

        $this->assertEqualsWithDelta(0.50, $engine->totalExposureLots((string) $workspace->id), 0.0000001);
    }

    /** @test */
    public function excludes_closed_baskets_and_other_workspaces(): void
    {
        $workspaceA = Workspace::factory()->create();
        $workspaceB = Workspace::factory()->create();

        $activeConfig = $this->makeConfig((string) $workspaceA->id, 'XAUUSD');
        $closedConfig = $this->makeConfig((string) $workspaceA->id, 'XAGUSD');
        $otherWorkspaceConfig = $this->makeConfig((string) $workspaceB->id, 'XPTUSD');

        $this->makeBasket($activeConfig, PepperstoneBasket::STATUS_ACTIVE, 0.30);
        $this->makeBasket($closedConfig, PepperstoneBasket::STATUS_CLOSED, 0.40);
        $this->makeBasket($otherWorkspaceConfig, PepperstoneBasket::STATUS_ACTIVE, 0.90);

        $engine = new PortfolioRiskEngine();

        $this->assertEqualsWithDelta(0.30, $engine->totalExposureLots((string) $workspaceA->id), 0.0000001);
    }

    /** @test */
    public function wouldExceedPortfolioCap_returns_false_when_no_cap_is_configured(): void
    {
        $engine = new PortfolioRiskEngine();
        $workspace = Workspace::factory()->create();

        $this->assertFalse($engine->wouldExceedPortfolioCap((string) $workspace->id, 999.0, null));
    }

    /** @test */
    public function wouldExceedPortfolioCap_detects_a_breach(): void
    {
        $workspace = Workspace::factory()->create();
        $config = $this->makeConfig((string) $workspace->id, 'XAUUSD');
        $this->makeBasket($config, PepperstoneBasket::STATUS_ACTIVE, 0.8);

        $engine = new PortfolioRiskEngine();

        $this->assertTrue($engine->wouldExceedPortfolioCap((string) $workspace->id, 0.3, 1.0));
        $this->assertFalse($engine->wouldExceedPortfolioCap((string) $workspace->id, 0.1, 1.0));
    }
}
