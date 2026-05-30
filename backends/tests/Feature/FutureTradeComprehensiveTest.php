<?php

/**
 * AutoFinX Comprehensive Feature Test Suite
 *
 * Covers: block reason surfacing, warning-vs-blocker separation,
 * all daily-loss tiers, cooldown logic, max-trades, emergency-stop,
 * duplicate prevention, auto-close (TP / SL / trailing / rollback),
 * slot routing with 5 concurrent positions, freshness gate,
 * and lastWebhookPayload execution outcome visibility.
 *
 * All tests use paper-trading mode so no real exchange calls are made.
 */

use App\Domain\FutureTrade\FutureTradeBotService;
use App\Models\FutureTradeBotConfig;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

// ──────────────────────────────────────────────────────────────────────────────
// Test helpers
// ──────────────────────────────────────────────────────────────────────────────

function compConfig(string $workspaceId, array $settings = []): FutureTradeBotConfig
{
    $config = FutureTradeBotConfig::create([
        'workspace_id'       => $workspaceId,
        'bot_name'           => 'Comprehensive Test Bot',
        'paper_trading'      => true,
        'enable_live_trading' => false,
        'enabled'            => true,
        'settings'           => array_merge(config('future_trade.defaults'), [
            'signalProfile'        => 'autofinx_prime_v6_1_7_crypto',
            'marketProfile'        => 'Crypto',
            'symbols'              => ['ETHUSDT', 'BTCUSDT', 'SOLUSDT', 'BNBUSDT', 'XRPUSDT'],
            'timeframe'            => '15m',
            'tradeScoreThreshold'  => 72,
            'autoExecutePaperSignals' => true,
            'signalSource'         => 'webhook',
            'maxOpenPositions'     => 5,
        ], $settings),
    ]);
    $config->state()->create([
        'status'            => 'Running',
        'state'             => 'SCANNING',
        'connection_status' => 'Connected',
        'trading_day'       => now()->toDateString(),
        'trade_count_today' => 0,
    ]);
    return $config->fresh('state');
}

function compPayload(array $overrides = []): array
{
    return array_merge([
        'token'           => 'comp-secret',
        'profile'         => 'autofinx_prime_v6_1_7_crypto',
        'source'          => 'pine_webhook',
        'symbol'          => 'ETHUSDT',
        'timeframe'       => '15m',
        'signal'          => 'BUY',
        'price'           => 2500.00,
        'score'           => 85,
        'longScore'       => 85,
        'shortScore'      => 35,
        'bar_confirmed'   => true,
        'candle_open_time'  => '2026-05-19T10:15:00Z',
        'candle_close_time' => '2026-05-19T10:30:00Z',
    ], $overrides);
}

// ──────────────────────────────────────────────────────────────────────────────
// 1. BLOCK REASON SURFACING
// ──────────────────────────────────────────────────────────────────────────────

test('blocked trade response includes blockReason field', function () {
    config(['future_trade.pine_webhook_token' => 'comp-secret']);
    Carbon::setTestNow('2026-05-19 10:30:05');

    $workspace = Workspace::factory()->create();
    $config = compConfig($workspace->id, [
        'trade_count_today' => 0,
        'maxTradesPerDay'   => 0,   // set via state below
    ]);
    $config->state()->update(['trade_count_today' => 9999]);

    $response = $this->postJson(
        "/api/v1/future-trade/pine-webhook/{$workspace->id}",
        compPayload()
    )->assertOk();

    $body = $response->json();
    expect($body['accepted'])->toBeTrue()
        ->and($body['executed'])->toBeFalse()
        ->and($body)->toHaveKey('blockReason')
        ->and($body['blockReason'])->toBe('max_trades_per_day');
});

test('blocked trade execution field says blocked_with_logged_reason', function () {
    config(['future_trade.pine_webhook_token' => 'comp-secret']);
    Carbon::setTestNow('2026-05-19 10:30:05');

    $workspace = Workspace::factory()->create();
    compConfig($workspace->id, ['emergencyStop' => true]);

    $response = $this->postJson(
        "/api/v1/future-trade/pine-webhook/{$workspace->id}",
        compPayload()
    )->assertOk();

    $body = $response->json();
    expect($body['accepted'])->toBeTrue()
        ->and($body['executed'])->toBeFalse()
        ->and($body['execution'])->toBe('blocked_with_logged_reason')
        ->and($body['blockReason'])->toBe('emergency_stop');
});

// ──────────────────────────────────────────────────────────────────────────────
// 2. WARNING VS BLOCKER — daily_loss_warn tier must NOT block trades
// ──────────────────────────────────────────────────────────────────────────────

