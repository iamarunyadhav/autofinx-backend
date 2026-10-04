<?php

namespace Tests\Unit\RatioBot;

use App\Domain\Exchange\BinanceSpotHealthService;
use App\Domain\Ops\OperationalBreakerService;
use App\Domain\RatioBot\RatioBotAwaitingExecutionWatchdog;
use App\Domain\RatioBot\RatioBotOrderUpdateService;
use App\Domain\RatioBot\RatioBotStateService;
use App\Jobs\RunRatioBotEvaluationJob;
use App\Models\Notification;
use App\Models\Order;
use App\Models\RatioBotConfig;
use App\Models\RatioBotDecision;
use App\Models\RatioBotLot;
use App\Models\RatioBotState;
use App\Models\Symbol;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class RatioBotExecutionRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->workspace = Workspace::factory()->create();
    }

    private function bot(array $state = [], array $config = []): RatioBotConfig
    {
        $bot = RatioBotConfig::create(array_merge([
            'workspace_id' => $this->workspace->id, 'symbol' => 'BTCUSDT', 'name' => 'bot-'.Str::random(6),
            'market_type' => 'spot', 'execution_mode' => 'live', 'enabled' => true,
            'sell_threshold_ratio' => 0.02, 'buy_threshold_ratio' => 0.01,
        ], $config));
        RatioBotState::create(array_merge(['ratio_bot_config_id' => $bot->id, 'position_state' => RatioBotState::STATE_FLAT,
            'last_sell_price' => 100, 'last_trade_side' => 'SELL'], $state));

        return $bot->fresh('state');
    }

    /** A bot frozen in AWAITING_EXECUTION after submitting $decision. */
    private function awaiting(int $secondsAgo = 600): array
    {
        $bot = $this->bot();
        $decision = RatioBotDecision::create([
            'ratio_bot_config_id' => $bot->id, 'decision' => RatioBotDecision::DECISION_BUY, 'evaluated_price' => 100,
            'position_state_before' => RatioBotState::STATE_FLAT, 'decided_at' => now()->subSeconds($secondsAgo),
            'execution_status' => RatioBotDecision::EXEC_PENDING,
        ]);
        $clientOrderId = 'rb-'.str_replace('-', '', (string) $decision->id);
        app(RatioBotStateService::class)->onAwaitingExecution($bot, $decision, $clientOrderId);
        RatioBotState::where('ratio_bot_config_id', $bot->id)->update(['awaiting_since' => now()->subSeconds($secondsAgo)]);

        return [$bot->fresh('state'), $decision->fresh(), $clientOrderId];
    }

    private function order(RatioBotConfig $bot, RatioBotDecision $decision, string $clientOrderId, array $overrides = []): Order
    {
        $symbol = Symbol::firstOrCreate(['symbol' => 'BTCUSDT'], ['id' => (string) Str::uuid(), 'type' => 'crypto']);

        return Order::create(array_merge([
            'id' => (string) Str::uuid(), 'workspace_id' => $this->workspace->id, 'symbol_id' => $symbol->id,
            'provider' => 'binance', 'exchange' => 'binance', 'asset_class' => 'CRYPTO', 'asset_code' => 'BTCUSDT',
            'quote_currency' => 'USDT', 'side' => 'buy', 'type' => 'market', 'quantity' => 0.5, 'qty' => 0.5,
            'requested_qty' => 0.5, 'price' => 100, 'status' => Order::STATUS_UNCERTAIN,
            'reconciliation_state' => Order::RECONCILIATION_UNCERTAIN, 'client_order_id' => $clientOrderId,
            'idempotency_key' => $clientOrderId, 'trace_id' => (string) Str::uuid(), 'placed_ts' => now()->subMinutes(10),
            'meta' => ['source' => 'ratio_bot', 'ratio_bot_config_id' => (string) $bot->id,
                'ratio_bot_decision_id' => (string) $decision->id, 'ratio_bot_signal' => $decision->decision],
        ], $overrides));
    }

    public function test_watchdog_releases_a_bot_whose_order_was_never_sent(): void
    {
        [$bot, $decision] = $this->awaiting();

        $this->assertSame('released_never_sent', app(RatioBotAwaitingExecutionWatchdog::class)->check($bot));
        $this->assertSame(RatioBotState::STATE_FLAT, $bot->state->fresh()->position_state);
        $this->assertSame(RatioBotDecision::EXEC_FAILED, $decision->fresh()->execution_status);
        $this->assertTrue((bool) $bot->fresh()->enabled);
    }

    public function test_watchdog_waits_until_the_timeout(): void
    {
        [$bot] = $this->awaiting(30);

        $this->assertNull(app(RatioBotAwaitingExecutionWatchdog::class)->check($bot));
        $this->assertSame(RatioBotState::STATE_AWAITING_EXECUTION, $bot->state->fresh()->position_state);
    }

    public function test_watchdog_applies_a_fill_the_bot_never_saw(): void
    {
        [$bot, $decision, $clientOrderId] = $this->awaiting();
        $this->order($bot, $decision, $clientOrderId, ['status' => Order::STATUS_FILLED, 'filled_qty' => 0.5,
            'avg_fill_price' => 100, 'exchange_order_id' => '42', 'reconciliation_state' => Order::RECONCILIATION_ALIGNED]);

        $this->assertSame('applied_order_result', app(RatioBotAwaitingExecutionWatchdog::class)->check($bot));
        $state = $bot->state->fresh();
        $this->assertSame(RatioBotState::STATE_HOLDING, $state->position_state);
        $this->assertEqualsWithDelta(0.5, (float) $state->last_buy_quantity, 1e-8);
        $this->assertSame(RatioBotDecision::EXEC_FILLED, $decision->fresh()->execution_status);
    }

    public function test_watchdog_sends_an_unresolved_order_to_review_and_alerts_once(): void
    {
        [$bot, $decision, $clientOrderId] = $this->awaiting();
        $this->order($bot, $decision, $clientOrderId);

        $this->assertSame('error_review', app(RatioBotAwaitingExecutionWatchdog::class)->check($bot));
        $this->assertSame(RatioBotState::STATE_ERROR_REVIEW, $bot->state->fresh()->position_state);
        $this->assertFalse((bool) $bot->fresh()->enabled);
        $this->assertSame(1, Notification::where('type', 'ratio_bot_alert')->count());

        app(RatioBotStateService::class)->errorReviewRequired($bot, $decision, 'execution_stuck', 'again');
        $this->assertSame(1, Notification::where('type', 'ratio_bot_alert')->count());   // same reason inside the cooldown
    }

    public function test_a_block_raises_an_alert(): void
    {
        $bot = $this->bot();

        app(RatioBotStateService::class)->block($bot, 'stale_feed', 'Latest market price is stale.');

        $alert = Notification::where('type', 'ratio_bot_alert')->first();
        $this->assertNotNull($alert);
        $this->assertStringContainsString('stale_feed', (string) data_get($alert->content, 'body'));
    }

    public function test_another_bots_uncertain_order_does_not_block_this_bot(): void
    {
        $bot = $this->bot([], ['execution_mode' => 'sandbox']);
        $sibling = $this->bot([], ['execution_mode' => 'sandbox']);
        $decision = RatioBotDecision::create(['ratio_bot_config_id' => $sibling->id, 'decision' => RatioBotDecision::DECISION_BUY,
            'evaluated_price' => 100, 'decided_at' => now(), 'execution_status' => RatioBotDecision::EXEC_SUBMITTED]);
        $this->order($sibling, $decision, 'rb-sibling', ['placed_ts' => now()]);

        $this->assertNull($this->healthBlock($bot));
        $this->assertSame('RECONCILIATION_MISMATCH', $this->healthBlock($sibling)['code'] ?? null);
    }

    public function test_a_partially_filled_expired_order_books_the_filled_part(): void
    {
        $bot = $this->bot();
        $decision = RatioBotDecision::create(['ratio_bot_config_id' => $bot->id, 'decision' => RatioBotDecision::DECISION_BUY,
            'evaluated_price' => 100, 'decided_at' => now(), 'execution_status' => RatioBotDecision::EXEC_SUBMITTED,
            'position_state_before' => RatioBotState::STATE_FLAT]);
        $order = $this->order($bot, $decision, 'rb-partial', ['status' => 'CANCELLED', 'filled_qty' => 0.2,
            'avg_fill_price' => 100, 'exchange_order_id' => '7', 'reconciliation_state' => Order::RECONCILIATION_ALIGNED]);

        app(RatioBotOrderUpdateService::class)->syncFromOrder($order);

        $this->assertEqualsWithDelta(0.2, (float) $bot->state->fresh()->last_buy_quantity, 1e-8);
        $this->assertSame(RatioBotDecision::EXEC_FILLED, $decision->fresh()->execution_status);
        $this->assertSame(0, RatioBotLot::where('ratio_bot_config_id', $bot->id)->where('original_qty', '>', 0.2)->count());
    }

    private function healthBlock(RatioBotConfig $bot): ?array
    {
        $job = new RunRatioBotEvaluationJob((string) $bot->id);
        $method = new \ReflectionMethod($job, 'healthBlockReason');

        return $method->invoke($job, $bot->fresh('state'), app(OperationalBreakerService::class), app(BinanceSpotHealthService::class), []);
    }
}
