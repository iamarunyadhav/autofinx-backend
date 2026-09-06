<?php

namespace Tests\Feature\RatioBot;

use App\Domain\RatioBot\RatioBotRealtimeEngine;
use App\Models\Position;
use App\Models\RatioBotConfig;
use App\Models\RatioBotDecision;
use App\Models\RatioBotEvent;
use App\Models\RatioBotState;
use App\Models\Symbol;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class RatioBotSharedPositionLockTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        User::factory()->create();
        $this->workspace = Workspace::factory()->create();
        Queue::fake();
    }

    /** @test */
    public function a_bot_that_cannot_acquire_the_force_exit_lock_holds_and_logs_contention_instead_of_double_submitting(): void
    {
        $configA = $this->createFuturesConfig(['name' => 'Bot A']);
        $this->createPosition($configA, liquidationBufferPct: 2.0);
        $this->syncPosition($configA, qty: 1.0, side: 'long');

        // Simulate Bot B already holding the lock on this exact shared
        // position (same workspace + symbol) mid force-exit.
        $lockKey = 'ratio-bot-force-exit:' . $configA->workspace_id . ':' . $configA->normalizedSymbol();
        $contendingLock = Cache::lock($lockKey, 10);
        $this->assertTrue($contendingLock->get(), 'test setup: must be able to acquire the lock first');

        $result = app(RatioBotRealtimeEngine::class)->evaluate($configA->fresh('state'), 50000.0);

        $this->assertSame(RatioBotDecision::DECISION_HOLD, $result['decision']);
        $this->assertStringContainsString('lock', (string) $result['decision_record']->reason);
        $this->assertDatabaseHas('ratio_bot_events', [
            'ratio_bot_config_id' => $configA->id,
            'event_type' => RatioBotEvent::EVENT_FORCE_EXIT_LOCK_CONTENDED,
        ]);
        // No competing close was submitted by this bot while contended.
        $this->assertDatabaseMissing('ratio_bot_decisions', [
            'ratio_bot_config_id' => $configA->id,
            'decision' => RatioBotDecision::DECISION_EXIT_LONG,
        ]);

        $contendingLock->release();
    }

    /** @test */
    public function the_force_exit_succeeds_and_releases_the_lock_when_it_is_free(): void
    {
        $config = $this->createFuturesConfig(['mode' => 'restricted_auto']);
        $this->createPosition($config, liquidationBufferPct: 2.0);
        $this->syncPosition($config, qty: 1.0, side: 'long');

        $result = app(RatioBotRealtimeEngine::class)->evaluate($config->fresh('state'), 50000.0);

        $this->assertSame(RatioBotDecision::DECISION_EXIT_LONG, $result['decision']);

        // The lock must have been released -- a second evaluation on a
        // (now-flat) position must not itself find the lock contended.
        $lockKey = 'ratio-bot-force-exit:' . $config->workspace_id . ':' . $config->normalizedSymbol();
        $this->assertTrue(Cache::lock($lockKey, 1)->get(), 'the force-exit lock should have been released after use');
    }

    private function createFuturesConfig(array $overrides = []): RatioBotConfig
    {
        $config = RatioBotConfig::create(array_merge([
            'workspace_id' => $this->workspace->id,
            'symbol' => 'BTCUSDT',
            'market_type' => 'futures',
            'sell_threshold_ratio' => 0.50,
            'buy_threshold_ratio' => 0.25,
            'base_quote_amount' => 100.0,
            'execution_mode' => 'sandbox',
            'enabled' => true,
            'liquidation_guard_enabled' => true,
            'liquidation_watch_buffer_pct' => 15.0,
            'liquidation_critical_buffer_pct' => 5.0,
            'leverage' => 5,
        ], $overrides));

        RatioBotState::firstOrCreate(
            ['ratio_bot_config_id' => $config->id],
            ['position_state' => RatioBotState::STATE_FLAT]
        );

        return $config->refresh()->load('state');
    }

    private function syncPosition(RatioBotConfig $config, float $qty, string $side): void
    {
        app(\App\Domain\RatioBot\RatioBotStateService::class)->syncPositionSnapshot($config, $side, $qty, 50000.0, 0);
    }

    private function createPosition(RatioBotConfig $config, float $liquidationBufferPct): Position
    {
        $symbol = Symbol::firstOrCreate(['symbol' => $config->normalizedSymbol()], [
            'id' => (string) Str::uuid(),
            'type' => 'crypto',
            'exchange' => 'binance_futures',
        ]);

        return Position::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $config->workspace_id,
            'symbol_id' => $symbol->id,
            'market_type' => 'futures',
            'qty' => 1.0,
            'quantity' => 1.0,
            'avg_price' => 50000.0,
            'avg_entry' => 50000.0,
            'last_mark_price' => 50000.0,
            'liquidation_price' => 45000.0,
            'liquidation_buffer_pct' => $liquidationBufferPct,
            'mark_price_synced_at' => now(),
        ]);
    }
}
