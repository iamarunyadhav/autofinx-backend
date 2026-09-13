<?php

use App\Domain\Market\MarketPriceCacheService;
use App\Domain\Ops\KillSwitchService;
use App\Domain\Trading\QuickTradeService;
use App\Models\AuditDecisionSnapshot;
use App\Models\AuditEvent;
use App\Models\Integration;
use App\Models\MarketTick;
use App\Models\QuickTrade;
use App\Models\Setting;
use App\Models\Symbol;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

test('a successful paper quick trade placement records a passing audit event and decision snapshot', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();

    $quickTrade = app(QuickTradeService::class)->place([
        'workspace_id' => $workspace->id,
        'symbol' => 'GOLD_OTC',
        'side' => 'buy',
        'stake_amount' => 10,
        'timeframe' => '1m',
        'expiry_seconds' => 5,
        'mode' => 'paper',
        'indicator_set' => ['RSI'],
    ], $user->id);

    $event = AuditEvent::where('event_type', 'quick_trade_entry_gates_passed')
        ->where('bot_type', 'quick_trade')
        ->first();

    expect($event)->not->toBeNull()
        ->and($event->symbol)->toBe('GOLD_OTC');

    $snapshot = AuditDecisionSnapshot::where('correlation_id', $event->correlation_id)->first();
    expect($snapshot)->not->toBeNull()
        ->and($snapshot->final_decision)->toBe('PLACED')
        ->and($snapshot->failed_rules)->toBe([]);

    expect(QuickTrade::whereKey($quickTrade->id)->exists())->toBeTrue();
});

test('a risk-guard block on a quick trade placement records a failed-rule audit event', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();

    app(KillSwitchService::class)->activate($workspace->id, $user->id, 'test kill switch');

    $threw = false;
    try {
        app(QuickTradeService::class)->place([
            'workspace_id' => $workspace->id,
            'symbol' => 'GOLD_OTC',
            'side' => 'buy',
            'stake_amount' => 10,
            'timeframe' => '1m',
            'expiry_seconds' => 5,
            'mode' => 'paper',
            'indicator_set' => ['RSI'],
        ], $user->id);
    } catch (\Throwable) {
        $threw = true;
    }
    expect($threw)->toBeTrue();

    $event = AuditEvent::where('event_type', 'quick_trade_entry_gate_blocked')
        ->where('bot_type', 'quick_trade')
        ->first();

    expect($event)->not->toBeNull();

    $snapshot = AuditDecisionSnapshot::where('correlation_id', $event->correlation_id)->first();
    expect($snapshot)->not->toBeNull()
        ->and($snapshot->final_decision)->toBe('BLOCKED')
        ->and(collect($snapshot->failed_rules)->pluck('rule'))->toContain('risk_guard');

    expect(QuickTrade::where('workspace_id', $workspace->id)->exists())->toBeFalse();
});

