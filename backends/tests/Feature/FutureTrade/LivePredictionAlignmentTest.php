<?php

use App\Domain\Exchange\BinanceFuturesClient;
use App\Domain\FutureTrade\FutureTradeReconciliationService;
use App\Domain\FutureTrade\FutureTradeSignalStabilityService;
use App\Domain\FutureTrade\FutureTradeUserStreamService;
use App\Models\FutureTradeBotConfig;
use App\Models\FutureTradeLogicalTrade;
use App\Models\FutureTradeLogicalTradeEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Live-data alignment: signals must follow the real candle clock, signal_keys
 * must rotate at each candle boundary, and exchange-confirmed fills must
 * propagate cleanly back into logical_trades without overwriting good data.
 */

function laConfig(): FutureTradeBotConfig
{
    return FutureTradeBotConfig::create([
        'workspace_id' => (string) Str::uuid(),
        'bot_name' => 'Future Trade Bot',
        'paper_trading' => false,
        'enable_live_trading' => true,
        'enabled' => true,
        'settings' => array_merge(config('future_trade.defaults'), [
            'liveTrading' => true,
            'paperTrading' => false,
            'forceTestnet' => true,
            'envMode' => 'testnet',
            'envTestnetEnabled' => true,
            'testnetApiKey' => 'test-key',
            'testnetApiSecret' => 'test-secret',
        ]),
    ]);
}

// ─── Candle clock alignment ──────────────────────────────────────────────────

test('current 5m candle open snaps to the actual 5-minute boundary in UTC', function () {
    $svc = new FutureTradeSignalStabilityService();
    Carbon::setTestNow(Carbon::parse('2026-05-17T10:33:42+00:00'));
    try {
        $candle = $svc->currentCandleOpenIso('5m');
        expect($candle)->toBe('2026-05-17T10:30:00+00:00');
    } finally {
        Carbon::setTestNow();
    }
});

test('previous closed 5m candle is exactly 5 minutes before the current one', function () {
    $svc = new FutureTradeSignalStabilityService();
    Carbon::setTestNow(Carbon::parse('2026-05-17T10:33:42+00:00'));
    try {
        expect($svc->previousClosedCandleOpenIso('5m'))->toBe('2026-05-17T10:25:00+00:00');
    } finally {
        Carbon::setTestNow();
    }
});

test('different timeframes produce different candle boundaries — no 1m/5m bleed', function () {
    $svc = new FutureTradeSignalStabilityService();
    Carbon::setTestNow(Carbon::parse('2026-05-17T10:33:42+00:00'));
    try {
        expect($svc->currentCandleOpenIso('1m'))->toBe('2026-05-17T10:33:00+00:00')
            ->and($svc->currentCandleOpenIso('5m'))->toBe('2026-05-17T10:30:00+00:00')
            ->and($svc->currentCandleOpenIso('15m'))->toBe('2026-05-17T10:30:00+00:00')
            ->and($svc->currentCandleOpenIso('1h'))->toBe('2026-05-17T10:00:00+00:00');
    } finally {
        Carbon::setTestNow();
    }
});

// ─── signal_key rotation per candle ──────────────────────────────────────────

test('the same scanner call inside the same candle produces the same signal_key', function () {
    $svc = new FutureTradeSignalStabilityService();
    $a = $svc->generateSignalKey('ETHUSDT', 'SELL', '5m', '2026-05-17T10:25:00+00:00', ['price' => 2300.50]);
    $b = $svc->generateSignalKey('ETHUSDT', 'SELL', '5m', '2026-05-17T10:25:00+00:00', ['price' => 2300.50]);
    expect($a)->toBe($b);
});

test('a fresh candle rotates the signal_key so the next opportunity is not blocked', function () {
    $svc = new FutureTradeSignalStabilityService();
    $old = $svc->generateSignalKey('ETHUSDT', 'SELL', '5m', '2026-05-17T10:25:00+00:00', ['price' => 2300]);
    $new = $svc->generateSignalKey('ETHUSDT', 'SELL', '5m', '2026-05-17T10:30:00+00:00', ['price' => 2300]);
    expect($old)->not->toBe($new);
});

test('different price fingerprints rotate the signal_key even inside the same candle', function () {
    $svc = new FutureTradeSignalStabilityService();
    $a = $svc->generateSignalKey('ETHUSDT', 'SELL', '5m', '2026-05-17T10:25:00+00:00', ['price' => 2300]);
    $b = $svc->generateSignalKey('ETHUSDT', 'SELL', '5m', '2026-05-17T10:25:00+00:00', ['price' => 2301]);
    expect($a)->not->toBe($b);
});

