<?php

namespace Tests\Feature\ForexGrid;

use App\Domain\ForexGrid\GridLotSizer;
use App\Domain\Venue\TradingVenueClientFactory;
use App\Jobs\RunForexGridBotEvaluationJob;
use App\Models\ForexGridBotConfig;
use App\Models\ForexGridBotEvent;
use App\Models\ForexGridBotLevel;
use App\Models\ForexGridBotState;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Runs against the REAL OandaClient in sandbox mode (no mocking of
 * TradingVenueClientInterface) -- matches this codebase's existing
 * preference for real-infrastructure tests over mocks (see
 * RatioBotStaleFeedSymbolIsolationTest).
 *
 * Sandbox prices for EURUSD hover around a fixed base (1.08) with a small
 * +/-0.2% random jitter per call (see OandaClient::sandboxQuote()) -- there
 * is no way to pin an exact mid price without reaching into the client's
 * internals, so every scenario below is deliberately constructed so the
 * OUTCOME is invariant to that jitter: entry prices are placed far enough
 * away from the ~1.08 sandbox base that +/-0.2% noise can never flip which
 * side of a threshold the price falls on (e.g. an anchor at 2.0 vs a
 * sandbox mid of ~1.08 is never within 0.2% of crossing back).
 */
class RunForexGridBotEvaluationJobTest extends TestCase
{
    use RefreshDatabase;

    private function makeConfig(array $overrides = []): ForexGridBotConfig
    {
        return ForexGridBotConfig::create(array_merge([
            'workspace_id' => Workspace::factory()->create()->id,
            'provider' => 'oanda',
            'symbol' => 'EURUSD',
            'enabled' => true,
            'grid_spacing_pips' => 100, // 0.01 price units
            'position_sizing_mode' => 'fixed',
            'base_lot_size' => 0.10,
            'max_grid_levels' => 8,
            'max_total_exposure_lots' => 10,
            'direction_mode' => 'buy_only',
            'basket_stop_loss_pct' => 90,
        ], $overrides));
    }

    private function makeState(ForexGridBotConfig $config, array $overrides = []): ForexGridBotState
    {
        return ForexGridBotState::create(array_merge([
            'forex_grid_bot_config_id' => $config->id,
            'status' => ForexGridBotState::STATUS_RUNNING,
        ], $overrides));
    }

    private function seedLevel(ForexGridBotConfig $config, array $overrides = []): ForexGridBotLevel
    {
        return ForexGridBotLevel::create(array_merge([
            'forex_grid_bot_config_id' => $config->id,
            'level_index' => 0,
            'side' => ForexGridBotLevel::SIDE_BUY,
            'entry_price' => 2.0, // far above the ~1.08 sandbox EURUSD base
            'lot_size' => 0.10,
            'order_id' => 'seed-' . (string) Str::uuid(),
            'status' => ForexGridBotLevel::STATUS_FILLED,
            'opened_at' => now(),
        ], $overrides));
    }

    private function runJob(ForexGridBotConfig $config): void
    {
        (new RunForexGridBotEvaluationJob((string) $config->id, true))->handle(
            app(TradingVenueClientFactory::class),
            app(GridLotSizer::class),
        );
    }

    /** @test */
    public function first_tick_anchors_level_zero_and_transitions_to_running(): void
    {
        $config = $this->makeConfig();
        $this->makeState($config, ['status' => ForexGridBotState::STATUS_IDLE]);

        $this->runJob($config);

        $this->assertDatabaseHas('forex_grid_bot_levels', [
            'forex_grid_bot_config_id' => $config->id,
            'level_index' => 0,
            'side' => 'buy',
            'status' => 'filled',
        ]);

        $state = $config->state()->first();
        $this->assertSame(ForexGridBotState::STATUS_RUNNING, $state->status);
        $this->assertSame(1, $state->active_levels_count);
    }

    /** @test */
    public function opens_a_new_level_when_price_has_moved_beyond_grid_spacing(): void
    {
        $config = $this->makeConfig(['max_grid_levels' => 8, 'max_total_exposure_lots' => 10]);
        $this->makeState($config);
        $this->seedLevel($config, ['level_index' => 0, 'entry_price' => 2.0, 'lot_size' => 0.10]);

        $this->runJob($config);

        $this->assertDatabaseHas('forex_grid_bot_levels', [
            'forex_grid_bot_config_id' => $config->id,
            'level_index' => 1,
            'side' => 'buy',
            'status' => 'filled',
        ]);

        $state = $config->state()->first();
        $this->assertSame(2, $state->active_levels_count);
    }

