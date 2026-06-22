<?php

use App\Domain\Exchange\BinanceFuturesClient;
use App\Domain\FutureTrade\FutureTradeBotService;
use App\Domain\FutureTrade\FutureTradeLatencyService;
use App\Domain\FutureTrade\FutureTradeOrderRouter;
use App\Domain\Trading\CandleDirectionBotService;
use App\Models\FutureTradeBotConfig;
use App\Models\FutureTradeWebhookAudit;
use App\Models\RobotSession;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function auditTraceConfig(string $workspaceId, array $settings = []): FutureTradeBotConfig
{
    $config = FutureTradeBotConfig::create([
        'workspace_id' => $workspaceId,
        'bot_name' => 'Future Trade Bot',
        'paper_trading' => true,
        'enable_live_trading' => false,
        'enabled' => true,
        'settings' => array_merge(config('future_trade.defaults'), [
            'signalSource' => 'webhook',
            'symbols' => ['ETHUSDT', 'BTCUSDT', 'SOLUSDT', 'BNBUSDT'],
            'restrictToWorkspaceSymbols' => true,
            'permissiveSymbolMode' => false,
            'timeframe' => '15m',
        ], $settings),
    ]);

    $config->state()->create([
        'status' => 'Running',
        'state' => 'SCANNING',
        'connection_status' => 'Connected',
        'trading_day' => now()->toDateString(),
    ]);

    return $config->fresh('state');
}

function auditTvPayload(array $overrides = []): array
{
    return array_merge([
        'workspace_id' => null,
        'token' => 'pine-secret',
        'profile' => 'autofinx_prime_v6_1_7_crypto',
        'symbol' => 'ETHUSDT.P',
        'timeframe' => '15m',
        'signal' => 'BUY',
        'price' => 2500,
        'score' => 82,
        'bar_confirmed' => true,
        'candle_open_time' => '2026-06-22T10:15:00Z',
        'candle_close_time' => '2026-06-22T10:30:00Z',
    ], $overrides);
}

test('TradingView BUY webhook creates an audit row', function () {
    Queue::fake();
    config(['future_trade.pine_webhook_token' => 'pine-secret']);
    Carbon::setTestNow('2026-06-22 10:30:05');
    $workspace = Workspace::factory()->create();
    auditTraceConfig($workspace->id);

    $this->postJson('/api/v1/future-trade/webhook/tradingview', auditTvPayload([
        'workspace_id' => $workspace->id,
    ]))->assertOk()->assertJsonPath('accepted', true);

    $audit = FutureTradeWebhookAudit::query()->first();
    expect($audit)->not->toBeNull()
        ->and($audit->source)->toBe('tradingview')
        ->and($audit->token_valid)->toBeTrue()
        ->and($audit->symbol)->toBe('ETHUSDT')
        ->and($audit->normalized_signal)->toBe('BUY');
});

test('invalid TradingView token returns 401 and saves rejected audit row', function () {
    config(['future_trade.pine_webhook_token' => 'pine-secret']);
    $workspace = Workspace::factory()->create();
    auditTraceConfig($workspace->id);

    $this->postJson('/api/v1/future-trade/webhook/tradingview', auditTvPayload([
        'workspace_id' => $workspace->id,
        'token' => 'wrong',
    ]))->assertStatus(401)->assertJsonPath('reason', 'invalid_or_missing_webhook_token');

    $audit = FutureTradeWebhookAudit::query()->first();
    expect($audit)->not->toBeNull()
        ->and($audit->token_valid)->toBeFalse()
        ->and($audit->validation_status)->toBe('rejected')
        ->and($audit->reject_reason)->toBe('invalid_token');
});

test('CLOSE_SHORT normalizes to CLOSE in webhook audit', function () {
    Queue::fake();
    config(['future_trade.pine_webhook_token' => 'pine-secret']);
    $workspace = Workspace::factory()->create();
    auditTraceConfig($workspace->id);

    $this->postJson('/api/v1/future-trade/webhook/tradingview', auditTvPayload([
        'workspace_id' => $workspace->id,
        'signal' => 'CLOSE_SHORT',
    ]))->assertOk();

    expect(FutureTradeWebhookAudit::query()->value('normalized_signal'))->toBe('CLOSE');
});

test('unsupported symbol is rejected with explicit audit reason', function () {
    config(['future_trade.pine_webhook_token' => 'pine-secret']);
    Carbon::setTestNow('2026-06-22 10:30:05');
    $workspace = Workspace::factory()->create();
    $config = auditTraceConfig($workspace->id);
    $payload = auditTvPayload([
        'workspace_id' => $workspace->id,
        'source' => 'pine_webhook',
        'symbol' => 'XAUUSD',
    ]);

    app(FutureTradeBotService::class)->recordRawPineWebhookRequest($config, $payload, ['tokenStatus' => 'valid']);
    $result = app(FutureTradeBotService::class)->ingestPineWebhook($config, $payload);

    $audit = FutureTradeWebhookAudit::query()->first();
    expect($result['reason'])->toBe('symbol_not_allowed')
        ->and($audit->validation_status)->toBe('rejected')
        ->and($audit->reject_reason)->toBe('unsupported_symbol');
});

