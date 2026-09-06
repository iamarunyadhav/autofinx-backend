<?php

namespace Tests\Feature\RatioBot;

use App\Domain\Venue\OandaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OandaClientTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function symbol_format_is_translated_between_this_repos_bare_format_and_oandas_underscore_format(): void
    {
        $client = new OandaClient('', '', '');

        $this->assertSame('EUR_USD', $client->toInstrument('EURUSD'));
        $this->assertSame('XAU_USD', $client->toInstrument('XAUUSD'));
        $this->assertSame('USD_JPY', $client->toInstrument('usdjpy'));
        // Already in OANDA format -- passed through unchanged.
        $this->assertSame('EUR_USD', $client->toInstrument('EUR_USD'));
    }

    /** @test */
    public function sandbox_mode_needs_no_credentials_and_returns_a_usable_price(): void
    {
        $client = new OandaClient('', '', '', 'sandbox');

        $quote = $client->getLatestPrice('EURUSD');

        $this->assertSame('oanda', $quote['provider']);
        $this->assertGreaterThan(0, $quote['bid']);
        $this->assertGreaterThan(0, $quote['ask']);
        $this->assertGreaterThan($quote['bid'], $quote['ask']);
    }

    /** @test */
    public function sandbox_order_placement_fills_immediately_and_side_determines_the_fill_direction(): void
    {
        $client = new OandaClient('', '', '', 'sandbox');

        $buy = $client->placeOrder(['asset_code' => 'EURUSD', 'side' => 'BUY', 'qty' => 1000]);
        $this->assertSame('FILLED', $buy['status']);
        $this->assertSame(1000.0, $buy['executed_qty']);

        $sell = $client->placeOrder(['asset_code' => 'EURUSD', 'side' => 'SELL', 'qty' => 500]);
        $this->assertSame('FILLED', $sell['status']);
        $this->assertSame(500.0, $sell['executed_qty']);
    }

    /** @test */
    public function sandbox_balances_returns_a_usable_shape(): void
    {
        $client = new OandaClient('', '', '', 'sandbox');

        $balances = $client->fetchBalances();

        $this->assertNotEmpty($balances);
        $this->assertArrayHasKey('asset', $balances[0]);
        $this->assertArrayHasKey('free', $balances[0]);
    }

    /** @test */
    public function the_real_order_request_expresses_side_as_signed_units_per_oandas_documented_v20_api(): void
    {
        $captured = null;
        Http::fake(function (\Illuminate\Http\Client\Request $request) use (&$captured) {
            if (str_contains($request->url(), '/orders') && $request->method() === 'POST') {
                $captured = $request->data();

                return Http::response([
                    'orderFillTransaction' => ['id' => '1234', 'units' => '-1000', 'price' => '1.0850'],
                ], 200);
            }

            return Http::response([], 200);
        });

        $client = new OandaClient('https://api-fxpractice.oanda.com', 'test-token', 'test-account', 'live');
        $result = $client->placeOrder(['asset_code' => 'EURUSD', 'side' => 'SELL', 'qty' => 1000, 'type' => 'MARKET']);

        $this->assertNotNull($captured);
        $this->assertSame('EUR_USD', $captured['order']['instrument']);
        $this->assertSame('-1000', $captured['order']['units'], 'SELL must be expressed as negative units, not a separate side field');
        $this->assertSame('MARKET', $captured['order']['type']);
        $this->assertSame('FILLED', $result['status']);
        $this->assertSame(1000.0, $result['executed_qty']);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            return $request->hasHeader('Authorization', 'Bearer test-token');
        });
    }

    /** @test */
    public function the_real_pricing_request_hits_the_documented_endpoint_and_parses_bid_ask(): void
    {
        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            if (str_contains($request->url(), '/pricing')) {
                return Http::response([
                    'prices' => [[
                        'instrument' => 'EUR_USD',
                        'bids' => [['price' => '1.0845']],
                        'asks' => [['price' => '1.0847']],
                    ]],
                ], 200);
            }

            return Http::response([], 200);
        });

        $client = new OandaClient('https://api-fxpractice.oanda.com', 'test-token', 'test-account', 'live');
        $quote = $client->getLatestPrice('EURUSD');

        $this->assertEqualsWithDelta(1.0845, $quote['bid'], 0.0001);
        $this->assertEqualsWithDelta(1.0847, $quote['ask'], 0.0001);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            return str_contains($request->url(), '/v3/accounts/test-account/pricing')
                && ($request->data()['instruments'] ?? null) === 'EUR_USD';
        });
    }
}