    /** @test */
    public function max_grid_levels_cap_blocks_the_next_signal(): void
    {
        $config = $this->makeConfig(['max_grid_levels' => 1, 'max_total_exposure_lots' => 100]);
        $this->makeState($config, ['active_levels_count' => 1, 'total_exposure_lots' => 0.10]);
        $this->seedLevel($config, ['level_index' => 0, 'entry_price' => 2.0, 'lot_size' => 0.10]);

        $this->runJob($config);

        $this->assertDatabaseMissing('forex_grid_bot_levels', [
            'forex_grid_bot_config_id' => $config->id,
            'level_index' => 1,
        ]);

        $this->assertDatabaseHas('forex_grid_bot_events', [
            'forex_grid_bot_config_id' => $config->id,
            'event_type' => ForexGridBotEvent::EVENT_BLOCKED,
        ]);

        $event = ForexGridBotEvent::where('forex_grid_bot_config_id', $config->id)
            ->where('event_type', ForexGridBotEvent::EVENT_BLOCKED)
            ->first();
        $this->assertSame('max_grid_levels', $event->context_json['cap']);

        // The cap check never touched state -- still reflects what it was
        // seeded with, proving the block happened before any mutation.
        $state = $config->state()->first();
        $this->assertSame(1, $state->active_levels_count);
    }

    /** @test */
    public function max_total_exposure_lots_cap_blocks_independently_of_level_count(): void
    {
        // max_grid_levels is generously high (20) -- only the exposure cap
        // can be the reason this blocks.
        $config = $this->makeConfig([
            'max_grid_levels' => 20,
            'max_total_exposure_lots' => 0.15,
            'base_lot_size' => 0.10,
        ]);
        $this->makeState($config);
        $this->seedLevel($config, ['level_index' => 0, 'entry_price' => 2.0, 'lot_size' => 0.10]);

        $this->runJob($config);

        $this->assertDatabaseMissing('forex_grid_bot_levels', [
            'forex_grid_bot_config_id' => $config->id,
            'level_index' => 1,
        ]);

        $event = ForexGridBotEvent::where('forex_grid_bot_config_id', $config->id)
            ->where('event_type', ForexGridBotEvent::EVENT_BLOCKED)
            ->first();
        $this->assertNotNull($event);
        $this->assertSame('max_total_exposure_lots', $event->context_json['cap']);
    }

    /** @test */
    public function martingale_max_lot_size_clips_the_next_level(): void
    {
        $config = $this->makeConfig([
            'position_sizing_mode' => 'martingale',
            'base_lot_size' => 0.10,
            'martingale_multiplier' => 5.0,
            'martingale_max_lot_size' => 0.30,
            'max_grid_levels' => 20,
            'max_total_exposure_lots' => 100,
        ]);
        $this->makeState($config);
        $this->seedLevel($config, ['level_index' => 0, 'entry_price' => 2.0, 'lot_size' => 0.10]);

        $this->runJob($config);

        // Raw martingale size at level 1 would be 0.10 * 5 = 0.50, clipped to 0.30.
        $this->assertDatabaseHas('forex_grid_bot_levels', [
            'forex_grid_bot_config_id' => $config->id,
            'level_index' => 1,
            'lot_size' => '0.3000',
        ]);
    }

    /** @test */
    public function max_total_exposure_lots_blocks_under_martingale_even_when_no_single_level_exceeds_the_clip(): void
    {
        $config = $this->makeConfig([
            'position_sizing_mode' => 'martingale',
            'base_lot_size' => 0.10,
            'martingale_multiplier' => 1.5,
            'martingale_max_lot_size' => 1.0, // high -- individual sizes never clip
            'max_grid_levels' => 20,
            'max_total_exposure_lots' => 0.35,
        ]);
        $this->makeState($config);
        // Level 0: 0.10, Level 1: 0.10*1.5 = 0.15 -- both already open, total 0.25.
        $this->seedLevel($config, ['level_index' => 0, 'entry_price' => 2.0, 'lot_size' => 0.10]);
        $this->seedLevel($config, ['level_index' => 1, 'entry_price' => 2.0, 'lot_size' => 0.15]);

        $this->runJob($config);

        // Level 2 would be 0.10*1.5^2=0.225 (well under the 1.0 clip), but
        // 0.25 + 0.225 = 0.475 > 0.35 -- blocked on exposure alone.
        $this->assertDatabaseMissing('forex_grid_bot_levels', [
            'forex_grid_bot_config_id' => $config->id,
            'level_index' => 2,
        ]);

        $event = ForexGridBotEvent::where('forex_grid_bot_config_id', $config->id)
            ->where('event_type', ForexGridBotEvent::EVENT_BLOCKED)
            ->first();
        $this->assertNotNull($event);
        $this->assertSame('max_total_exposure_lots', $event->context_json['cap']);
    }