test('a duplicate-live-position block on a quick trade placement records a failed-rule audit event', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $integration = Integration::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'type' => 'exchange',
        'provider_key' => 'binance',
        'display_name' => 'Binance Live',
        'status' => 'active',
        'config_json' => ['environment' => 'live'],
        'secrets_encrypted' => Crypt::encryptString(json_encode([
            'api_key' => 'test-key',
            'api_secret' => 'test-secret',
        ])),
    ]);
    foreach ([
        'trading_mode' => 'live',
        'live_enabled' => true,
        'preferred_provider' => 'binance',
        'preferred_integration_id' => $integration->id,
        'venue_environment' => 'live',
    ] as $key => $value) {
        Setting::updateOrCreate(
            ['workspace_id' => $workspace->id, 'group' => 'trading', 'key' => $key],
            ['id' => (string) Str::uuid(), 'value_json' => $value, 'encrypted' => false]
        );
    }
    app(MarketPriceCacheService::class)->setLatestPrice(
        'binance_futures',
        'ETHUSDT',
        2000.0,
        (int) floor(microtime(true) * 1000),
        ['stream' => 'ethusdt@trade', 'event_type' => 'trade', 'market_type' => 'futures'],
    );

    \Illuminate\Support\Facades\Http::fake(function (\Illuminate\Http\Client\Request $request) {
        $url = $request->url();
        if (str_contains($url, '/fapi/v2/balance')) {
            return \Illuminate\Support\Facades\Http::response([
                ['asset' => 'USDT', 'balance' => '1000.00', 'availableBalance' => '1000.00'],
            ], 200);
        }
        if (str_contains($url, '/fapi/v1/ticker/price')) {
            return \Illuminate\Support\Facades\Http::response(['symbol' => 'ETHUSDT', 'price' => '2000.00'], 200);
        }
        if (str_contains($url, '/fapi/v1/exchangeInfo')) {
            return \Illuminate\Support\Facades\Http::response([
                'symbols' => [[
                    'symbol' => 'ETHUSDT',
                    'filters' => [[
                        'filterType' => 'MARKET_LOT_SIZE',
                        'stepSize' => '0.001',
                        'minQty' => '0.001',
                    ], [
                        'filterType' => 'MIN_NOTIONAL',
                        'notional' => '20.00000000',
                    ]],
                ]],
            ], 200);
        }
        if (str_contains($url, '/fapi/v1/order') && $request->method() === 'POST') {
            return \Illuminate\Support\Facades\Http::response([
                'orderId' => '9001',
                'avgPrice' => '2000.00',
                'executedQty' => '0.010',
            ], 200);
        }

        return \Illuminate\Support\Facades\Http::response([], 200);
    });

    $payload = [
        'workspace_id' => $workspace->id,
        'symbol' => 'ETHUSDT',
        'side' => 'buy',
        'stake_amount' => 20,
        'timeframe' => '1m',
        'expiry_seconds' => 60,
        'mode' => 'live',
        'provider' => 'binance',
        'integration_id' => $integration->id,
        'indicator_set' => ['MACD'],
    ];

    app(QuickTradeService::class)->place($payload, $user->id);

    expect(fn () => app(QuickTradeService::class)->place(array_merge($payload, ['side' => 'sell']), $user->id))
        ->toThrow(\RuntimeException::class);

    $event = AuditEvent::where('event_type', 'quick_trade_entry_gate_blocked')
        ->where('bot_type', 'quick_trade')
        ->where('symbol', 'ETHUSDT')
        ->first();

    expect($event)->not->toBeNull();

    $snapshot = AuditDecisionSnapshot::where('correlation_id', $event->correlation_id)->first();
    expect($snapshot)->not->toBeNull()
        ->and(collect($snapshot->failed_rules)->pluck('rule'))->toContain('duplicate_live_position');
});

test('settling a quick trade records a linked audit event using the QuickTrade id as correlation', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-10 10:00:00'));

    $quickTrade = app(QuickTradeService::class)->place([
        'workspace_id' => $workspace->id,
        'symbol' => 'GOLD_OTC',
        'side' => 'buy',
        'stake_amount' => 10,
        'timeframe' => '1m',
        'expiry_seconds' => 5,
        'mode' => 'paper',
        'indicator_set' => ['RSI'],
    ], $user->id);

    $symbol = Symbol::where('symbol', 'GOLD_OTC')->firstOrFail();
    MarketTick::create([
        'id' => (string) Str::uuid(),
        'symbol_id' => $symbol->id,
        'price' => (float) $quickTrade->entry_price + 2,
        'bid' => (float) $quickTrade->entry_price + 1.9,
        'ask' => (float) $quickTrade->entry_price + 2.1,
        'volume' => 50,
        'occurred_at' => CarbonImmutable::parse('2026-03-10 10:00:06'),
        'metadata' => ['test' => true],
    ]);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-10 10:00:07'));

    app(QuickTradeService::class)->settleTrade($quickTrade->fresh());

    $event = AuditEvent::where('event_type', 'quick_trade_settled')
        ->where('correlation_id', $quickTrade->id)
        ->first();

    expect($event)->not->toBeNull();

    $snapshot = AuditDecisionSnapshot::where('correlation_id', $quickTrade->id)->first();
    expect($snapshot)->not->toBeNull();

    CarbonImmutable::setTestNow();
});