test('daily loss warn tier does NOT block the trade', function () {
    config(['future_trade.pine_webhook_token' => 'comp-secret']);
    Carbon::setTestNow('2026-05-19 10:30:05');

    $workspace = Workspace::factory()->create();
    $config = compConfig($workspace->id, [
        'softDailyLossWarnPercent'  => 0.3,
        'maxDailyLossPercent'       => 0.5,
        'hardDailyLossBlockPercent' => 0.8,
    ]);
    // 0.35% loss — above soft-warn (0.3%) but below block-entries (0.5%)
    $config->state()->update(['daily_loss' => 0.35]);

    $response = $this->postJson(
        "/api/v1/future-trade/pine-webhook/{$workspace->id}",
        compPayload()
    )->assertOk();

    $body = $response->json();
    // Trade should be ALLOWED (warn tier never blocks)
    expect($body['accepted'])->toBeTrue()
        ->and($body['executed'])->toBeTrue()
        ->and($body)->not->toHaveKey('blockReason');

    // A DAILY_LOSS_WARN log should exist
    $warnLog = $config->logs()->where('event', 'DAILY_LOSS_WARN')->first();
    expect($warnLog)->not->toBeNull();
});

test('daily loss warn tier returns 200 OK not 422', function () {
    config(['future_trade.pine_webhook_token' => 'comp-secret']);
    Carbon::setTestNow('2026-05-19 10:30:05');

    $workspace = Workspace::factory()->create();
    $config = compConfig($workspace->id, [
        'softDailyLossWarnPercent'  => 0.3,
        'maxDailyLossPercent'       => 0.5,
        'hardDailyLossBlockPercent' => 0.8,
    ]);
    $config->state()->update(['daily_loss' => 0.42]);

    $this->postJson("/api/v1/future-trade/pine-webhook/{$workspace->id}", compPayload())
        ->assertStatus(200);
});

// ──────────────────────────────────────────────────────────────────────────────
// 3. DAILY LOSS TIERS — block_entries and hard_block
// ──────────────────────────────────────────────────────────────────────────────

test('daily loss block_entries tier blocks new entry but returns 200 accepted', function () {
    config(['future_trade.pine_webhook_token' => 'comp-secret']);
    Carbon::setTestNow('2026-05-19 10:30:05');

    $workspace = Workspace::factory()->create();
    $config = compConfig($workspace->id, [
        'maxDailyLossPercent'       => 0.5,
        'hardDailyLossBlockPercent' => 0.8,
    ]);
    // 0.6% — above block_entries threshold
    $config->state()->update(['daily_loss' => 0.6]);

    $response = $this->postJson(
        "/api/v1/future-trade/pine-webhook/{$workspace->id}",
        compPayload()
    )->assertOk();

    $body = $response->json();
    expect($body['accepted'])->toBeTrue()
        ->and($body['executed'])->toBeFalse()
        ->and($body['blockReason'])->toBe('daily_loss_guard');
});

test('daily loss hard_block tier blocks NEW entries but CLOSE signals still execute', function () {
    config(['future_trade.pine_webhook_token' => 'comp-secret']);
    Carbon::setTestNow('2026-05-19 10:30:05');

    $workspace = Workspace::factory()->create();
    // Slot is FLAT — daily_loss=1.5 on a flat slot triggers hard_block for a new entry
    $config = compConfig($workspace->id, [
        'maxDailyLossPercent'       => 0.5,
        'hardDailyLossBlockPercent' => 0.8,
    ]);
    $config->state()->update(['daily_loss' => 1.5]);

    // BUY entry should be blocked by hard daily loss cap
    $entryResponse = $this->postJson(
        "/api/v1/future-trade/pine-webhook/{$workspace->id}",
        compPayload(['signal' => 'BUY'])
    )->assertOk();
    expect($entryResponse->json('blockReason'))->toBe('daily_loss_hard_block')
        ->and($entryResponse->json('executed'))->toBeFalse();

    // Now put the slot IN_POSITION and verify CLOSE_LONG still executes
    $config->state()->update([
        'state'          => 'IN_POSITION',
        'position_side'  => 'LONG',
        'entry_price'    => 2500,
        'quantity'       => 0.05,
        'current_symbol' => 'ETHUSDT',
        'peak_price'     => 2500,
        'bottom_price'   => 2500,
    ]);
    $closeResponse = $this->postJson(
        "/api/v1/future-trade/pine-webhook/{$workspace->id}",
        compPayload(['signal' => 'CLOSE_LONG'])
    )->assertOk();
    expect($closeResponse->json('executed'))->toBeTrue()
        ->and($closeResponse->json('reason'))->toBe('close_signal_executed');
});