// ─── Signal stability across realistic scan sequences ────────────────────────

test('a stable BUY across three scans confirms WITHOUT requiring candle close', function () {
    $svc = new FutureTradeSignalStabilityService();
    $candle = '2026-05-17T10:25:00+00:00';
    $buffer = [
        ['ts' => time() - 30, 'side' => 'BUY', 'candle' => $candle, 'confidence' => 0.72],
        ['ts' => time() - 20, 'side' => 'BUY', 'candle' => $candle, 'confidence' => 0.74],
        ['ts' => time() - 10, 'side' => 'BUY', 'candle' => $candle, 'confidence' => 0.76],
    ];
    $state = $svc->summarize($buffer, 'BUY', $candle);
    expect($state['confirmed'])->toBe('BUY')
        ->and($state['executable'])->toBe('BUY')
        ->and($state['confirmedScans'])->toBeGreaterThanOrEqual(3);
});

test('a single WAIT between confirmed BUYs does not falsely promote to BUY', function () {
    $svc = new FutureTradeSignalStabilityService();
    $candle = '2026-05-17T10:25:00+00:00';
    $buffer = [
        ['ts' => time() - 30, 'side' => 'BUY', 'candle' => $candle, 'confidence' => 0.72],
        ['ts' => time() - 20, 'side' => 'WAIT', 'candle' => $candle, 'confidence' => 0.50],
        ['ts' => time() - 10, 'side' => 'BUY', 'candle' => $candle, 'confidence' => 0.76],
    ];
    $state = $svc->summarize($buffer, 'BUY', $candle);
    // Only 1 consecutive BUY at the end — must NOT confirm yet
    expect($state['executable'])->toBe('WAIT')
        ->and($state['reason'])->toBe('awaiting_confirmation_scans');
});

test('after a candle flip, the NEXT candle starts fresh and CAN confirm immediately', function () {
    $svc = new FutureTradeSignalStabilityService();
    $oldCandle = '2026-05-17T10:25:00+00:00';
    $newCandle = '2026-05-17T10:30:00+00:00';
    $buffer = [
        ['ts' => time() - 30, 'side' => 'BUY', 'candle' => $oldCandle, 'confidence' => 0.7],
        ['ts' => time() - 25, 'side' => 'SELL', 'candle' => $oldCandle, 'confidence' => 0.6], // flip in old candle
        ['ts' => time() - 10, 'side' => 'BUY', 'candle' => $newCandle, 'confidence' => 0.8],
        ['ts' => time() - 5,  'side' => 'BUY', 'candle' => $newCandle, 'confidence' => 0.82],
    ];
    $state = $svc->summarize($buffer, 'BUY', $newCandle);
    expect($state['sameCandleFlip'])->toBeFalse()
        ->and($state['confirmed'])->toBe('BUY')
        ->and($state['executable'])->toBe('BUY');
});

// ─── User stream → logical_trade alignment ───────────────────────────────────

test('a Binance partial-fill on entry updates quantity & price without closing the trade', function () {
    $config = laConfig();
    $svc = app(FutureTradeUserStreamService::class);

    $trade = FutureTradeLogicalTrade::create([
        'future_trade_bot_config_id' => $config->id,
        'symbol' => 'ETHUSDT', 'side' => 'LONG',
        'status' => FutureTradeLogicalTrade::STATUS_OPEN, 'mode' => 'LIVE_TESTNET',
        'signal_key' => 'sk-partial-1', 'entry_client_order_id' => 'ftx-entry-partial',
        'entry_price' => 0, 'quantity' => 0, 'opened_at' => now(),
    ]);

    $svc->ingestEvent($config, [
        'e' => 'ORDER_TRADE_UPDATE',
        'o' => [
            's' => 'ETHUSDT', 'c' => 'ftx-entry-partial', 'i' => '111',
            'X' => 'PARTIALLY_FILLED', 'x' => 'TRADE', 'S' => 'BUY',
            'ap' => 2300.50, 'z' => 0.03,
        ],
    ]);
    $trade->refresh();
    expect($trade->status)->toBe(FutureTradeLogicalTrade::STATUS_OPEN)
        ->and((float) $trade->entry_price)->toEqualWithDelta(2300.50, 0.0001)
        ->and((float) $trade->quantity)->toEqualWithDelta(0.03, 0.0001);
});

