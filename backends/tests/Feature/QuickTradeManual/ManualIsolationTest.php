<?php

use App\Domain\QuickTradeManual\ManualPositionGuard;
use App\Domain\QuickTradeManual\ManualPositionStateMachine;
use App\Domain\Trading\QuickTradeService;
use App\Models\QuickTrade;
use App\Models\QuickTradeManualPosition as P;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\ManualTradeScenario;

function qtmActivePosition(ManualTradeScenario $s, string $status = P::OPEN_PROTECTED, string $symbol = 'BTCUSDT'): P
{
    return P::create([
        'id' => (string) Str::uuid(), 'workspace_id' => $s->workspace->id, 'integration_id' => $s->integration->id, 'idempotency_key' => 'iso-'.Str::random(10),
        'mode' => 'manual_sl_tp', 'environment' => 'testnet', 'symbol' => $symbol, 'direction' => 'long', 'entry_side' => 'buy', 'position_mode' => 'one_way',
        'status' => $status, 'active_key' => $s->workspace->id.':'.$symbol, 'stake_amount' => 100, 'leverage' => 10, 'requested_quantity' => 0.012,
        'executed_quantity' => 0.012, 'entry_price' => 80000, 'sl_pct' => 1, 'sl_trigger_price' => 79200, 'opened_at' => now()->subDays(3),
        'protection_status' => 'PROTECTED', 'sl_status' => 'WORKING', 'sl_algo_id' => '1', 'sl_client_algo_id' => 'qtm-sl-x-1',
        'created_at' => now()->subDays(3), 'updated_at' => now()->subDays(3),
    ]);
}

test('the 10 s Time-Based settler ignores manual positions however old they are: no state change, no exchange call', function () {
    $s = ManualTradeScenario::make();
    $p = qtmActivePosition($s);
    $before = $p->fresh()->toArray();

    $this->artisan('quick-trades:settle')->assertExitCode(0);
    $settled = app(QuickTradeService::class)->settleExpired(100);
    $this->postJson('/api/v1/quick-trade/settle-due', ['limit' => 50]);

    expect($settled)->toBe([])
        ->and($p->fresh()->toArray())->toBe($before)
        ->and($s->fake->calls)->toBe([]);
    Http::assertNothingSent();
});

test('the software stop-loss monitor and the time-based reconcile never see manual positions', function () {
    $s = ManualTradeScenario::make();
    $p = qtmActivePosition($s);
    (new \App\Jobs\CheckQuickTradeStopLossJob())->handle(
        app(\App\Domain\Market\MarketPriceCacheService::class),
        app(\App\Domain\Trading\LiveTimedTradeService::class),
        app(QuickTradeService::class),
        app(\App\Domain\Trading\RuntimeSafetySettingsService::class),
    );
    $this->artisan('quick-trades:reconcile')->assertExitCode(0);
    expect($p->fresh()->status)->toBe(P::OPEN_PROTECTED)->and($s->fake->calls)->toBe([]);
    Http::assertNothingSent();
});

test('manual positions are not quick_trades rows and carry an explicit mode identifier; quick_trades is unchanged', function () {
    $s = ManualTradeScenario::make();
    $p = qtmActivePosition($s);
    expect(QuickTrade::count())->toBe(0)->and($p->mode)->toBe('manual_sl_tp')->and(P::MODE)->toBe('manual_sl_tp');
    expect(Schema::hasColumn('quick_trades', 'expires_at'))->toBeTrue();      // the Time-Based table still requires expiry
});

test('Time-Based Quick Trade refuses to open on a symbol a manual position owns (one-way = same exchange position)', function () {
    $s = ManualTradeScenario::make();
    qtmActivePosition($s, P::OPEN_PROTECTED, 'ETHUSDT');
    $this->actingAs($s->user)->postJson('/api/v1/quick-trade/place', [
        'workspace_id' => $s->workspace->id, 'symbol' => 'ETHUSDT', 'side' => 'buy', 'stake_amount' => 20, 'timeframe' => '1m',
        'expiry_seconds' => 300, 'mode' => 'live', 'provider' => 'binance', 'integration_id' => $s->integration->id, 'indicator_set' => ['MACD'],
    ])->assertStatus(422)->assertJsonFragment(['message' => 'manual_sl_tp_position_open: A Manual SL/TP position is active on this symbol. Close it first or trade another symbol.']);
    expect(QuickTrade::count())->toBe(0);
});

test('the guard is inert when the table is absent (migration not run) or the position is terminal', function () {
    $s = ManualTradeScenario::make();
    $p = qtmActivePosition($s, P::CLOSED);
    $p->update(['active_key' => null]);
    expect(ManualPositionGuard::hasActivePosition($s->workspace->id, 'BTCUSDT'))->toBeFalse();
    qtmActivePosition($s);
    expect(ManualPositionGuard::hasActivePosition($s->workspace->id, 'BTCUSDT'))->toBeTrue()
        ->and(ManualPositionGuard::hasActivePosition($s->workspace->id, 'SOLUSDT'))->toBeFalse()
        ->and(ManualPositionGuard::hasActivePosition((string) Str::uuid(), 'BTCUSDT'))->toBeFalse();

    Schema::drop('quick_trade_manual_positions');
    ManualPositionGuard::forgetTableCheck();
    expect(ManualPositionGuard::hasActivePosition($s->workspace->id, 'BTCUSDT'))->toBeFalse();
});