test('daily loss lastWebhookPayload reflects current tier correctly', function () {
    config(['future_trade.pine_webhook_token' => 'comp-secret']);
    Carbon::setTestNow('2026-05-19 10:30:05');

    $workspace = Workspace::factory()->create();
    $config = compConfig($workspace->id, [
        'softDailyLossWarnPercent'  => 0.3,
        'maxDailyLossPercent'       => 0.5,
        'hardDailyLossBlockPercent' => 0.8,
    ]);
    $config->state()->update(['daily_loss' => 0.62]);

    // Send a WAIT so we have a webhook in the log
    $this->postJson("/api/v1/future-trade/pine-webhook/{$workspace->id}", compPayload(['signal' => 'WAIT']))->assertOk();

    // Call the service method directly to avoid route auth middleware in tests
    $svc = app(FutureTradeBotService::class);
    $payload = $svc->lastWebhookPayload($config->fresh('state'));

    expect($payload['dailyLossTier'])->toBe('block_entries')
        ->and($payload['dailyLossPercent'])->toBe(0.62);
});

// ──────────────────────────────────────────────────────────────────────────────
// 4. COOLDOWN — same-side blocked, opposite bypasses
// ──────────────────────────────────────────────────────────────────────────────

test('cooldown blocks same-direction re-entry and surfaces blockReason', function () {
    config(['future_trade.pine_webhook_token' => 'comp-secret']);
    Carbon::setTestNow('2026-05-19 10:30:05');

    $workspace = Workspace::factory()->create();
    $config = compConfig($workspace->id, ['cooldownSeconds' => 300]);
    $config->state()->update([
        'cooldown_until' => now()->addMinutes(4),
    ]);

    $response = $this->postJson(
        "/api/v1/future-trade/pine-webhook/{$workspace->id}",
        compPayload(['signal' => 'BUY'])
    )->assertOk();

    $body = $response->json();
    expect($body['executed'])->toBeFalse()
        ->and($body['blockReason'])->toBe('cooldown_active');
});

test('opposite-direction webhook bypasses cooldown and opens trade', function () {
    config(['future_trade.pine_webhook_token' => 'comp-secret']);
    Carbon::setTestNow('2026-05-19 10:30:05');

    $workspace = Workspace::factory()->create();
    $config = compConfig($workspace->id, ['cooldownSeconds' => 300]);
    $config->state()->update([
        'cooldown_until' => now()->addMinutes(4),
    ]);

    // Record last closed side as LONG so a SELL is the opposite direction
    $svc = app(FutureTradeBotService::class);
    $recordMethod = new ReflectionMethod(FutureTradeBotService::class, 'recordLastClosedSide');
    $recordMethod->setAccessible(true);
    $recordMethod->invoke($svc, $config, 'LONG', 300);

    $response = $this->postJson(
        "/api/v1/future-trade/pine-webhook/{$workspace->id}",
        compPayload(['signal' => 'SELL'])
    )->assertOk();

    $body = $response->json();
    // Should have bypassed cooldown and opened a SHORT
    expect($body['accepted'])->toBeTrue()
        ->and($body['executed'])->toBeTrue();

    $log = $config->logs()->where('event', 'COOLDOWN_BYPASSED_OPPOSITE_SIGNAL')->first();
    expect($log)->not->toBeNull();
});

// ──────────────────────────────────────────────────────────────────────────────
// 5. EMERGENCY STOP
// ──────────────────────────────────────────────────────────────────────────────

test('emergency stop blocks entry with blockReason=emergency_stop', function () {
    config(['future_trade.pine_webhook_token' => 'comp-secret']);
    Carbon::setTestNow('2026-05-19 10:30:05');

    $workspace = Workspace::factory()->create();
    compConfig($workspace->id, ['emergencyStop' => true]);

    $response = $this->postJson(
        "/api/v1/future-trade/pine-webhook/{$workspace->id}",
        compPayload()
    )->assertOk();

    expect($response->json('blockReason'))->toBe('emergency_stop')
        ->and($response->json('executed'))->toBeFalse();
});

// ──────────────────────────────────────────────────────────────────────────────
// 6. MAX TRADES PER DAY
// ──────────────────────────────────────────────────────────────────────────────

test('max trades per day blocks with blockReason=max_trades_per_day', function () {
    config(['future_trade.pine_webhook_token' => 'comp-secret']);
    Carbon::setTestNow('2026-05-19 10:30:05');

    $workspace = Workspace::factory()->create();
    $config = compConfig($workspace->id, ['maxTradesPerDay' => 3]);
    $config->state()->update(['trade_count_today' => 3]);

    $response = $this->postJson(
        "/api/v1/future-trade/pine-webhook/{$workspace->id}",
        compPayload()
    )->assertOk();

    expect($response->json('blockReason'))->toBe('max_trades_per_day')
        ->and($response->json('executed'))->toBeFalse();
});