test('a Binance CANCELED entry flips the logical_trade to CANCELLED — no phantom open position', function () {
    $config = laConfig();
    $svc = app(FutureTradeUserStreamService::class);

    $trade = FutureTradeLogicalTrade::create([
        'future_trade_bot_config_id' => $config->id,
        'symbol' => 'ETHUSDT', 'side' => 'LONG',
        'status' => FutureTradeLogicalTrade::STATUS_OPEN, 'mode' => 'LIVE_TESTNET',
        'signal_key' => 'sk-cancel-1', 'entry_client_order_id' => 'ftx-entry-cancel',
        'entry_price' => 2300, 'quantity' => 0.05, 'opened_at' => now(),
    ]);

    $svc->ingestEvent($config, [
        'e' => 'ORDER_TRADE_UPDATE',
        'o' => [
            's' => 'ETHUSDT', 'c' => 'ftx-entry-cancel', 'i' => '222',
            'X' => 'CANCELED', 'x' => 'CANCELED', 'S' => 'BUY',
            'ap' => 0, 'z' => 0,
        ],
    ]);
    $trade->refresh();
    expect($trade->status)->toBe(FutureTradeLogicalTrade::STATUS_CANCELLED);
});

test('a LONG closed via our deterministic SELL clientId is matched and closed at the exchange price', function () {
    $config = laConfig();
    $svc = app(FutureTradeUserStreamService::class);

    $trade = FutureTradeLogicalTrade::create([
        'future_trade_bot_config_id' => $config->id,
        'symbol' => 'ETHUSDT', 'side' => 'LONG',
        'status' => FutureTradeLogicalTrade::STATUS_OPEN, 'mode' => 'LIVE_TESTNET',
        'signal_key' => 'sk-opposing-1',
        'entry_client_order_id' => 'ftx-entry-opp', 'exit_client_order_id' => 'ftx-exit-opp',
        'entry_price' => 2300, 'quantity' => 0.05, 'opened_at' => now(),
    ]);

    $svc->ingestEvent($config, [
        'e' => 'ORDER_TRADE_UPDATE',
        'o' => [
            's' => 'ETHUSDT', 'c' => 'ftx-exit-opp', 'i' => '333',
            'X' => 'FILLED', 'x' => 'TRADE', 'S' => 'SELL',
            'ap' => 2310, 'z' => 0.05, 'rp' => 0.50, 'n' => 0.01,
        ],
    ]);
    $trade->refresh();
    expect($trade->status)->toBe(FutureTradeLogicalTrade::STATUS_CLOSED)
        ->and((float) $trade->exit_price)->toEqualWithDelta(2310.0, 0.0001);
});

test('a SHORT closed via BUY at a HIGHER exit price records a NEGATIVE realized PnL', function () {
    $config = laConfig();
    $svc = app(FutureTradeUserStreamService::class);

    $trade = FutureTradeLogicalTrade::create([
        'future_trade_bot_config_id' => $config->id,
        'symbol' => 'ETHUSDT', 'side' => 'SHORT',
        'status' => FutureTradeLogicalTrade::STATUS_OPEN, 'mode' => 'LIVE_TESTNET',
        'signal_key' => 'sk-short-loss-1',
        'entry_client_order_id' => 'ftx-entry-sl', 'exit_client_order_id' => 'ftx-exit-sl',
        'entry_price' => 2280, 'quantity' => 0.05, 'opened_at' => now(),
    ]);

    $svc->ingestEvent($config, [
        'e' => 'ORDER_TRADE_UPDATE',
        'o' => [
            's' => 'ETHUSDT', 'c' => 'ftx-exit-sl', 'i' => '444',
            'X' => 'FILLED', 'x' => 'TRADE', 'S' => 'BUY',
            'ap' => 2305, 'z' => 0.05, 'rp' => -1.25, 'n' => 0.02,
        ],
    ]);
    $trade->refresh();
    expect($trade->status)->toBe(FutureTradeLogicalTrade::STATUS_CLOSED)
        ->and((float) $trade->net_pnl)->toBeLessThan(0);
});

// ─── Reconciliation aligned with REST fills ──────────────────────────────────

/**
 * Anonymous test double extending BinanceFuturesClient. We only need to
 * override the two methods the reconciler calls — the constructor is fine
 * with empty credentials.
 */
