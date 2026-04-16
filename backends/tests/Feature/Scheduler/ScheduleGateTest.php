<?php

use App\Domain\Scheduler\ScheduleGate;
use App\Models\Schedule;
use App\Models\StrategyInstance;
use App\Models\StrategyInstanceRuntime;
use App\Models\Strategy;
use App\Models\Workspace;
use App\Support\Clock\FrozenClock;
use App\Support\Clock\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeInstanceWithSchedule(array $window, array $overrides = []): StrategyInstance
{
    $workspace = Workspace::create([
        'id' => (string) Str::uuid(),
        'name' => 'Test Workspace',
        'timezone' => 'Asia/Colombo',
    ]);

    $strategy = Strategy::create([
        'id' => (string) Str::uuid(),
        'name' => 'Demo Strat',
        'slug' => (string) Str::uuid(),
    ]);

    $schedule = Schedule::create(array_merge([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'name' => 'Test Schedule',
        'window_json' => $window,
        'interval_ms' => 60000,
        'cooldown_s' => 0,
        'max_trades_json' => ['per_hour' => 5, 'per_day' => 10],
        'enabled' => true,
    ], $overrides));

    $instance = StrategyInstance::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'strategy_id' => $strategy->id,
        'name' => 'Demo Instance',
        'mode' => 'paper',
        'status' => 'running',
    ]);

    // ensure runtime exists
    StrategyInstanceRuntime::firstOrCreate(
        ['strategy_instance_id' => $instance->id],
        ['id' => (string) Str::uuid()]
    );

    return $instance;
}

function bindClock(CarbonImmutable $now)
{
    $clock = new FrozenClock($now);
    app()->instance(Clock::class, $clock);
    return $clock;
}

test('allows within window and blocks outside', function () {
    $instance = makeInstanceWithSchedule([
        'days' => ['mon','tue','wed','thu','fri'],
        'start' => '09:00',
        'end' => '17:00',
        'timezone' => 'Asia/Colombo',
    ]);

    bindClock(CarbonImmutable::parse('2026-01-05 10:00', 'Asia/Colombo'));
    $gate = app(ScheduleGate::class);
    expect($gate->canEvaluateStrategyInstance($instance)->allowed)->toBeTrue();

    bindClock(CarbonImmutable::parse('2026-01-05 08:00', 'Asia/Colombo'));
    $gate = app(ScheduleGate::class);
    expect($gate->canEvaluateStrategyInstance($instance)->allowed)->toBeFalse();
});

test('midnight crossing window works', function () {
    $instance = makeInstanceWithSchedule([
        'days' => ['mon','tue','wed','thu','fri','sat','sun'],
        'start' => '22:00',
        'end' => '02:00',
        'timezone' => 'Asia/Colombo',
    ]);

    bindClock(CarbonImmutable::parse('2026-01-05 23:30', 'Asia/Colombo'));
    $gate = app(ScheduleGate::class);
    expect($gate->canEvaluateStrategyInstance($instance)->allowed)->toBeTrue();

    bindClock(CarbonImmutable::parse('2026-01-06 01:30', 'Asia/Colombo'));
    $gate = app(ScheduleGate::class);
    expect($gate->canEvaluateStrategyInstance($instance)->allowed)->toBeTrue();
});

test('interval throttle blocks repeated evaluations', function () {
    $instance = makeInstanceWithSchedule([
        'days' => ['mon','tue','wed','thu','fri','sat','sun'],
        'start' => '00:00',
        'end' => '23:59',
        'timezone' => 'Asia/Colombo',
    ], ['interval_ms' => 60000]);

    $runtime = StrategyInstanceRuntime::where('strategy_instance_id', $instance->id)->first();
    $runtime->last_evaluated_at = CarbonImmutable::parse('2026-01-05 10:00', 'Asia/Colombo');
    $runtime->save();

    bindClock(CarbonImmutable::parse('2026-01-05 10:00:30', 'Asia/Colombo'));
    $gate = app(ScheduleGate::class);
    $result = $gate->canEvaluateStrategyInstance($instance);
    expect($result->allowed)->toBeFalse()->and($result->reason)->toBe('interval_throttle');
});

test('cooldown blocks until expiry', function () {
    $instance = makeInstanceWithSchedule([
        'days' => ['mon','tue','wed','thu','fri','sat','sun'],
        'start' => '00:00',
        'end' => '23:59',
        'timezone' => 'Asia/Colombo',
    ], ['cooldown_s' => 120]);

    $runtime = StrategyInstanceRuntime::where('strategy_instance_id', $instance->id)->first();
    $runtime->last_trade_at = CarbonImmutable::parse('2026-01-05 10:00', 'Asia/Colombo');
    $runtime->save();

    bindClock(CarbonImmutable::parse('2026-01-05 10:01', 'Asia/Colombo'));
    $gate = app(ScheduleGate::class);
    $result = $gate->canEvaluateStrategyInstance($instance);
    expect($result->allowed)->toBeFalse()->and($result->reason)->toBe('cooldown');
});

test('max trades per hour/day enforced and buckets reset at midnight', function () {
    $instance = makeInstanceWithSchedule([
        'days' => ['mon','tue','wed','thu','fri','sat','sun'],
        'start' => '00:00',
        'end' => '23:59',
        'timezone' => 'Asia/Colombo',
    ], ['max_trades_json' => ['per_hour' => 1, 'per_day' => 1]]);

    $runtime = StrategyInstanceRuntime::where('strategy_instance_id', $instance->id)->first();
    $runtime->hour_bucket = '2026-01-05 10:00';
    $runtime->day_bucket = '2026-01-05';
    $runtime->trades_in_current_hour = 1;
    $runtime->trades_in_current_day = 1;
    $runtime->save();

    bindClock(CarbonImmutable::parse('2026-01-05 10:30', 'Asia/Colombo'));
    $gate = app(ScheduleGate::class);
    $result = $gate->canEvaluateStrategyInstance($instance);
    expect($result->allowed)->toBeFalse()->and($result->reason)->toBe('max_trades_hour');

    // next day resets
    bindClock(CarbonImmutable::parse('2026-01-06 09:00', 'Asia/Colombo'));
    $gate = app(ScheduleGate::class);
    $result2 = $gate->canEvaluateStrategyInstance($instance);
    expect($result2->allowed)->toBeTrue();
});

test('next evaluation times covers DST zone safely', function () {
    $workspace = Workspace::create([
        'id' => (string) Str::uuid(),
        'name' => 'DST Workspace',
        'timezone' => 'America/New_York',
    ]);

    $schedule = Schedule::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'name' => 'DST Schedule',
        'window_json' => [
            'days' => ['mon','tue','wed','thu','fri','sat','sun'],
            'start' => '00:00',
            'end' => '23:59',
            'timezone' => 'America/New_York',
        ],
        'interval_ms' => 3600000,
        'cooldown_s' => 0,
        'max_trades_json' => [],
        'enabled' => true,
    ]);

    $gate = app(ScheduleGate::class);
    $now = CarbonImmutable::parse('2026-03-09 01:00:00', 'America/New_York');
    $times = $gate->nextEvaluationTimes($schedule, $now, 6);
    expect($times)->not()->toBeEmpty();
});