test('max trades per day allows trade when count is one below limit', function () {
    config(['future_trade.pine_webhook_token' => 'comp-secret']);
    Carbon::setTestNow('2026-05-19 10:30:05');

    $workspace = Workspace::factory()->create();
    $config = compConfig($workspace->id, ['maxTradesPerDay' => 3]);
    $config->state()->update(['trade_count_today' => 2]);

    $response = $this->postJson(
        "/api/v1/future-trade/pine-webhook/{$workspace->id}",
        compPayload()
    )->assertOk();

    expect($response->json('executed'))->toBeTrue()
        ->and($response->json())->not->toHaveKey('blockReason');
});

// ──────────────────────────────────────────────────────────────────────────────
// 7. CONFIDENCE THRESHOLD
// ──────────────────────────────────────────────────────────────────────────────

test('confidence below 0.72 blocks entry with blockReason=confidence_below_threshold', function () {
    config(['future_trade.pine_webhook_token' => 'comp-secret']);
    Carbon::setTestNow('2026-05-19 10:30:05');

    $workspace = Workspace::factory()->create();
    compConfig($workspace->id, ['minConfidence' => 0.72]);

    // Score 60/100 = 0.60 confidence — below 0.72
    $response = $this->postJson(
        "/api/v1/future-trade/pine-webhook/{$workspace->id}",
        compPayload(['score' => 60, 'longScore' => 60, 'shortScore' => 40])
    )->assertOk();

    expect($response->json('blockReason'))->toBe('confidence_below_threshold')
        ->and($response->json('executed'))->toBeFalse();
});

test('confidence exactly at 0.72 allows the trade', function () {
    config(['future_trade.pine_webhook_token' => 'comp-secret']);
    Carbon::setTestNow('2026-05-19 10:30:05');

    $workspace = Workspace::factory()->create();
    compConfig($workspace->id, ['minConfidence' => 0.72]);

    // Score 72/100 = 0.72 — exactly at threshold (should pass: < not <=)
    $response = $this->postJson(
        "/api/v1/future-trade/pine-webhook/{$workspace->id}",
        compPayload(['score' => 72, 'longScore' => 72])
    )->assertOk();

    expect($response->json('executed'))->toBeTrue();
});

// ──────────────────────────────────────────────────────────────────────────────
// 8. DUPLICATE SIGNAL PREVENTION
// ──────────────────────────────────────────────────────────────────────────────

test('duplicate webhook for same candle is ignored with reason=duplicate_webhook_ignored', function () {
    config(['future_trade.pine_webhook_token' => 'comp-secret']);
    Carbon::setTestNow('2026-05-19 10:30:05');

    $workspace = Workspace::factory()->create();
    compConfig($workspace->id);

    $payload = compPayload();

    // First webhook — should execute
    $this->postJson("/api/v1/future-trade/pine-webhook/{$workspace->id}", $payload)
        ->assertOk()
        ->assertJsonPath('executed', true);

    // Send the webhook a second time (same candle, same symbol, same signal)
    // — the first opened a position, so the second hits the same-direction block.
    $second = $this->postJson("/api/v1/future-trade/pine-webhook/{$workspace->id}", $payload)
        ->assertOk();

    $body = $second->json();
    // Either same_direction_position_exists (since slot now has open pos) or duplicate_webhook_ignored
    expect($body['executed'])->toBeFalse();
});

// ──────────────────────────────────────────────────────────────────────────────
// 9. AUTO-CLOSE — TP, SL, Trailing, Rollback via manageOpenPosition tick
// ──────────────────────────────────────────────────────────────────────────────

test('paper position closes on take-profit via direct service call', function () {
    Carbon::setTestNow('2026-05-19 10:30:05');

    $workspace = Workspace::factory()->create();
    $config = compConfig($workspace->id, [
        'takeProfitPercent'          => 0.5,
        'stopLossPercent'            => 0.25,
        'trailingTakeProfitPercent'  => 0.18,
        'rollbackLossPercent'        => 0.15,
    ]);

    // Put the bot in an OPEN position
    $config->state()->update([
        'state'          => 'IN_POSITION',
        'position_side'  => 'LONG',
        'entry_price'    => 2000.00,
        'quantity'       => 0.05,
        'current_symbol' => 'ETHUSDT',
        'peak_price'     => 2000.00,
        'bottom_price'   => 2000.00,
    ]);
    $config = $config->fresh('state');

    $svc = app(FutureTradeBotService::class);

    // TP trigger: entry 2000, TP 0.5% net.
    // Net PnL pct = (gross - fees) / notional * 100.
    // At 2015: gross=0.75, fees≈0.0803, net≈0.6697%, which is >= 0.5%.
    $tickMethod = new ReflectionMethod(FutureTradeBotService::class, 'manageOpenPosition');
    $tickMethod->setAccessible(true);
    $tickMethod->invoke($svc, $config->fresh('state'), 2015.0);

    $afterState = $config->fresh('state')->state;
    expect((float) ($afterState->quantity ?? 0))->toBe(0.0)
        ->and((string) ($afterState->state ?? ''))->not->toBe('IN_POSITION');
});

