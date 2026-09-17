<?php

namespace Tests\Feature\RatioBot;

use App\Domain\RatioBot\RatioBotExecutionCostReconciliationService;
use App\Domain\Trading\FuturesConfigService;
use App\Models\Integration;
use App\Models\Order;
use App\Models\RatioBotConfig;
use App\Models\RatioBotExecutionCostRecord;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RatioBotExecutionCostReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private RatioBotConfig $bot;
    private array $trades;
    private array $income;
    private bool $failIncome = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        Http::preventStrayRequests();
        $workspace = Workspace::factory()->create();
        $integration = Integration::create(['workspace_id' => $workspace->id, 'type' => 'exchange',
            'provider_key' => 'binance', 'display_name' => 'Test', 'status' => 'active',
            'secrets_encrypted' => Crypt::encryptString(json_encode(['api_key' => 'cost-test-key', 'api_secret' => 'cost-test-secret']))]);
        $this->bot = RatioBotConfig::create(['workspace_id' => $workspace->id, 'symbol' => 'BTCUSDT',
            'market_type' => 'futures', 'execution_mode' => 'live', 'enabled' => false,
            'sell_threshold_ratio' => 0.5, 'buy_threshold_ratio' => 0.25, 'base_quote_amount' => 100]);
        Order::create(['workspace_id' => $workspace->id, 'integration_id' => $integration->id,
            'asset_code' => 'BTCUSDT', 'quote_currency' => 'USDT', 'side' => 'buy', 'quantity' => 1,
            'qty' => 1, 'filled_qty' => 1, 'status' => 'FILLED', 'exchange_order_id' => '10',
            'placed_ts' => now()->subDays(2), 'meta' => ['ratio_bot_config_id' => $this->bot->id, 'evaluated_price' => 99]]);
        $this->trades = [['id' => 100, 'orderId' => 10, 'symbol' => 'BTCUSDT', 'side' => 'BUY',
            'price' => '100', 'qty' => '1', 'time' => now()->subDay()->getTimestampMs(),
            'commission' => '0.2', 'commissionAsset' => 'USDT', 'realizedPnl' => '0', 'positionSide' => 'BOTH']];
        $this->income = [['tranId' => 500, 'symbol' => 'BTCUSDT', 'incomeType' => 'FUNDING_FEE',
            'asset' => 'USDT', 'income' => '-1', 'time' => now()->subHours(12)->getTimestampMs()]];
        $this->mock(FuturesConfigService::class, fn ($mock) => $mock->shouldReceive('effective')->andReturn(['futures_force_testnet' => true]));
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/positionRisk')) {
                return Http::response([['symbol' => 'BTCUSDT', 'positionSide' => 'BOTH', 'positionAmt' => '1']]);
            }
            if (str_contains($request->url(), '/userTrades')) {
                return Http::response(array_values(array_filter($this->trades,
                    fn ($row) => $row['time'] >= $request['startTime'] && $row['time'] <= $request['endTime'])));
            }
            if (str_contains($request->url(), '/myTrades')) {
                return Http::response($this->trades);
            }
            if (str_contains($request->url(), '/income')) {
                return $this->failIncome ? Http::response(['code' => -1000, 'msg' => 'unavailable'], 500)
                    : Http::response(array_values(array_filter($this->income,
                        fn ($row) => $row['time'] >= $request['startTime'] && $row['time'] <= $request['endTime'])));
            }
            $this->fail('Unexpected exchange request: '.$request->url());
        });
    }

    public function test_repeat_reconciliation_is_idempotent_and_does_not_rebook_orders(): void
    {
        $service = app(RatioBotExecutionCostReconciliationService::class);
        $first = $service->reconcile($this->bot);
        $second = $service->reconcile($this->bot);
        $this->assertSame('real', $first['cost_source'], $first['reason'] ?? '');
        $this->assertSame(-1.2, $second['net_pnl_actual']);
        $this->assertSame(4, RatioBotExecutionCostRecord::count());
        $this->assertSame(1, Order::count());
        $this->assertDatabaseCount('wallet_ledger_entries', 0);
    }

    public function test_delayed_commission_is_retried_for_disabled_closed_bots(): void
    {
        $full = $this->trades;
        $this->trades = [];
        $service = app(RatioBotExecutionCostReconciliationService::class);
        $this->assertNull($service->reconcile($this->bot)['net_pnl_actual']);
        $this->trades = $full;
        $this->artisan('ratio-bots:reconcile-costs', ['--bot' => $this->bot->id])->assertSuccessful();
        $report = RatioBotExecutionCostRecord::where('source_key', 'report')->first()->payload;
        $this->assertSame('real', $report['cost_source']);
        $this->assertSame(-1.2, $report['net_pnl_actual']);
    }

    public function test_failed_income_read_preserves_evidence_and_does_not_advance_cursor(): void
    {
        $service = app(RatioBotExecutionCostReconciliationService::class);
        $service->reconcile($this->bot);
        $cursor = RatioBotExecutionCostRecord::where('source_key', 'checkpoint')->first()->payload;
        $this->travel(1)->hours();
        $this->failIncome = true;
        $result = $service->reconcile($this->bot);
        $this->assertSame('unavailable', $result['cost_source']);
        $this->assertNull($result['net_pnl_actual']);
        $this->assertSame($cursor, RatioBotExecutionCostRecord::where('source_key', 'checkpoint')->first()->payload);
        $this->assertDatabaseCount('ratio_bot_execution_cost_records', 4);
    }

    public function test_wrong_symbol_evidence_is_not_committed(): void
    {
        $this->trades[0]['symbol'] = 'ETHUSDT';
        $result = app(RatioBotExecutionCostReconciliationService::class)->reconcile($this->bot);
        $this->assertSame('unavailable', $result['cost_source']);
        $this->assertSame(0, RatioBotExecutionCostRecord::where('source_key', 'checkpoint')->count());
    }

    public function test_retention_gap_is_visible_instead_of_false_zero_cost(): void
    {
        Order::query()->update(['placed_ts' => now()->subMonths(4)]);
        $result = app(RatioBotExecutionCostReconciliationService::class)->reconcile($this->bot);
        $this->assertSame('unavailable', $result['cost_source']);
        $this->assertStringContainsString('retention', $result['reason']);
        Http::assertNothingSent();
    }

    public function test_spot_reads_actual_commission_without_any_futures_funding_request(): void
    {
        $this->bot->update(['market_type' => 'spot']);
        $result = app(RatioBotExecutionCostReconciliationService::class)->reconcile($this->bot);
        $this->assertSame('real', $result['fee_source'], $result['reason'] ?? '');
        $this->assertSame('not_applicable', $result['funding_source']);
        $this->assertSame(-0.2, $result['net_pnl_actual']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/myTrades') && (string) $request['orderId'] === '10');
    }

    public function test_long_hold_catches_every_missed_window_without_recharging_old_funding(): void
    {
        Order::query()->update(['placed_ts' => now()->subDays(18)]);
        $this->trades[0]['time'] = now()->subDays(17)->getTimestampMs();
        $this->income[0]['time'] = now()->subDays(16)->getTimestampMs();
        $this->income[] = array_merge($this->income[0], ['tranId' => 501, 'time' => now()->subDays(8)->getTimestampMs()]);
        $service = app(RatioBotExecutionCostReconciliationService::class);
        $first = $service->reconcile($this->bot);
        $this->assertSame('real', $first['cost_source'], $first['reason'] ?? '');
        $this->assertSame(-2.2, $first['net_pnl_actual']);
        $this->travel(2)->days();
        $again = $service->reconcile($this->bot);
        $this->assertSame(-2.2, $again['net_pnl_actual']);
        $this->assertDatabaseCount('ratio_bot_execution_cost_records', 5);
    }

    public function test_continuously_collected_evidence_survives_exchange_retention(): void
    {
        $service = app(RatioBotExecutionCostReconciliationService::class);
        $first = $service->reconcile($this->bot);
        $this->travel(60)->days();
        $this->assertSame('real', $service->reconcile($this->bot)['cost_source']);
        $this->travel(60)->days();
        $later = $service->reconcile($this->bot);
        $this->assertSame('real', $later['cost_source'], $later['reason'] ?? '');
        $this->assertSame($first['history_from'], $later['history_from']);
        $this->assertSame(-1.2, $later['net_pnl_actual']);
    }
}