test('a manual Binance app close with an unknown client id closes the matching open trade and clears runtime state', function () {
    $config = laConfig();
    $config->state()->create([
        'status' => 'Running',
        'state' => 'LIVE_IN_POSITION',
        'connection_status' => 'Connected',
        'current_symbol' => 'BTCUSDT',
        'position_side' => 'LONG',
        'entry_price' => 67000,
        'quantity' => 0.01,
        'trade_count_today' => 1,
        'trading_day' => now()->toDateString(),
    ]);
    $svc = app(FutureTradeUserStreamService::class);

    $trade = FutureTradeLogicalTrade::create([
        'future_trade_bot_config_id' => $config->id,
        'symbol' => 'BTCUSDT', 'side' => 'LONG',
        'status' => FutureTradeLogicalTrade::STATUS_OPEN, 'mode' => 'LIVE_TESTNET',
        'signal_key' => 'sk-manual-binance-close',
        'entry_client_order_id' => 'ftx-entry-btc', 'entry_exchange_order_id' => 'entry-1',
        'entry_price' => 67000, 'quantity' => 0.01, 'opened_at' => now()->subMinutes(5),
    ]);

    $updated = $svc->ingestEvent($config, [
        'e' => 'ORDER_TRADE_UPDATE',
        'o' => [
            's' => 'BTCUSDT', 'c' => 'web-manual-close', 'i' => 'manual-9001',
            'X' => 'FILLED', 'x' => 'TRADE', 'S' => 'SELL',
            'ap' => 67100, 'z' => 0.01, 'rp' => 1.00, 'n' => 0.02,
        ],
    ]);

    $trade->refresh();
    $state = $config->fresh('state')->state;
    expect($updated)->toBeTrue()
        ->and($trade->status)->toBe(FutureTradeLogicalTrade::STATUS_CLOSED)
        ->and((string) $trade->exit_client_order_id)->toBe('web-manual-close')
        ->and((float) $state->quantity)->toEqualWithDelta(0.0, 0.000001)
        ->and($state->position_side)->toBeNull()
        ->and($state->current_signal)->toBe('BINANCE_EXTERNAL_CLOSE_FILLED');
});

test('an account update that says exchange is flat clears phantom app open state and flags reconciliation', function () {
    $config = laConfig();
    $config->state()->create([
        'status' => 'Running',
        'state' => 'LIVE_IN_POSITION',
        'connection_status' => 'Connected',
        'current_symbol' => 'BTCUSDT',
        'position_side' => 'LONG',
        'entry_price' => 67000,
        'quantity' => 0.01,
        'trade_count_today' => 1,
        'trading_day' => now()->toDateString(),
    ]);
    $svc = app(FutureTradeUserStreamService::class);

    $trade = FutureTradeLogicalTrade::create([
        'future_trade_bot_config_id' => $config->id,
        'symbol' => 'BTCUSDT', 'side' => 'LONG',
        'status' => FutureTradeLogicalTrade::STATUS_OPEN, 'mode' => 'LIVE_TESTNET',
        'signal_key' => 'sk-account-flat',
        'entry_client_order_id' => 'ftx-entry-btc-flat',
        'entry_price' => 67000, 'quantity' => 0.01, 'opened_at' => now()->subMinutes(5),
    ]);

    $updated = $svc->ingestEvent($config, [
        'e' => 'ACCOUNT_UPDATE',
        'a' => [
            'm' => 'ORDER',
            'P' => [
                ['s' => 'BTCUSDT', 'pa' => '0', 'ep' => '0', 'up' => '0', 'ps' => 'BOTH'],
            ],
        ],
    ]);

    $trade->refresh();
    $state = $config->fresh('state')->state;
    expect($updated)->toBeTrue()
        ->and($trade->status)->toBe(FutureTradeLogicalTrade::STATUS_RECONCILIATION_REQUIRED)
        ->and((float) $state->quantity)->toEqualWithDelta(0.0, 0.000001)
        ->and($state->state)->toBe('SCANNING')
        ->and($state->current_signal)->toBe('ACCOUNT_UPDATE_EXCHANGE_FLAT');
});

function fakeFuturesClient(callable $positionFn, callable $userTradesFn): BinanceFuturesClient
{
    return new class('https://testnet.local', 'x', 'y', $positionFn, $userTradesFn) extends BinanceFuturesClient {
        public function __construct(string $baseUrl, string $apiKey, string $apiSecret, private $positionFn, private $userTradesFn)
        {
            parent::__construct($baseUrl, $apiKey, $apiSecret);
        }
        public function fetchPositionRisk(?string $symbol = null): array
        {
            return ($this->positionFn)($symbol);
        }
        public function fetchUserTrades(string $symbol, ?int $startTimeMs = null, int $limit = 100): array
        {
            return ($this->userTradesFn)($symbol, $startTimeMs, $limit);
        }
    };
}