test('duplicate same candle webhook is blocked with duplicate reason', function () {
    config(['future_trade.pine_webhook_token' => 'pine-secret']);
    Carbon::setTestNow('2026-06-22 10:30:05');
    $workspace = Workspace::factory()->create();
    $config = auditTraceConfig($workspace->id);
    $payload = auditTvPayload(['source' => 'pine_webhook', 'workspace_id' => $workspace->id]);

    app(FutureTradeBotService::class)->ingestPineWebhook($config, $payload);
    $second = app(FutureTradeBotService::class)->ingestPineWebhook($config, $payload);

    expect($second['execution'])->toBe('duplicate_ignored')
        ->and($second['reason'])->toBe('duplicate_signal');
});

test('scanner BUY is blocked when mirror live is disabled', function () {
    $workspace = Workspace::factory()->create();
    $config = auditTraceConfig($workspace->id, [
        'signalSource' => 'mirror',
        'primeMirrorLiveEnabled' => false,
        'liveTrading' => true,
        'paperTrading' => false,
    ]);
    $config->update(['paper_trading' => false, 'enable_live_trading' => true]);
    $method = new ReflectionMethod(app(FutureTradeBotService::class), 'primeMirrorExecutionBlocked');
    $method->setAccessible(true);

    $blocked = $method->invoke(app(FutureTradeBotService::class), $config->fresh('state'), $config->mergedSettings(), [
        'source' => 'backend_mirror',
        'signal' => 'BUY',
    ]);

    expect($blocked)->toBeTrue();
});

test('robot returns WAIT when candle is noisy', function () {
    $session = new RobotSession([
        'active_side' => null,
        'timeframe' => '15m',
        'indicator_set' => [],
        'confidence_threshold' => 60,
    ]);

    $decision = app(CandleDirectionBotService::class)->evaluateCandle($session, [[
        'open' => 100,
        'high' => 110,
        'low' => 90,
        'close' => 101,
        'volume' => 1000,
    ]]);

    expect($decision['action'])->toBe('HOLD')
        ->and($decision['reason'])->toBe('noisy_candle_filtered')
        ->and($decision['signal']['noisy_candle'])->toBeTrue();
});

test('order router can route live orders through Binance websocket transport', function () {
    $workspace = Workspace::factory()->create();
    $config = auditTraceConfig($workspace->id, [
        'orderTransport' => 'websocket',
        'orderWebSocketEnabled' => true,
    ]);
    $client = new class extends BinanceFuturesClient {
        public string $called = '';

        public function __construct()
        {
            parent::__construct('key', 'secret', 'https://testnet.binancefuture.com');
        }

        public function placeOrder(array $payload): array
        {
            $this->called = 'rest';

            return ['orderId' => 1, 'status' => 'NEW', 'clientOrderId' => $payload['newClientOrderId'] ?? 'rest'];
        }

        public function placeOrderViaWebSocket(array $payload): array
        {
            $this->called = 'websocket';

            return ['orderId' => 2, 'status' => 'NEW', 'clientOrderId' => $payload['newClientOrderId'] ?? 'ws'];
        }
    };

    $result = (new FutureTradeOrderRouter(app(FutureTradeLatencyService::class)))->place($config, $client, [
        'symbol' => 'ETHUSDT',
        'side' => 'BUY',
        'type' => 'MARKET',
        'quantity' => '0.01',
        'newClientOrderId' => 'ft-test-ws',
    ]);

    expect($client->called)->toBe('websocket')
        ->and($result['transport'])->toBe('websocket')
        ->and($result['response']['orderId'])->toBe(2);
});

test('order router falls back to REST when websocket transport is disabled', function () {
    $workspace = Workspace::factory()->create();
    $config = auditTraceConfig($workspace->id, [
        'orderTransport' => 'websocket',
        'orderWebSocketEnabled' => false,
    ]);
    $client = new class extends BinanceFuturesClient {
        public string $called = '';

        public function __construct()
        {
            parent::__construct('key', 'secret', 'https://testnet.binancefuture.com');
        }

        public function placeOrder(array $payload): array
        {
            $this->called = 'rest';

            return ['orderId' => 1, 'status' => 'NEW', 'clientOrderId' => $payload['newClientOrderId'] ?? 'rest'];
        }

        public function placeOrderViaWebSocket(array $payload): array
        {
            $this->called = 'websocket';

            return ['orderId' => 2, 'status' => 'NEW', 'clientOrderId' => $payload['newClientOrderId'] ?? 'ws'];
        }
    };

    $result = (new FutureTradeOrderRouter(app(FutureTradeLatencyService::class)))->place($config, $client, [
        'symbol' => 'ETHUSDT',
        'side' => 'BUY',
        'type' => 'MARKET',
        'quantity' => '0.01',
        'newClientOrderId' => 'ft-test-rest',
    ]);

    expect($client->called)->toBe('rest')
        ->and($result['transport'])->toBe('rest')
        ->and($result['response']['orderId'])->toBe(1);
});