test('Ratio Bot, Robot Trade, Future Trade, ML and the Time-Based settlers contain no reference to Manual SL/TP (they cannot open/close/reverse it)', function () {
    $roots = [
        base_path('app/Domain/RatioBot'), base_path('app/Domain/FutureTrade'), base_path('app/Domain/Ml'),
        base_path('app/Domain/Fast2X'), base_path('app/Domain/MarketIntelligence'),
    ];
    $files = [
        base_path('app/Domain/Trading/RobotSessionService.php'), base_path('app/Http/Controllers/Api/RobotSessionController.php'),
        base_path('app/Http/Controllers/Api/FutureTradeBotController.php'), base_path('app/Http/Controllers/Api/RatioBotController.php'),
        base_path('app/Jobs/CheckQuickTradeStopLossJob.php'), base_path('app/Console/Commands/SettleQuickTradesCommand.php'),
        base_path('app/Console/Commands/MonitorQuickTradeStopLossCommand.php'), base_path('app/Console/Commands/QuickTradesReconcileCommand.php'),
    ];
    foreach ($roots as $root) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $files[] = $f->getPathname();
            }
        }
    }
    $hits = [];
    foreach ($files as $file) {
        $src = (string) file_get_contents($file);
        if (stripos($src, 'QuickTradeManual') !== false || stripos($src, 'quick_trade_manual') !== false) {
            $hits[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
        }
    }
    expect($hits)->toBe([]);
});

test('every other reference to Manual SL/TP in app/ is on an allow-list (the feature itself, ONE guard in QuickTradeService, ONE additive method on BinanceFuturesClient)', function () {
    $allowed = [
        'app/Domain/QuickTradeManual/', 'app/Http/Controllers/Api/QuickTradeManualController.php', 'app/Models/QuickTradeManual',
        'app/Console/Commands/QuickTradeManualReconcileCommand.php', 'app/Domain/Trading/QuickTradeService.php',
        'app/Domain/Exchange/BinanceFuturesClient.php',   // docblock of the one additive signedApiCall() method
    ];
    $found = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'), FilesystemIterator::SKIP_DOTS)) as $f) {
        if (! $f->isFile() || $f->getExtension() !== 'php') {
            continue;
        }
        $rel = str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $f->getPathname()));
        $src = (string) file_get_contents($f->getPathname());
        if (preg_match('/QuickTradeManual|quick_trade_manual/i', $src)) {
            $ok = false;
            foreach ($allowed as $prefix) {
                $ok = $ok || str_starts_with($rel, $prefix);
            }
            if (! $ok) {
                $found[] = $rel;
            }
        }
    }
    expect($found)->toBe([]);
    $svc = (string) file_get_contents(base_path('app/Domain/Trading/QuickTradeService.php'));
    expect(substr_count($svc, 'ManualPositionGuard'))->toBe(1);
});

test('state machine: illegal transitions are refused, the CAS loser gets false and changes nothing, terminal states free the symbol slot', function () {
    $s = ManualTradeScenario::make();
    $sm = app(ManualPositionStateMachine::class);
    $p = qtmActivePosition($s, P::OPEN_PROTECTED);

    expect($sm->transition($p, P::ENTRY_FILLED, 'test'))->toBeFalse()          // not an allowed edge
        ->and($sm->transition($p, P::CLOSED, 'test'))->toBeFalse()             // must go through RECONCILIATION
        ->and($p->refresh()->status)->toBe(P::OPEN_PROTECTED);

    $stale = P::find($p->id);                                                  // two workers hold the same row
    expect($sm->transition($p, P::CLOSING, 'api'))->toBeTrue()
        ->and($sm->transition($stale, P::CLOSING, 'reconciler'))->toBeFalse()  // loser: already CLOSING in the DB
        ->and($stale->refresh()->status)->toBe(P::CLOSING);

    expect($sm->transition($p, P::RECONCILIATION, 'api'))->toBeTrue()
        ->and($p->active_key)->not->toBeNull()
        ->and($sm->transition($p, P::CLOSED, 'api'))->toBeTrue()
        ->and($p->active_key)->toBeNull()
        ->and($sm->transition($p, P::OPEN_PROTECTED, 'api'))->toBeFalse();     // terminal stays terminal
    expect(\App\Models\QuickTradeManualEvent::where('position_id', $p->id)->count())->toBe(3);
});

test('every lifecycle state from the spec exists and terminal states are exactly CLOSED, LIQUIDATED, ENTRY_FAILED', function () {
    foreach (['ENTRY_PENDING', 'ENTRY_FILLED', 'PROTECTION_PENDING', 'PROTECTION_FAILED', 'EMERGENCY_EXIT', 'OPEN_PROTECTED', 'CLOSING', 'RECONCILIATION', 'CLOSED',
        'PARTIALLY_FILLED', 'CLOSE_FAILED', 'EXCHANGE_STATE_UNKNOWN', 'LIQUIDATED'] as $state) {
        expect(defined(P::class.'::'.$state))->toBeTrue($state);
    }
    expect(P::TERMINAL)->toBe(['CLOSED', 'LIQUIDATED', 'ENTRY_FAILED']);
});