test('reconciliation closes an OPEN trade only when a matching exchange fill is found', function () {
    $config = laConfig();
    $config->state()->create([
        'status' => 'Running',
        'state' => 'LIVE_IN_POSITION',
        'connection_status' => 'Connected',
        'current_symbol' => 'ETHUSDT',
        'position_side' => 'LONG',
        'entry_price' => 2300,
        'quantity' => 0.04,
        'trading_day' => now()->toDateString(),
    ]);

    $trade = FutureTradeLogicalTrade::create([
        'future_trade_bot_config_id' => $config->id,
        'symbol' => 'ETHUSDT', 'side' => 'LONG',
        'status' => FutureTradeLogicalTrade::STATUS_OPEN, 'mode' => 'LIVE_TESTNET',
        'signal_key' => 'sk-rec-1',
        'entry_client_order_id' => 'ftx-entry-rec', 'exit_client_order_id' => null,
        'entry_price' => 2300, 'quantity' => 0.04,
        'opened_at' => now()->subHour(),
    ]);
    $legacyTrade = $config->trades()->create([
        'symbol' => 'ETHUSDT',
        'side' => 'LONG',
        'entry_price' => 2300,
        'quantity' => 0.04,
        'leverage' => 5,
        'reason' => 'LIVE_BUY_SIGNAL',
        'mode' => 'LIVE_TESTNET',
        'status' => 'OPEN',
    ]);

    $client = fakeFuturesClient(
        fn ($symbol) => [['symbol' => $symbol, 'positionAmt' => '0', 'entryPrice' => '0']],
        fn ($symbol) => [[
            'symbol' => $symbol,
            'orderId' => '9001',
            'clientOrderId' => 'web-manual-close',
            'side' => 'SELL',
            'price' => 2315,
            'qty' => 0.04,
            'commission' => 0.01,
            'time' => now()->getTimestampMs(),
        ]],
    );

    $report = app(FutureTradeReconciliationService::class)->reconcileConfig($config, $client, 'ETHUSDT');

    $trade->refresh();
    $legacyTrade->refresh();
    $state = $config->fresh('state')->state;
    expect($trade->status)->toBe(FutureTradeLogicalTrade::STATUS_CLOSED)
        ->and((float) $trade->exit_price)->toEqualWithDelta(2315.0, 0.0001)
        ->and($legacyTrade->status)->toBe('CLOSED')
        ->and((float) $legacyTrade->exit_price)->toEqualWithDelta(2315.0, 0.0001)
        ->and($legacyTrade->net_pnl)->toBeNull()
        ->and($report['closed'])->toBe(1)
        ->and($report['state_cleared'])->toBe(1)
        ->and((float) $state->quantity)->toEqualWithDelta(0.0, 0.000001)
        ->and($state->current_symbol)->toBeNull()
        ->and($state->current_signal)->toBe('EXCHANGE_RECONCILED');
});

test('reconciliation flags as RECONCILIATION_REQUIRED when exchange is flat but no fill is found', function () {
    $config = laConfig();
    $config->state()->create([
        'status' => 'Running',
        'state' => 'LIVE_IN_POSITION',
        'connection_status' => 'Connected',
        'current_symbol' => 'ETHUSDT',
        'position_side' => 'LONG',
        'entry_price' => 2300,
        'quantity' => 0.04,
        'trading_day' => now()->toDateString(),
    ]);
    $trade = FutureTradeLogicalTrade::create([
        'future_trade_bot_config_id' => $config->id,
        'symbol' => 'ETHUSDT', 'side' => 'LONG',
        'status' => FutureTradeLogicalTrade::STATUS_OPEN, 'mode' => 'LIVE_TESTNET',
        'signal_key' => 'sk-rec-flag-1',
        'entry_client_order_id' => 'ftx-entry-flag',
        'entry_price' => 2300, 'quantity' => 0.04,
        'opened_at' => now()->subHour(),
    ]);
    $legacyTrade = $config->trades()->create([
        'symbol' => 'ETHUSDT',
        'side' => 'LONG',
        'entry_price' => 2300,
        'quantity' => 0.04,
        'leverage' => 5,
        'reason' => 'LIVE_BUY_SIGNAL',
        'mode' => 'LIVE_TESTNET',
        'status' => 'OPEN',
    ]);

    $client = fakeFuturesClient(
        fn ($symbol) => [['symbol' => $symbol, 'positionAmt' => '0']],
        fn ($symbol) => [],
    );

    $report = app(FutureTradeReconciliationService::class)->reconcileConfig($config, $client, 'ETHUSDT');

    $trade->refresh();
    $legacyTrade->refresh();
    $state = $config->fresh('state')->state;
    expect($trade->status)->toBe(FutureTradeLogicalTrade::STATUS_RECONCILIATION_REQUIRED)
        ->and($legacyTrade->status)->toBe('RECONCILIATION_REQUIRED')
        ->and($report['flagged'])->toBe(1)
        ->and($report['state_cleared'])->toBe(1)
        ->and((float) $state->quantity)->toEqualWithDelta(0.0, 0.000001)
        ->and($state->current_symbol)->toBeNull()
        ->and($state->current_signal)->toBe('EXCHANGE_FLAT_RECONCILE_REQUIRED');
});

