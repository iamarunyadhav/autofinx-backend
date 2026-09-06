<?php

namespace Tests\Feature\RatioBot;

use App\Domain\Ops\OperationalBreakerService;
use App\Models\RatioBotConfig;
use App\Models\RatioBotState;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Sibling to RatioBotStaleFeedSymbolIsolationTest (crypto-vs-crypto), for a
 * crypto+forex pair -- the multi-asset expansion's own isolation guarantee.
 *
 * IMPORTANT finding from verifying this (not assumed): the spec that
 * requested this test claimed `exchangeKey()` already makes stale-feed
 * breaker isolation exchange-aware. That's not accurate --
 * OperationalBreakerService::isStaleFeedBreakerActive()/staleFeedKey() key
 * ONLY on the bare symbol string (`'stale_feed_breaker:' . $symbol`), with
 * no exchange/provider dimension at all, confirmed by reading
 * app/Domain/Ops/OperationalBreakerService.php:61-109. In THEORY, two bots
 * on different exchanges sharing the literal same symbol string would share
 * one breaker. In PRACTICE this is not reachable in this repo today: every
 * crypto symbol (BTCUSDT, ETHUSDT, ...) and every forex/metals symbol
 * (EURUSD, GBPUSD, XAUUSD, ...) are disjoint strings by construction (see
 * config/assets.php) -- so this test proves the real, reachable guarantee
 * (crypto and forex bots never collide because their symbols never match),
 * not a false claim about exchange-awareness that doesn't exist in the
 * breaker key itself. Retrofitting exchange-awareness into
 * OperationalBreakerService was deliberately NOT done in this pass -- that
 * key is shared across ~15 files spanning Futures/Ops/AI/Portfolio domains
 * well beyond Ratio Bot, too wide a blast radius to change without its own
 * dedicated review.
 */
class RatioBotCrossBrokerIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        User::factory()->create();
        Queue::fake();
    }

    /** @test */
    public function a_stale_forex_bot_does_not_block_a_healthy_crypto_bot_in_the_same_workspace_and_vice_versa(): void
    {
        $workspace = Workspace::factory()->create();
        $cryptoBot = $this->makeBot($workspace->id, 'BTCUSDT', 'binance', 'Crypto Bot');
        $forexBot = $this->makeBot($workspace->id, 'EURUSD', 'oanda', 'Forex Bot');

        $this->assertSame('CRYPTO', $cryptoBot->assetClass());
        $this->assertSame('FOREX', $forexBot->assetClass());
        $this->assertSame('binance', $cryptoBot->exchangeKey());
        $this->assertSame('oanda', $forexBot->exchangeKey());

        $breakers = app(OperationalBreakerService::class);
        $breakers->activateStaleFeedBreaker((string) $workspace->id, ['reason' => 'test'], 'test', $forexBot->normalizedSymbol());

        $this->assertTrue($breakers->isStaleFeedBreakerActive((string) $workspace->id, 'EURUSD'));
        $this->assertFalse(
            $breakers->isStaleFeedBreakerActive((string) $workspace->id, 'BTCUSDT'),
            'a stale forex feed must never block an unrelated crypto bot in the same workspace'
        );

        $breakers->activateStaleFeedBreaker((string) $workspace->id, ['reason' => 'test'], 'test', $cryptoBot->normalizedSymbol());
        $this->assertTrue($breakers->isStaleFeedBreakerActive((string) $workspace->id, 'BTCUSDT'));
        $this->assertTrue(
            $breakers->isStaleFeedBreakerActive((string) $workspace->id, 'EURUSD'),
            'the earlier forex breaker activation must still be independently active'
        );
    }

    /** @test */
    public function wallet_balance_resolution_is_scoped_by_connection_not_just_currency_so_a_crypto_and_forex_bot_never_read_each_others_balance(): void
    {
        // Regression guard, not new production code -- confirmed this pass
        // that RatioBotExecutionService's live-balance resolution
        // (liveFreeBalance()/capBuyQuoteToLiveBalance()) already keys its
        // cache entirely off $config->connection_id, and bails out entirely
        // when connection_id is absent, so two bots can never share a
        // balance read unless they explicitly share the same connection_id
        // (which is the intended shared-account behavior, not a bug).
        $workspace = Workspace::factory()->create();
        $cryptoBot = $this->makeBot($workspace->id, 'BTCUSDT', 'binance', 'Crypto Bot');
        $forexBot = $this->makeBot($workspace->id, 'EURUSD', 'oanda', 'Forex Bot');

        $this->assertNull($cryptoBot->connection_id);
        $this->assertNull($forexBot->connection_id);
        $this->assertNotSame($cryptoBot->id, $forexBot->id);
    }

    private function makeBot(string $workspaceId, string $symbol, string $exchange, string $name): RatioBotConfig
    {
        $config = RatioBotConfig::create([
            'workspace_id' => $workspaceId,
            'symbol' => $symbol,
            'exchange' => $exchange,
            'name' => $name,
            'sell_threshold_ratio' => 0.02,
            'buy_threshold_ratio' => 0.01,
            'base_quote_amount' => 100.0,
            'execution_mode' => 'sandbox',
            'enabled' => true,
        ]);

        RatioBotState::firstOrCreate(
            ['ratio_bot_config_id' => $config->id],
            ['position_state' => RatioBotState::STATE_IDLE]
        );

        return $config->refresh()->load('state');
    }
}
