<?php

namespace Tests\Feature\Pepperstone;

use App\Domain\Venue\PepperstoneBrokerAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Sandbox-mode round-trip tests only -- no real Pepperstone account exists
 * or should exist yet (Phase 0 pending). Mirrors OandaClientTest's sandbox
 * coverage shape for the same reason that class documents: this is the
 * proven pattern to copy.
 */
class PepperstoneBrokerAdapterTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function sandbox_mode_needs_no_credentials_and_returns_a_usable_quote(): void
    {
        $client = new PepperstoneBrokerAdapter('', '', '', 'sandbox');

        $quote = $client->getLatestPrice('XAUUSD');

        $this->assertSame('pepperstone', $quote['provider']);
        $this->assertGreaterThan(0, $quote['bid']);
        $this->assertGreaterThan(0, $quote['ask']);
        $this->assertGreaterThan($quote['bid'], $quote['ask']);
    }

    /** @test */
    public function every_covered_instrument_returns_a_distinct_realistic_base_price(): void
    {
        $client = new PepperstoneBrokerAdapter('', '', '', 'sandbox');

        $gold = $client->getLatestPrice('XAUUSD');
        $silver = $client->getLatestPrice('XAGUSD');
        $platinum = $client->getLatestPrice('XPTUSD');
        $crude = $client->getLatestPrice('WTICOUSD');

        $this->assertEqualsWithDelta(2150.0, $gold['mid'], 50.0);
        $this->assertEqualsWithDelta(24.5, $silver['mid'], 5.0);
        $this->assertEqualsWithDelta(950.0, $platinum['mid'], 50.0);
        $this->assertEqualsWithDelta(78.0, $crude['mid'], 10.0);
    }

    /** @test */
    public function sandbox_order_placement_fills_immediately_and_side_determines_fill_direction(): void
    {
        $client = new PepperstoneBrokerAdapter('', '', '', 'sandbox');

        $buy = $client->placeOrder(['asset_code' => 'XAUUSD', 'side' => 'BUY', 'qty' => 0.10]);
        $this->assertSame('FILLED', $buy['status']);
        $this->assertSame(0.10, $buy['executed_qty']);

        $sell = $client->placeOrder(['asset_code' => 'XAUUSD', 'side' => 'SELL', 'qty' => 0.05]);
        $this->assertSame('FILLED', $sell['status']);
        $this->assertSame(0.05, $sell['executed_qty']);
    }

    /** @test */
    public function sandbox_orders_can_be_fetched_and_cancelled(): void
    {
        $client = new PepperstoneBrokerAdapter('', '', '', 'sandbox');

        $order = $client->placeOrder(['asset_code' => 'XAUUSD', 'side' => 'BUY', 'qty' => 0.10]);
        $fetched = $client->fetchOrder($order['order_id']);
        $this->assertSame($order['order_id'], $fetched['order_id']);

        $cancelled = $client->cancelOrder($order['order_id']);
        $this->assertSame('CANCELLED', $cancelled['status']);
    }

    /** @test */
    public function sandbox_balances_returns_a_usable_shape(): void
    {
        $client = new PepperstoneBrokerAdapter('', '', '', 'sandbox');

        $balances = $client->fetchBalances();

        $this->assertNotEmpty($balances);
        $this->assertArrayHasKey('asset', $balances[0]);
        $this->assertArrayHasKey('free', $balances[0]);
    }

    /** @test */
    public function the_live_path_throws_a_clear_not_implemented_exception_for_every_method(): void
    {
        $client = new PepperstoneBrokerAdapter('https://example.invalid', 'key', 'account', 'live');

        foreach (['getLatestPrice', 'fetchBalances'] as $method) {
            try {
                $method === 'getLatestPrice' ? $client->getLatestPrice('XAUUSD') : $client->fetchBalances();
                $this->fail("{$method} should have thrown for the live path");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('not yet implemented', $e->getMessage());
            }
        }

        try {
            $client->placeOrder(['asset_code' => 'XAUUSD', 'side' => 'BUY', 'qty' => 0.1]);
            $this->fail('placeOrder should have thrown for the live path');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not yet implemented', $e->getMessage());
        }
    }
}
