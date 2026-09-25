<?php

use App\Domain\Trading\RobotSessionService;
use App\Domain\Trading\TradingModuleSettingsService;
use App\Models\Integration;
use App\Models\Setting;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Default chart timeframe / expiry for NEW robot sessions whose caller omits
 * them. LIVE: `robot_trade.default_live_timeframe`/`default_live_duration`
 * (5m / 3600s) when the provider's live catalog offers both. PAPER: the
 * historical 1m / Quick Trade default_duration. Existing sessions keep their
 * stored settings. Helpers are prefixed `rsd`.
 */
function rsdWorkspace(bool $live): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    \App\Models\WorkspaceMember::create([
        'id' => (string) Str::uuid(), 'workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => 'owner',
    ]);
    if (! $live) {
        return [$user, $workspace];
    }

    $integration = Integration::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'type' => 'exchange',
        'provider_key' => 'binance',
        'display_name' => 'Binance Live',
        'status' => 'active',
        'config_json' => ['environment' => 'live'],
        'secrets_encrypted' => Crypt::encryptString(json_encode(['api_key' => 'k', 'api_secret' => 's'])),
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

    return [$user, $workspace];
}

function rsdCreate(Workspace $workspace, User $user, array $payload = []): \App\Models\RobotSession
{
    return app(RobotSessionService::class)->create($workspace->id, array_merge([
        'symbol' => 'BTCUSDT', 'base_stake' => 20, 'provider' => 'binance',
    ], $payload), $user->id);
}

test('new LIVE sessions that omit timeframe/expiry default to 5m / 3600s', function () {
    [$user, $workspace] = rsdWorkspace(true);

    $session = rsdCreate($workspace, $user);

    expect($session->mode)->toBe('live')
        ->and($session->timeframe)->toBe('5m')
        ->and((int) $session->expiry_seconds)->toBe(3600);
});

test('new PAPER sessions keep the historical 1m / 60s default', function () {
    [$user, $workspace] = rsdWorkspace(false);

    $session = rsdCreate($workspace, $user);

    expect($session->mode)->toBe('paper')
        ->and($session->timeframe)->toBe('1m')
        ->and((int) $session->expiry_seconds)->toBe(60);
});

test('an explicit timeframe/expiry always wins over the live default', function () {
    [$user, $workspace] = rsdWorkspace(true);

    $session = rsdCreate($workspace, $user, ['timeframe' => '1m', 'expiry_seconds' => 300]);

    expect($session->timeframe)->toBe('1m')->and((int) $session->expiry_seconds)->toBe(300);
});

test('config-only rollback: ROBOT_TRADE_DEFAULT_LIVE_* set to 1m / 60 restores the old live default', function () {
    config()->set('robot_trade.default_live_timeframe', '1m');
    config()->set('robot_trade.default_live_duration', 60);
    [$user, $workspace] = rsdWorkspace(true);

    $session = rsdCreate($workspace, $user);

    expect($session->timeframe)->toBe('1m')->and((int) $session->expiry_seconds)->toBe(60);
});

test('a robot_trade settings row overrides the live default for one workspace', function () {
    [$user, $workspace] = rsdWorkspace(true);
    Setting::create([
        'id' => (string) Str::uuid(), 'workspace_id' => $workspace->id, 'group' => 'robot_trade',
        'key' => 'default_live_duration', 'value_json' => 900, 'encrypted' => false,
    ]);

    $session = rsdCreate($workspace, $user);

    expect($session->timeframe)->toBe('5m')->and((int) $session->expiry_seconds)->toBe(900);
});

test('a live default the provider catalog does not offer, or a mistyped timeframe, falls back instead of failing', function () {
    [$user, $workspace] = rsdWorkspace(true);

    config()->set('robot_trade.default_live_duration', 7200); // not in Binance's live durations
    $unsupported = rsdCreate($workspace, $user);
    config()->set('robot_trade.default_live_duration', 3600);
    config()->set('robot_trade.default_live_timeframe', '5min'); // not a known timeframe
    $mistyped = rsdCreate($workspace, $user);

    expect($unsupported->timeframe)->toBe('1m')->and((int) $unsupported->expiry_seconds)->toBe(60)
        ->and($mistyped->timeframe)->toBe('1m')->and((int) $mistyped->expiry_seconds)->toBe(60);
});

test('existing sessions keep their stored timeframe/expiry when other fields are updated', function () {
    [$user, $workspace] = rsdWorkspace(true);
    $session = rsdCreate($workspace, $user, ['timeframe' => '1m', 'expiry_seconds' => 60]);

    $updated = app(RobotSessionService::class)->update($session, ['name' => 'renamed', 'confidence_threshold' => 65]);

    expect($updated->timeframe)->toBe('1m')->and((int) $updated->expiry_seconds)->toBe(60);
});

test('metadata exposes the live defaults to the Robot page', function () {
    $robot = app(TradingModuleSettingsService::class)->metadata(null)['robot_trade'];

    expect($robot['default_live_timeframe'])->toBe('5m')
        ->and($robot['default_live_duration'])->toBe(3600);
});

test('the live default is a pair Binance live Robot Trade accepts end to end', function () {
    $catalog = app(\App\Domain\Trading\Providers\BinanceTimedFuturesProvider::class)->capabilities();

    // Provider catalog (what QuickTradeService::place() enforces for live)...
    expect($catalog['timeframes'])->toContain('5m')
        ->and($catalog['durations'])->toContain(3600);
    // ...and RobotSessionController's own `expiry_seconds` cap (max:3600).
    [$user, $workspace] = rsdWorkspace(true);
    $this->actingAs($user)->postJson("/api/v1/workspaces/{$workspace->id}/robot-sessions", [
        'name' => 'defaults', 'symbol' => 'BTCUSDT', 'timeframe' => '5m', 'expiry_seconds' => 3600,
        'base_stake' => 20, 'provider' => 'binance',
    ])->assertSuccessful();
});
