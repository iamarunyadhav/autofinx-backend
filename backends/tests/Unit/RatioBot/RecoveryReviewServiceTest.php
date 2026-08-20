<?php

namespace Tests\Unit\RatioBot;

use App\Domain\RatioBot\Contracts\AnchorCandidateProviderInterface;
use App\Domain\RatioBot\DynamicThresholdService;
use App\Domain\RatioBot\IndicatorService;
use App\Domain\RatioBot\NullAnchorCandidateProvider;
use App\Domain\RatioBot\RecoveryReviewService;
use App\Domain\RatioBot\StrategyManagerService;
use App\Models\RatioBotConfig;
use App\Models\RatioBotState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecoveryReviewServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function never_reanchors_a_stuck_position_below_cost_basis(): void
    {
        $service = new RecoveryReviewService($this->strategyManagerStub(StrategyManagerService::REGIME_STRONG_UPTREND), new NullAnchorCandidateProvider());
        $config = $this->makeConfig();
        $state = new RatioBotState(['ratio_bot_config_id' => $config->id, 'last_buy_price' => 100.0]);

        // currentPrice 80 < cost basis 100 -> position is at a loss.
        $result = $service->review($config, $state, 80.0, 'position_stuck');

        $this->assertSame(RecoveryReviewService::OUTCOME_PAUSE, $result['outcome']);
    }

    /** @test */
    public function continues_waiting_in_unstable_regimes(): void
    {
        $service = new RecoveryReviewService($this->strategyManagerStub(StrategyManagerService::REGIME_HIGH_VOLATILITY), new NullAnchorCandidateProvider());
        $config = $this->makeConfig();
        $state = new RatioBotState(['ratio_bot_config_id' => $config->id, 'last_buy_price' => 100.0]);

        $result = $service->review($config, $state, 110.0, 'position_stuck');

        $this->assertSame(RecoveryReviewService::OUTCOME_CONTINUE_WAIT, $result['outcome']);
    }

    /** @test */
    public function keeps_existing_anchor_when_regime_still_favors_it(): void
    {
        // cash_stuck (fully sold, waiting for a buy) with a still-favorable downtrend.
        $service = new RecoveryReviewService($this->strategyManagerStub(StrategyManagerService::REGIME_STRONG_DOWNTREND), new NullAnchorCandidateProvider());
        $config = $this->makeConfig();
        $state = new RatioBotState(['ratio_bot_config_id' => $config->id, 'last_buy_price' => 0]);

        $result = $service->review($config, $state, 50.0, 'cash_stuck');

        $this->assertSame(RecoveryReviewService::OUTCOME_KEEP_EXISTING_ANCHOR, $result['outcome']);
    }

    /** @test */
    public function continues_waiting_when_no_confident_anchor_candidate_exists(): void
    {
        // Regime flipped (uptrend) for a cash_stuck bot, but the anchor
        // provider (null/default) has zero confidence.
        $service = new RecoveryReviewService($this->strategyManagerStub(StrategyManagerService::REGIME_STRONG_UPTREND), new NullAnchorCandidateProvider());
        $config = $this->makeConfig();
        $state = new RatioBotState(['ratio_bot_config_id' => $config->id, 'last_buy_price' => 0]);

        $result = $service->review($config, $state, 50.0, 'cash_stuck');

        $this->assertSame(RecoveryReviewService::OUTCOME_CONTINUE_WAIT, $result['outcome']);
    }

    /** @test */
    public function reanchors_when_regime_flipped_and_candidate_is_confident(): void
    {
        $service = new RecoveryReviewService(
            $this->strategyManagerStub(StrategyManagerService::REGIME_STRONG_UPTREND),
            $this->confidentAnchorProvider(55.0)
        );
        $config = $this->makeConfig();
        $state = new RatioBotState(['ratio_bot_config_id' => $config->id, 'last_buy_price' => 0]);

        $result = $service->review($config, $state, 50.0, 'cash_stuck');

        $this->assertSame(RecoveryReviewService::OUTCOME_REANCHOR_STRATEGY, $result['outcome']);
        $this->assertEqualsWithDelta(55.0, $result['suggested_anchor']['candidate_price'], 0.0001);
    }

    /** @test */
    public function review_result_never_contains_an_executable_trade_key(): void
    {
        $service = new RecoveryReviewService($this->strategyManagerStub(StrategyManagerService::REGIME_UNCERTAIN), new NullAnchorCandidateProvider());
        $config = $this->makeConfig();
        $state = new RatioBotState(['ratio_bot_config_id' => $config->id, 'last_buy_price' => 100.0]);

        $result = $service->review($config, $state, 110.0, 'position_stuck');

        foreach (['side', 'qty', 'quantity', 'price', 'decision', 'order_id'] as $forbiddenKey) {
            $this->assertArrayNotHasKey($forbiddenKey, $result);
        }
    }

    private function strategyManagerStub(string $regime): StrategyManagerService
    {
        return new class($regime) extends StrategyManagerService {
            public function __construct(private readonly string $fixedRegime)
            {
                parent::__construct(new IndicatorService(), new DynamicThresholdService());
            }

            public function analyze(RatioBotConfig $config, RatioBotState $state, float $currentPrice): array
            {
                return [
                    'enabled' => true,
                    'regime' => $this->fixedRegime,
                    'regime_reason' => 'stubbed for test',
                ];
            }
        };
    }

    private function confidentAnchorProvider(float $price): AnchorCandidateProviderInterface
    {
        return new class($price) implements AnchorCandidateProviderInterface {
            public function __construct(private readonly float $price)
            {
            }

            public function suggestAnchor(RatioBotConfig $config, RatioBotState $state, string $anchorType, array $regimeContext = []): array
            {
                return [
                    'candidate_price' => $this->price,
                    'confidence' => 80,
                    'basis' => 'test_stub',
                    'reason' => 'stubbed confident candidate',
                ];
            }
        };
    }

    private function makeConfig(): RatioBotConfig
    {
        return RatioBotConfig::create([
            'workspace_id' => '00000000-0000-0000-0000-000000000001',
            'symbol' => 'BTCUSDT',
            'market_type' => 'spot',
            'algorithm' => 'ratio_threshold',
            'base_quote_amount' => 100,
            'v2_enabled' => true,
            'recovery_enabled' => true,
            'recovery_timeout_minutes' => 60,
        ]);
    }
}