    /** @test */
    public function basket_stop_loss_closes_every_open_level_not_partially(): void
    {
        $config = $this->makeConfig([
            'direction_mode' => 'both',
            'basket_stop_loss_pct' => 10,
            'basket_take_profit_pct' => null,
            // Capped at exactly the 2 levels seeded below -- direction_mode
            // 'both' would otherwise keep opening further BUY levels every
            // tick too (price is far below both anchors), which would
            // muddy this test's "closes every open level" assertion with
            // an extra level opened in the same tick as the close.
            'max_grid_levels' => 2,
            'max_total_exposure_lots' => 100,
        ]);
        $this->makeState($config);

        // Both deeply underwater relative to the ~1.08 sandbox mid: a BUY
        // anchored at 2.0 (mid is far below entry -> big loss) plus a
        // second BUY at 2.5 (even deeper loss) -- basket floating P&L is
        // guaranteed strongly negative regardless of the small sandbox
        // jitter.
        $this->seedLevel($config, ['level_index' => 0, 'side' => 'buy', 'entry_price' => 2.0, 'lot_size' => 0.10]);
        $this->seedLevel($config, ['level_index' => 1, 'side' => 'buy', 'entry_price' => 2.5, 'lot_size' => 0.10]);

        $this->runJob($config);

        $this->assertDatabaseMissing('forex_grid_bot_levels', ['forex_grid_bot_config_id' => $config->id, 'status' => 'filled']);
        $this->assertSame(0, ForexGridBotLevel::where('forex_grid_bot_config_id', $config->id)->where('status', 'filled')->count());
        $this->assertSame(2, ForexGridBotLevel::where('forex_grid_bot_config_id', $config->id)->where('status', 'closed')->count());

        $state = $config->state()->first();
        $this->assertSame(ForexGridBotState::STATUS_STOPPED, $state->status);
        $this->assertSame(0, $state->active_levels_count);

        $event = ForexGridBotEvent::where('forex_grid_bot_config_id', $config->id)
            ->where('event_type', ForexGridBotEvent::EVENT_BASKET_CLOSED)
            ->first();
        $this->assertNotNull($event);
        $this->assertSame('stop_loss_triggered', $event->context_json['reason']);
    }

    /** @test */
    public function basket_take_profit_closes_every_open_level_symmetrically(): void
    {
        $config = $this->makeConfig([
            'direction_mode' => 'both',
            'basket_stop_loss_pct' => 90,
            'basket_take_profit_pct' => 10,
            'max_grid_levels' => 20,
            'max_total_exposure_lots' => 100,
        ]);
        $this->makeState($config);

        // SELL levels anchored well above the ~1.08 sandbox mid: price has
        // fallen a lot since selling -> a large, robust profit regardless
        // of jitter.
        $this->seedLevel($config, ['level_index' => 0, 'side' => 'sell', 'entry_price' => 2.0, 'lot_size' => 0.10]);
        $this->seedLevel($config, ['level_index' => 1, 'side' => 'sell', 'entry_price' => 2.5, 'lot_size' => 0.10]);

        $this->runJob($config);

        $this->assertSame(0, ForexGridBotLevel::where('forex_grid_bot_config_id', $config->id)->where('status', 'filled')->count());
        $this->assertSame(2, ForexGridBotLevel::where('forex_grid_bot_config_id', $config->id)->where('status', 'closed')->count());

        $state = $config->state()->first();
        $this->assertSame(ForexGridBotState::STATUS_STOPPED, $state->status);

        $event = ForexGridBotEvent::where('forex_grid_bot_config_id', $config->id)
            ->where('event_type', ForexGridBotEvent::EVENT_BASKET_CLOSED)
            ->first();
        $this->assertNotNull($event);
        $this->assertSame('take_profit_triggered', $event->context_json['reason']);
    }
}