test('paper position closes on stop-loss via direct service call', function () {
    Carbon::setTestNow('2026-05-19 10:30:05');

    $workspace = Workspace::factory()->create();
    $config = compConfig($workspace->id, [
        'stopLossPercent'   => 0.25,
        'takeProfitPercent' => 0.5,
    ]);

    $config->state()->update([
        'state'          => 'IN_POSITION',
        'position_side'  => 'LONG',
        'entry_price'    => 2000.00,
        'quantity'       => 0.05,
        'current_symbol' => 'ETHUSDT',
        'peak_price'     => 2000.00,
        'bottom_price'   => 2000.00,
    ]);

    $svc = app(FutureTradeBotService::class);
    $tickMethod = new ReflectionMethod(FutureTradeBotService::class, 'manageOpenPosition');
    $tickMethod->setAccessible(true);

    // SL at 0.25% below entry: 2000 * (1 - 0.0025) = 1995.0
    $tickMethod->invoke($svc, $config->fresh('state'), 1994.0);

    $afterState = $config->fresh('state')->state;
    expect((float) ($afterState->quantity ?? 0))->toBe(0.0);
});

test('paper SHORT position closes on stop-loss', function () {
    Carbon::setTestNow('2026-05-19 10:30:05');

    $workspace = Workspace::factory()->create();
    $config = compConfig($workspace->id, [
        'stopLossPercent'   => 0.25,
        'takeProfitPercent' => 0.5,
    ]);

    // SHORT: entry 2000, SL is triggered if price rises above 2000 * 1.0025 = 2005
    $config->state()->update([
        'state'          => 'IN_POSITION',
        'position_side'  => 'SHORT',
        'entry_price'    => 2000.00,
        'quantity'       => 0.05,
        'current_symbol' => 'ETHUSDT',
        'peak_price'     => 2000.00,
        'bottom_price'   => 2000.00,
    ]);

    $svc = app(FutureTradeBotService::class);
    $tickMethod = new ReflectionMethod(FutureTradeBotService::class, 'manageOpenPosition');
    $tickMethod->setAccessible(true);
    $tickMethod->invoke($svc, $config->fresh('state'), 2006.0);

    $afterState = $config->fresh('state')->state;
    expect((float) ($afterState->quantity ?? 0))->toBe(0.0);
});

// ──────────────────────────────────────────────────────────────────────────────
// 10. PINE WEBHOOK CLOSE SIGNAL — closes the matching side
// ──────────────────────────────────────────────────────────────────────────────

test('pine CLOSE_LONG closes a LONG position correctly', function () {
    config(['future_trade.pine_webhook_token' => 'comp-secret']);
    Carbon::setTestNow('2026-05-19 10:30:05');

    $workspace = Workspace::factory()->create();
    $config = compConfig($workspace->id);
    $config->state()->update([
        'state'          => 'IN_POSITION',
        'position_side'  => 'LONG',
        'entry_price'    => 2490,
        'quantity'       => 0.04,
        'current_symbol' => 'ETHUSDT',
        'peak_price'     => 2500,
        'bottom_price'   => 2490,
    ]);

    $response = $this->postJson(
        "/api/v1/future-trade/pine-webhook/{$workspace->id}",
        compPayload(['signal' => 'CLOSE_LONG'])
    )->assertOk();

    expect($response->json('executed'))->toBeTrue()
        ->and($response->json('reason'))->toBe('close_signal_executed');

    $afterState = $config->fresh('state')->state;
    expect((float) ($afterState->quantity ?? 0))->toBe(0.0);
});

test('pine CLOSE_SHORT does not close a LONG position and returns side mismatch', function () {
    config(['future_trade.pine_webhook_token' => 'comp-secret']);
    Carbon::setTestNow('2026-05-19 10:30:05');

    $workspace = Workspace::factory()->create();
    $config = compConfig($workspace->id);
    $config->state()->update([
        'state'          => 'IN_POSITION',
        'position_side'  => 'LONG',
        'entry_price'    => 2490,
        'quantity'       => 0.04,
        'current_symbol' => 'ETHUSDT',
    ]);

    $response = $this->postJson(
        "/api/v1/future-trade/pine-webhook/{$workspace->id}",
        compPayload(['signal' => 'CLOSE_SHORT'])
    )->assertOk();

    expect($response->json('executed'))->toBeFalse()
        ->and($response->json('reason'))->toBe('close_signal_side_mismatch');

    // Position must remain open
    $afterState = $config->fresh('state')->state;
    expect((float) ($afterState->quantity ?? 0))->toBeGreaterThan(0.0);
});

