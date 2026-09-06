<?php

namespace Tests\Feature\RatioBot;

use App\Domain\RatioBot\RatioBotRiskService;
use App\Models\RatioBotConfig;
use App\Models\RatioBotDecision;
use App\Models\RatioBotState;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RatioBotMarketHoursGateTest extends TestCase
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

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @test */
    public function a_forex_bot_is_blocked_from_a_new_entry_outside_market_hours(): void
    {
        // Saturday -- config/assets.php forex.market_hours.closed_weekdays = [6].
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-05 12:00:00', 'UTC'));
        $config = $this->createConfig(['symbol' => 'EURUSD']);
        $this->assertSame('FOREX', $config->assetClass());

        $decision = RatioBotDecision::create($this->decisionAttrs($config, RatioBotDecision::DECISION_BUY));
        $block = app(RatioBotRiskService::class)->preTradeBlockReason($config, $decision);

        $this->assertNotNull($block);
        $this->assertSame('market_closed', $block['code']);
        $this->assertFalse((bool) $block['pause'], 'a closed market must never pause the whole bot, only skip this submission');
    }

    /** @test */
    public function a_forex_bot_is_allowed_to_enter_during_market_hours(): void
    {
        // Wednesday, well within the 22:00-22:00 session (effectively always
        // open except the Saturday closed_weekday).
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-09 12:00:00', 'UTC'));
        $config = $this->createConfig(['symbol' => 'EURUSD']);

        $decision = RatioBotDecision::create($this->decisionAttrs($config, RatioBotDecision::DECISION_BUY));
        $block = app(RatioBotRiskService::class)->preTradeBlockReason($config, $decision);

        $this->assertNull($block);
    }

    /** @test */
    public function a_crypto_bot_is_completely_unaffected_by_the_market_hours_gate(): void
    {
        // Same Saturday closed-weekday timestamp that blocks the forex bot above.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-05 12:00:00', 'UTC'));
        $config = $this->createConfig(['symbol' => 'BTCUSDT']);
        $this->assertSame('CRYPTO', $config->assetClass());

        $decision = RatioBotDecision::create($this->decisionAttrs($config, RatioBotDecision::DECISION_BUY));
        $block = app(RatioBotRiskService::class)->preTradeBlockReason($config, $decision);

        $this->assertNull($block);
    }

    /** @test */
    public function an_existing_forex_position_is_never_force_closed_by_the_gate_only_new_entries_are_blocked(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-05 12:00:00', 'UTC'));
        $config = $this->createConfig(['symbol' => 'EURUSD']);

        // A protective exit (e.g. stop-loss) must still be allowed to fire
        // even with the market closed per our config's window interpretation.
        $decision = RatioBotDecision::create($this->decisionAttrs($config, RatioBotDecision::DECISION_SELL, [
            'protective_exit' => true,
            'trigger' => 'stop_loss',
        ]));
        $block = app(RatioBotRiskService::class)->preTradeBlockReason($config, $decision);

        $this->assertNull($block, 'a protective exit must never be blocked by the market-hours gate');
    }

    /** @test */
    public function the_gate_resumes_correctly_once_hours_reopen(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-05 12:00:00', 'UTC'));
        $config = $this->createConfig(['symbol' => 'EURUSD']);
        $blockedDecision = RatioBotDecision::create($this->decisionAttrs($config, RatioBotDecision::DECISION_BUY));
        $this->assertNotNull(app(RatioBotRiskService::class)->preTradeBlockReason($config, $blockedDecision));

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-06 08:00:00', 'UTC'));
        $reopenedDecision = RatioBotDecision::create($this->decisionAttrs($config, RatioBotDecision::DECISION_BUY));
        $this->assertNull(app(RatioBotRiskService::class)->preTradeBlockReason($config, $reopenedDecision));
    }

    private function decisionAttrs(RatioBotConfig $config, string $decision, array $metadata = []): array
    {
        return [
            'ratio_bot_config_id' => $config->id,
            'decision' => $decision,
            'evaluated_price' => 1.1,
            'execution_status' => RatioBotDecision::EXEC_PENDING,
            'metadata' => $metadata,
            'decided_at' => now(),
        ];
    }

    private function createConfig(array $overrides = []): RatioBotConfig
    {
        $config = RatioBotConfig::create(array_merge([
            'workspace_id' => $this->workspace->id,
            'symbol' => 'BTCUSDT',
            'exchange' => 'binance',
            'market_type' => 'spot',
            'sell_threshold_ratio' => 0.50,
            'buy_threshold_ratio' => 0.25,
            'base_quote_amount' => 100.0,
            'execution_mode' => 'sandbox',
            'enabled' => true,
        ], $overrides));

        RatioBotState::firstOrCreate(
            ['ratio_bot_config_id' => $config->id],
            ['position_state' => RatioBotState::STATE_FLAT]
        );

        return $config->refresh()->load('state');
    }
}
