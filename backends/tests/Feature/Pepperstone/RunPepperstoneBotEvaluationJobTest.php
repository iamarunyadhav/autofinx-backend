<?php

namespace Tests\Feature\Pepperstone;

use App\Domain\Pepperstone\BasketExitEngine;
use App\Domain\Pepperstone\MarketRegimeDetector;
use App\Domain\Pepperstone\MTFConfirmationEngine;
use App\Domain\Pepperstone\PortfolioRiskEngine;
use App\Domain\Pepperstone\PositionSizingService;
use App\Domain\Pepperstone\RiskEngine;
use App\Domain\Pepperstone\TradingCostCalculator;
use App\Domain\Pepperstone\V3CompositeScorer;
use App\Domain\Venue\TradingVenueClientFactory;
use App\Jobs\RunPepperstoneBotEvaluationJob;
use App\Models\Instrument;
use App\Models\InstrumentMapping;
use App\Models\MarketOhlc;
use App\Models\PepperstoneBasket;
use App\Models\PepperstoneBasketSlot;
use App\Models\PepperstoneBotConfig;
use App\Models\PepperstoneBotEvent;
use App\Models\PepperstoneBotState;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * End-to-end (sandbox venue, real DB) coverage of the REWRITTEN,
 * basket-aware evaluation job -- supersedes the old single-ladder/
 * PepperstonePosition-based test of the same name (see
 * RunPepperstoneBotEvaluationJob's own docblock for the redesign).
 */
class RunPepperstoneBotEvaluationJobTest extends TestCase
{
    use RefreshDatabase;

    private const TIMEFRAME_MINUTES = ['5m' => 5, '15m' => 15, '30m' => 30, '1h' => 60, '4h' => 240, '1d' => 1440];

    private function makeConfig(array $overrides = []): PepperstoneBotConfig
    {
        $workspace = Workspace::factory()->create();

        $instrument = Instrument::create([
            'canonical_symbol' => 'XAUUSD',
            'market_type' => 'metal',
            'name' => 'Gold',
            'quote_currency' => 'USD',
            'enabled' => true,
        ]);

        InstrumentMapping::create([
            'instrument_id' => $instrument->id,
            'provider' => 'pepperstone',
            'broker_symbol' => 'XAUUSD',
            'tick_size' => 0.01,
            'tick_value' => 1.0,
            'contract_size' => 100,
            'min_volume' => 0.01,
            'volume_step' => 0.01,
            'margin_requirement' => 0.001,
        ]);

        $config = PepperstoneBotConfig::create(array_merge([
            'workspace_id' => $workspace->id,
            'instrument_id' => $instrument->id,
            'lot_per_level' => 0.10,
            'max_basket_levels' => 8,
            'max_total_exposure_lots' => 10,
            'max_position_lots' => 10,
            'basket_stop_loss_pct' => 50,
            'basket_take_profit_pct' => 50,
            'v3_confidence_threshold' => 0.0,
            'min_mtf_confidence' => 0.0,
            'enabled' => true,
        ], $overrides));

        PepperstoneBotState::create([
            'pepperstone_bot_config_id' => $config->id,
            'status' => PepperstoneBotState::STATUS_IDLE,
        ]);

        return $config;
    }

    /**
     * Seeds a strong, deterministic uptrend across every MTF timeframe
     * (and V3's default '1h') so both V3CompositeScorer and
     * MTFConfirmationEngine agree on BUY with real confidence -- without
     * this, both report NEUTRAL/insufficient-data and the job will never
     * open a basket at all (by design).
     */
    private function seedBullishHistory(string $symbol): void
    {
        foreach (self::TIMEFRAME_MINUTES as $timeframe => $minutes) {
            $price = 2000.0;
            for ($i = 0; $i < 260; $i++) {
                $price += 1.0;
                MarketOhlc::create([
                    'exchange' => 'pepperstone',
                    'symbol' => $symbol,
                    'timeframe' => $timeframe,
                    'open' => $price - 0.2,
                    'high' => $price + 0.5,
                    'low' => $price - 0.5,
                    'close' => $price,
                    'volume' => 0,
                    'ts' => Carbon::now()->subMinutes($minutes * (260 - $i + 1)),
                ]);
            }
        }
    }

    private function runJob(PepperstoneBotConfig $config): void
    {
        (new RunPepperstoneBotEvaluationJob((string) $config->id, true))->handle(
            app(TradingVenueClientFactory::class),
            app(PositionSizingService::class),
            app(RiskEngine::class),
            app(MTFConfirmationEngine::class),
            app(MarketRegimeDetector::class),
            app(V3CompositeScorer::class),
            app(PortfolioRiskEngine::class),
            app(BasketExitEngine::class),
            app(TradingCostCalculator::class),
        );
    }

    /** @test */
    public function first_tick_opens_an_active_basket_when_v3_and_mtf_agree(): void
    {
        $config = $this->makeConfig();
        $this->seedBullishHistory('XAUUSD');

        $this->runJob($config);

        $this->assertDatabaseCount('pepperstone_baskets', 1);
        $basket = PepperstoneBasket::first();
        $this->assertSame(PepperstoneBasket::STATUS_ACTIVE, $basket->status);
        $this->assertSame(PepperstoneBasket::DIRECTION_BUY, $basket->direction);
        $this->assertSame(1, $basket->opened_levels);

        $this->assertDatabaseCount('pepperstone_basket_slots', 1);
        $slot = PepperstoneBasketSlot::first();
        $this->assertSame(0, $slot->level_index);
        $this->assertSame(PepperstoneBasketSlot::STATUS_FILLED, $slot->status);

        $this->assertDatabaseHas('pepperstone_bot_events', [
            'pepperstone_bot_config_id' => $config->id,
            'event_type' => PepperstoneBotEvent::EVENT_STARTED,
        ]);

        $state = $config->state()->first()->fresh();
        $this->assertSame(PepperstoneBotState::STATUS_RUNNING, $state->status);
    }

    /** @test */
    public function does_not_open_a_basket_when_v3_and_mtf_disagree_or_have_no_data(): void
    {
        $config = $this->makeConfig();
        // No MarketOhlc seeded at all -> both V3 and MTF report NEUTRAL.

        $this->runJob($config);

        $this->assertDatabaseCount('pepperstone_baskets', 0);
        $this->assertDatabaseHas('pepperstone_bot_events', [
            'pepperstone_bot_config_id' => $config->id,
            'event_type' => PepperstoneBotEvent::EVENT_BLOCKED,
        ]);
    }

    /** @test */
    public function max_basket_levels_blocks_a_further_slot_before_placing_an_order(): void
    {
        $config = $this->makeConfig(['max_basket_levels' => 1]);

        $basket = PepperstoneBasket::create([
            'pepperstone_bot_config_id' => $config->id,
            'direction' => PepperstoneBasket::DIRECTION_BUY,
            'status' => PepperstoneBasket::STATUS_ACTIVE,
            'max_levels' => 1,
            'opened_levels' => 1,
            'lot_per_level' => 0.10,
            'average_entry' => 2150.0,
        ]);
        PepperstoneBasketSlot::create([
            'pepperstone_basket_id' => $basket->id,
            'level_index' => 0,
            'entry_price' => 2150.0,
            'lot_size' => 0.10,
            'status' => PepperstoneBasketSlot::STATUS_FILLED,
            'opened_at' => now(),
        ]);
        $config->state()->first()->update(['status' => PepperstoneBotState::STATUS_RUNNING]);

        $this->runJob($config);

        // Still only the one seeded slot -- the cap blocked a second
        // before it ever reached order placement.
        $this->assertDatabaseCount('pepperstone_basket_slots', 1);
        $this->assertSame(PepperstoneBasket::STATUS_ACTIVE, $basket->fresh()->status);
    }

    /** @test */
    public function adds_a_second_slot_when_price_moves_adversely_and_v3_mtf_still_agree(): void
    {
        $config = $this->makeConfig(['max_basket_levels' => 8]);
        $this->seedBullishHistory('XAUUSD');

        // Entry far above any sandbox mid for XAUUSD (~2150 +/- jitter)
        // guarantees the ATR-based adverse-spacing trigger fires.
        $basket = PepperstoneBasket::create([
            'pepperstone_bot_config_id' => $config->id,
            'direction' => PepperstoneBasket::DIRECTION_BUY,
            'status' => PepperstoneBasket::STATUS_ACTIVE,
            'max_levels' => 8,
            'opened_levels' => 1,
            'lot_per_level' => 0.10,
            'average_entry' => 3000.0,
            // No stop-loss/take-profit configured on the basket itself so
            // BasketExitEngine's floating-pnl checks never fire here.
        ]);
        PepperstoneBasketSlot::create([
            'pepperstone_basket_id' => $basket->id,
            'level_index' => 0,
            'entry_price' => 3000.0,
            'lot_size' => 0.10,
            'status' => PepperstoneBasketSlot::STATUS_FILLED,
            'opened_at' => now(),
        ]);
        $config->state()->first()->update(['status' => PepperstoneBotState::STATUS_RUNNING]);

        $this->runJob($config);

        $this->assertDatabaseCount('pepperstone_basket_slots', 2);
        $basket = $basket->fresh();
        $this->assertSame(2, $basket->opened_levels);
        $this->assertDatabaseHas('pepperstone_bot_events', [
            'pepperstone_bot_config_id' => $config->id,
            'event_type' => PepperstoneBotEvent::EVENT_LEVEL_OPENED,
        ]);
    }

    /** @test */
    public function hard_stop_loss_closes_every_slot_and_stops_with_no_auto_restart(): void
    {
        $config = $this->makeConfig();

        $basket = PepperstoneBasket::create([
            'pepperstone_bot_config_id' => $config->id,
            'direction' => PepperstoneBasket::DIRECTION_BUY,
            'status' => PepperstoneBasket::STATUS_ACTIVE,
            'max_levels' => 8,
            'opened_levels' => 1,
            'lot_per_level' => 0.10,
            'average_entry' => 5000.0,
            'maximum_allowed_loss' => 1.0, // trivially small -> always breached by a deep floating loss
        ]);
        PepperstoneBasketSlot::create([
            'pepperstone_basket_id' => $basket->id,
            'level_index' => 0,
            'entry_price' => 5000.0, // far above sandbox XAUUSD mid -> deep floating loss
            'lot_size' => 0.10,
            'status' => PepperstoneBasketSlot::STATUS_FILLED,
            'opened_at' => now(),
        ]);
        $config->state()->first()->update(['status' => PepperstoneBotState::STATUS_RUNNING]);

        $this->runJob($config);

        $slot = PepperstoneBasketSlot::first();
        $this->assertSame(PepperstoneBasketSlot::STATUS_CLOSED, $slot->status);
        $this->assertLessThan(0, (float) $slot->realized_pnl);

        $basket = $basket->fresh();
        $this->assertSame(PepperstoneBasket::STATUS_CLOSED, $basket->status);
        $this->assertSame(BasketExitEngine::REASON_HARD_STOP_LOSS, $basket->closed_reason);

        $state = $config->state()->first()->fresh();
        $this->assertSame(PepperstoneBotState::STATUS_STOPPED, $state->status);

        $this->assertDatabaseHas('pepperstone_bot_events', [
            'pepperstone_bot_config_id' => $config->id,
            'event_type' => PepperstoneBotEvent::EVENT_BASKET_CLOSED,
        ]);
    }

    /** @test */
    public function take_profit_closes_every_slot(): void
    {
        $config = $this->makeConfig();

        $basket = PepperstoneBasket::create([
            'pepperstone_bot_config_id' => $config->id,
            'direction' => PepperstoneBasket::DIRECTION_BUY,
            'status' => PepperstoneBasket::STATUS_ACTIVE,
            'max_levels' => 8,
            'opened_levels' => 1,
            'lot_per_level' => 0.10,
            'average_entry' => 100.0,
            'take_profit_target' => 1.0, // trivially small -> always cleared by a deep floating gain
        ]);
        PepperstoneBasketSlot::create([
            'pepperstone_basket_id' => $basket->id,
            'level_index' => 0,
            'entry_price' => 100.0, // far below sandbox XAUUSD mid -> deep floating gain
            'lot_size' => 0.10,
            'status' => PepperstoneBasketSlot::STATUS_FILLED,
            'opened_at' => now(),
        ]);
        $config->state()->first()->update(['status' => PepperstoneBotState::STATUS_RUNNING]);

        $this->runJob($config);

        $slot = PepperstoneBasketSlot::first();
        $this->assertSame(PepperstoneBasketSlot::STATUS_CLOSED, $slot->status);
        $this->assertGreaterThan(0, (float) $slot->realized_pnl);

        $basket = $basket->fresh();
        $this->assertSame(PepperstoneBasket::STATUS_CLOSED, $basket->status);
        $this->assertSame(BasketExitEngine::REASON_TAKE_PROFIT, $basket->closed_reason);
    }
}