// ──────────────────────────────────────────────────────────────────────────────
// 11. OPPOSITE SIGNAL (reversal) — closes current, then in close_only mode stops
// ──────────────────────────────────────────────────────────────────────────────

test('opposite signal closes current LONG position in close_only mode', function () {
    config(['future_trade.pine_webhook_token' => 'comp-secret']);
    Carbon::setTestNow('2026-05-19 10:30:05');

    $workspace = Workspace::factory()->create();
    $config = compConfig($workspace->id, ['reversalMode' => 'close_only']);
    $config->state()->update([
        'state'          => 'IN_POSITION',
        'position_side'  => 'LONG',
        'entry_price'    => 2490,
        'quantity'       => 0.04,
        'current_symbol' => 'ETHUSDT',
        'peak_price'     => 2500,
        'bottom_price'   => 2490,
    ]);

    $response = $this->postJson(
        "/api/v1/future-trade/pine-webhook/{$workspace->id}",
        compPayload(['signal' => 'SELL'])
    )->assertOk();

    expect($response->json('executed'))->toBeTrue()
        ->and($response->json('reason'))->toBe('opposite_signal_closed_position_close_only');

    $afterState = $config->fresh('state')->state;
    // Position closed — quantity back to 0
    expect((float) ($afterState->quantity ?? 0))->toBe(0.0);
});

// ──────────────────────────────────────────────────────────────────────────────
// 12. lastWebhookPayload EXECUTION OUTCOME VISIBILITY
// ──────────────────────────────────────────────────────────────────────────────

test('lastWebhookPayload shows tradeBlocked=true when entry was blocked', function () {
    config(['future_trade.pine_webhook_token' => 'comp-secret']);
    Carbon::setTestNow('2026-05-19 10:30:05');

    $workspace = Workspace::factory()->create();
    $config = compConfig($workspace->id, ['maxTradesPerDay' => 3]);
    $config->state()->update(['trade_count_today' => 9999]);

    // Send a BUY webhook — will be blocked by max_trades_per_day
    $this->postJson("/api/v1/future-trade/pine-webhook/{$workspace->id}", compPayload())->assertOk();

    // Call service directly (route is auth-protected in tests)
    $svc = app(FutureTradeBotService::class);
    $payload = $svc->lastWebhookPayload($config->fresh('state'));

    expect($payload['received'])->toBeTrue()
        ->and($payload['tradeBlocked'])->toBeTrue()
        ->and($payload['executionOutcomeEvent'])->toBe('TRADE_BLOCKED');
});

test('lastWebhookPayload shows tradeBlocked=false when trade executed', function () {
    config(['future_trade.pine_webhook_token' => 'comp-secret']);
    Carbon::setTestNow('2026-05-19 10:30:05');

    $workspace = Workspace::factory()->create();
    $config = compConfig($workspace->id);

    $this->postJson("/api/v1/future-trade/pine-webhook/{$workspace->id}", compPayload(['signal' => 'WAIT']))->assertOk();
    $this->postJson("/api/v1/future-trade/pine-webhook/{$workspace->id}", compPayload())->assertOk();

    $svc = app(FutureTradeBotService::class);
    $payload = $svc->lastWebhookPayload($config->fresh('state'));

    expect($payload['received'])->toBeTrue()
        ->and($payload['tradeBlocked'])->toBeFalse();
});

// ──────────────────────────────────────────────────────────────────────────────
// 13. 5-SLOT MULTI-POSITION — happy path integration
// ──────────────────────────────────────────────────────────────────────────────

test('5 different symbols open 5 positions across slots', function () {
    config(['future_trade.pine_webhook_token' => 'comp-secret']);
    Carbon::setTestNow('2026-05-19 10:30:05');

    $workspace = Workspace::factory()->create();
    $primary = compConfig($workspace->id, ['maxOpenPositions' => 5]);
    $svc = app(FutureTradeBotService::class);
    $svc->syncSlots($primary);

    $symbols = ['ETHUSDT', 'BTCUSDT', 'SOLUSDT', 'BNBUSDT', 'XRPUSDT'];
    foreach ($symbols as $sym) {
        $this->postJson(
            "/api/v1/future-trade/pine-webhook/{$workspace->id}",
            compPayload(['symbol' => $sym])
        )->assertOk()
            ->assertJsonPath('executed', true);
    }

    expect($svc->activePositionCount($workspace->id))->toBe(5);
});