test('reconciliation clears legacy-only OPEN rows after logical trade was already closed', function () {
    $config = laConfig();
    FutureTradeLogicalTrade::create([
        'future_trade_bot_config_id' => $config->id,
        'symbol' => 'BTCUSDT', 'side' => 'SHORT',
        'status' => FutureTradeLogicalTrade::STATUS_CLOSED, 'mode' => 'LIVE_TESTNET',
        'signal_key' => 'sk-rec-legacy-1',
        'entry_price' => 63943.6, 'exit_price' => 64085.5, 'quantity' => 0.002,
        'opened_at' => now()->subHour(),
        'closed_at' => now()->subMinutes(10),
    ]);
    $legacyTrade = $config->trades()->create([
        'symbol' => 'BTCUSDT',
        'side' => 'SHORT',
        'entry_price' => 63943.6,
        'quantity' => 0.002,
        'leverage' => 5,
        'reason' => 'LIVE_SELL_SIGNAL',
        'mode' => 'LIVE_TESTNET',
        'status' => 'OPEN',
        'created_at' => now()->subHour(),
        'updated_at' => now()->subHour(),
    ]);

    $client = fakeFuturesClient(
        fn ($symbol) => [['symbol' => $symbol, 'positionAmt' => '0']],
        fn ($symbol) => [[
            'symbol' => $symbol,
            'orderId' => '9002',
            'clientOrderId' => 'web-manual-close-short',
            'side' => 'BUY',
            'price' => 64085.5,
            'qty' => 0.002,
            'commission' => 0.01,
            'time' => now()->subMinutes(10)->getTimestampMs(),
        ]],
    );

    $report = app(FutureTradeReconciliationService::class)->reconcileConfig($config, $client, 'BTCUSDT');

    $legacyTrade->refresh();
    expect($legacyTrade->status)->toBe('CLOSED')
        ->and((float) $legacyTrade->exit_price)->toEqualWithDelta(64085.5, 0.0001)
        ->and($report['legacy_closed'])->toBe(1)
        ->and($report['closed'])->toBe(0);
});

// ─── Stream snapshot reacts to real wall-clock time ──────────────────────────

test('a user-stream snapshot taken right after recordEvent shows fresh=true and small ageSeconds', function () {
    $config = laConfig();
    $svc = app(FutureTradeUserStreamService::class);

    $svc->recordEvent($config, ['e' => 'ACCOUNT_UPDATE']);
    $snap = $svc->snapshot($config);
    expect($snap['fresh'])->toBeTrue()
        ->and((int) $snap['ageSeconds'])->toBeLessThanOrEqual(2);
});

test('a user-stream snapshot 1 minute after the last event reports stale', function () {
    $config = laConfig();
    $svc = app(FutureTradeUserStreamService::class);

    // Write an event timestamped well past the freshness window.
    $stateKey = sprintf(FutureTradeUserStreamService::STATE_KEY, $config->id);
    \Illuminate\Support\Facades\Cache::put($stateKey, [
        'status' => FutureTradeUserStreamService::STATUS_CONNECTED,
        'last_event_at' => now()->subSeconds(90)->toIso8601String(),
    ], 3600);

    $snap = $svc->snapshot($config);
    expect($snap['fresh'])->toBeFalse()
        ->and((int) $snap['ageSeconds'])->toBeGreaterThan(FutureTradeUserStreamService::FRESHNESS_SECONDS);
});