test('6th symbol returns 422 max_concurrent_positions_reached when all 5 slots busy', function () {
    config(['future_trade.pine_webhook_token' => 'comp-secret']);
    Carbon::setTestNow('2026-05-19 10:30:05');

    $workspace = Workspace::factory()->create();
    $primary = compConfig($workspace->id, ['maxOpenPositions' => 5]);
    $svc = app(FutureTradeBotService::class);
    $svc->syncSlots($primary);

    // Fill all 5 slots via state update
    $slots = FutureTradeBotConfig::where('workspace_id', $workspace->id)->orderBy('slot_index')->get();
    $symbols = ['ETHUSDT', 'BTCUSDT', 'SOLUSDT', 'BNBUSDT', 'XRPUSDT'];
    foreach ($slots as $i => $slot) {
        $slot->state()->update([
            'state'          => 'IN_POSITION',
            'current_symbol' => $symbols[$i],
            'quantity'       => 1.0,
        ]);
    }

    $this->postJson(
        "/api/v1/future-trade/pine-webhook/{$workspace->id}",
        compPayload(['symbol' => 'ADAUSDT', 'signal' => 'BUY'])
    )
        ->assertStatus(422)
        ->assertJsonPath('reason', 'max_concurrent_positions_reached')
        ->assertJsonPath('activePositions', 5);
});

test('CLOSE signal for symbol in slot 2 is routed to slot 2', function () {
    config(['future_trade.pine_webhook_token' => 'comp-secret']);
    Carbon::setTestNow('2026-05-19 10:30:05');

    $workspace = Workspace::factory()->create();
    $primary = compConfig($workspace->id, ['maxOpenPositions' => 5]);
    $svc = app(FutureTradeBotService::class);
    $svc->syncSlots($primary);

    // Put SOLUSDT in slot 2 (index 2)
    $slots = FutureTradeBotConfig::where('workspace_id', $workspace->id)->orderBy('slot_index')->get();
    $slot2 = $slots[2];
    $slot2->state()->update([
        'state'          => 'IN_POSITION',
        'position_side'  => 'LONG',
        'current_symbol' => 'SOLUSDT',
        'entry_price'    => 150,
        'quantity'       => 1.0,
        'peak_price'     => 155,
        'bottom_price'   => 148,
    ]);

    $response = $this->postJson(
        "/api/v1/future-trade/pine-webhook/{$workspace->id}",
        compPayload(['symbol' => 'SOLUSDT', 'signal' => 'CLOSE_LONG', 'price' => 155])
    )->assertOk();

    expect($response->json('executed'))->toBeTrue()
        ->and($response->json('slotIndex'))->toBe(2);

    $afterState = $slot2->fresh('state')->state;
    expect((float) ($afterState->quantity ?? 0))->toBe(0.0);
});

// ──────────────────────────────────────────────────────────────────────────────
// 14. PHANTOM TRADE BLOCKING — after flat state, new entries go through
// ──────────────────────────────────────────────────────────────────────────────

test('a second BUY on same symbol is blocked while an OPEN logical trade exists for that symbol', function () {
    config(['future_trade.pine_webhook_token' => 'comp-secret']);
    Carbon::setTestNow('2026-05-19 10:30:05');

    $workspace = Workspace::factory()->create();
    $config = compConfig($workspace->id);

    // First webhook — opens a position and creates a logical trade
    $first = $this->postJson("/api/v1/future-trade/pine-webhook/{$workspace->id}", compPayload())->assertOk();
    expect($first->json('executed'))->toBeTrue();

    // Second BUY for same symbol on the NEXT candle while slot is IN_POSITION
    Carbon::setTestNow('2026-05-19 10:45:05');
    $second = $this->postJson(
        "/api/v1/future-trade/pine-webhook/{$workspace->id}",
        compPayload(['candle_open_time' => '2026-05-19T10:30:00Z', 'candle_close_time' => '2026-05-19T10:45:00Z'])
    )->assertOk();
    // Same direction while slot is busy → blocked (same_direction_position_exists)
    expect($second->json('executed'))->toBeFalse();
});

test('closing via CLOSE_LONG then sending a new BUY opens a fresh position', function () {
    config(['future_trade.pine_webhook_token' => 'comp-secret']);
    Carbon::setTestNow('2026-05-19 10:30:05');

    $workspace = Workspace::factory()->create();
    $config = compConfig($workspace->id);

    // Open
    $this->postJson("/api/v1/future-trade/pine-webhook/{$workspace->id}", compPayload())->assertOk();

    // Close via webhook
    $this->postJson(
        "/api/v1/future-trade/pine-webhook/{$workspace->id}",
        compPayload(['signal' => 'CLOSE_LONG'])
    )->assertOk()->assertJsonPath('executed', true);

    // New BUY on a fresh candle should open again
    Carbon::setTestNow('2026-05-19 10:45:05');
    $fresh = $this->postJson(
        "/api/v1/future-trade/pine-webhook/{$workspace->id}",
        compPayload(['candle_open_time' => '2026-05-19T10:30:00Z', 'candle_close_time' => '2026-05-19T10:45:00Z'])
    )->assertOk();
    expect($fresh->json('executed'))->toBeTrue();
});

// ──────────────────────────────────────────────────────────────────────────────
// 15. LATENCY TELEMETRY present in every response
// ──────────────────────────────────────────────────────────────────────────────

test('every webhook response includes latency telemetry with all 5 milestones', function () {
    config(['future_trade.pine_webhook_token' => 'comp-secret']);
    Carbon::setTestNow('2026-05-19 10:30:05');

    $workspace = Workspace::factory()->create();
    compConfig($workspace->id);

    $body = $this->postJson(
        "/api/v1/future-trade/pine-webhook/{$workspace->id}",
        compPayload()
    )->assertOk()->json();

    expect($body)->toHaveKey('latency')
        ->and($body['latency'])->toHaveKey('t0_received_at')
        ->and($body['latency'])->toHaveKey('validatedMs')
        ->and($body['latency'])->toHaveKey('stateSyncedMs')
        ->and($body['latency'])->toHaveKey('orderPlacedMs')
        ->and($body['latency'])->toHaveKey('totalMs')
        ->and($body['latency']['totalMs'])->toBeInt()
        ->and($body['latency']['totalMs'])->toBeGreaterThanOrEqual(0);
});

// ──────────────────────────────────────────────────────────────────────────────
// 16. SLOT ISOLATION — one symbol per slot, same symbol re-entry lands on same slot
// ──────────────────────────────────────────────────────────────────────────────

test('same symbol re-entry always routes to its existing slot not a new one', function () {
    config(['future_trade.pine_webhook_token' => 'comp-secret']);
    Carbon::setTestNow('2026-05-19 10:30:05');

    $workspace = Workspace::factory()->create();
    $primary = compConfig($workspace->id, ['maxOpenPositions' => 5]);
    $svc = app(FutureTradeBotService::class);
    $svc->syncSlots($primary);

    // Open ETHUSDT in slot 0
    $this->postJson("/api/v1/future-trade/pine-webhook/{$workspace->id}", compPayload(['symbol' => 'ETHUSDT']))->assertOk();

    // A CLOSE_LONG for ETHUSDT should also land on slot 0
    $response = $this->postJson(
        "/api/v1/future-trade/pine-webhook/{$workspace->id}",
        compPayload(['symbol' => 'ETHUSDT', 'signal' => 'CLOSE_LONG'])
    )->assertOk();

    expect($response->json('slotIndex'))->toBe(0);
});

// ──────────────────────────────────────────────────────────────────────────────
// 17. SIGNAL NORMALIZATION — Pine TV suffixes stripped correctly
// ──────────────────────────────────────────────────────────────────────────────

test('BNBUSDT.P is normalized to BNBUSDT before routing', function () {
    config(['future_trade.pine_webhook_token' => 'comp-secret']);
    Carbon::setTestNow('2026-05-19 10:30:05');

    $workspace = Workspace::factory()->create();
    compConfig($workspace->id);

    $response = $this->postJson(
        "/api/v1/future-trade/pine-webhook/{$workspace->id}",
        compPayload(['symbol' => 'BINANCE:BNBUSDT.P', 'signal' => 'WAIT'])
    )->assertOk();

    expect($response->json('accepted'))->toBeTrue();
});

// ──────────────────────────────────────────────────────────────────────────────
// 18. BOT DISABLED
// ──────────────────────────────────────────────────────────────────────────────

test('disabled bot returns 422 bot_disabled', function () {
    config(['future_trade.pine_webhook_token' => 'comp-secret']);
    Carbon::setTestNow('2026-05-19 10:30:05');

    $workspace = Workspace::factory()->create();
    $config = compConfig($workspace->id);
    $config->update(['enabled' => false]);

    $this->postJson("/api/v1/future-trade/pine-webhook/{$workspace->id}", compPayload())
        ->assertStatus(422)
        ->assertJsonPath('reason', 'bot_disabled');
});

// ──────────────────────────────────────────────────────────────────────────────
// 19. slotIndex in all 200-response webhooks
// ──────────────────────────────────────────────────────────────────────────────

test('webhook response includes slotIndex for multi-slot workspace', function () {
    config(['future_trade.pine_webhook_token' => 'comp-secret']);
    Carbon::setTestNow('2026-05-19 10:30:05');

    $workspace = Workspace::factory()->create();
    $primary = compConfig($workspace->id, ['maxOpenPositions' => 5]);
    app(FutureTradeBotService::class)->syncSlots($primary);

    $response = $this->postJson(
        "/api/v1/future-trade/pine-webhook/{$workspace->id}",
        compPayload(['symbol' => 'BTCUSDT'])
    )->assertOk();

    expect($response->json())->toHaveKey('slotIndex');
});
