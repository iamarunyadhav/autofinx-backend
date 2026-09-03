<?php

namespace App\Domain\Scheduler;

use App\Models\Schedule;
use App\Models\StrategyInstance;
use App\Models\StrategyInstanceRuntime;
use App\Support\Clock\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class ScheduleGate
{
    public function __construct(private Clock $clock)
    {
    }

    public function canEvaluateStrategyInstance(StrategyInstance $instance, ?CarbonImmutable $now = null): GateResult
    {
        $schedule = $this->resolveSchedule($instance);
        $clock = app(Clock::class);
        $now = ($now ?? $clock->now())->setTimezone($this->timezoneFor($schedule));

        [$windowOk, $windowReason] = $this->checkWindow($schedule, $now);
        if (! $windowOk) {
            return new GateResult(false, $windowReason);
        }

        $runtime = StrategyInstanceRuntime::firstOrCreate(
            ['strategy_instance_id' => $instance->id],
            ['id' => (string) Str::uuid()]
        );

        $this->resetBucketsIfNeeded($runtime, $now, $this->timezoneFor($schedule));

        $tz = $this->timezoneFor($schedule);
        $lastEvaluated = $this->runtimeTimestamp($runtime, 'last_evaluated_at', $tz);
        $lastTrade = $this->runtimeTimestamp($runtime, 'last_trade_at', $tz);

        if ($lastEvaluated) {
            $diffMs = $lastEvaluated->lessThanOrEqualTo($now)
                ? $lastEvaluated->diffInMilliseconds($now, true)
                : 0;
            if ($diffMs < ($schedule->interval_ms ?? 60000)) {
                $next = $lastEvaluated->addMilliseconds($schedule->interval_ms ?? 60000);
                return new GateResult(false, 'interval_throttle', $next);
            }
        }

        if ($lastTrade && ($schedule->cooldown_s ?? 0) > 0) {
            $diff = $lastTrade->lessThanOrEqualTo($now)
                ? $lastTrade->diffInSeconds($now, true)
                : 0;
            if ($diff < ($schedule->cooldown_s ?? 0)) {
                $next = $lastTrade->addSeconds($schedule->cooldown_s ?? 0);
                return new GateResult(false, 'cooldown', $next);
            }
        }

        // max trades
        $max = $schedule->max_trades_json ?? [];
        if (!empty($max['per_hour']) && $runtime->trades_in_current_hour >= (int) $max['per_hour']) {
            $nextHour = CarbonImmutable::createFromFormat('Y-m-d H:i', $runtime->hour_bucket ?? $now->format('Y-m-d H:00'), $this->timezoneFor($schedule))->addHour()->startOfHour();
            return new GateResult(false, 'max_trades_hour', $nextHour);
        }
        if (!empty($max['per_day']) && $runtime->trades_in_current_day >= (int) $max['per_day']) {
            $nextDay = CarbonImmutable::parse($runtime->day_bucket, $this->timezoneFor($schedule))->addDay()->startOfDay();
            return new GateResult(false, 'max_trades_day', $nextDay);
        }

        // touch last evaluated
        $runtime->last_evaluated_at = $now;
        $runtime->save();

        return new GateResult(true, 'allowed');
    }

    public function nextEvaluationTimes(Schedule $schedule, CarbonImmutable $now, int $hours = 24): array
    {
        $tz = $this->timezoneFor($schedule);
        $cursor = $now->setTimezone($tz);
        $end = $cursor->addHours($hours);
        $intervalSeconds = max(1, (int) ceil(($schedule->interval_ms ?? 60000) / 1000));
        $times = [];

        while ($cursor->lt($end)) {
            if ($this->checkWindow($schedule, $cursor)[0]) {
                $times[] = $cursor->toIso8601String();
            }
            $cursor = $cursor->addSeconds($intervalSeconds);
        }

        return $times;
    }

    private function resolveSchedule(StrategyInstance $instance): Schedule
    {
        $schedule = Schedule::where('workspace_id', $instance->workspace_id)
            ->where('enabled', true)
            ->first();

        if (! $schedule) {
            $schedule = new Schedule([
                'window_json' => [
                    'days' => ['mon','tue','wed','thu','fri','sat','sun'],
                    'start' => '00:00',
                    'end' => '23:59',
                    'timezone' => config('app.timezone', 'Asia/Colombo'),
                ],
                'interval_ms' => env('TRADING_LOOP_INTERVAL_SECONDS', 300) * 1000,
                'cooldown_s' => 0,
                'max_trades_json' => [],
            ]);
        }

        return $schedule;
    }

    private function timezoneFor(Schedule $schedule): string
    {
        return $schedule->window_json['timezone']
            ?? env('SCHEDULER_DEFAULT_TIMEZONE', 'Asia/Colombo');
    }

    private function checkWindow(Schedule $schedule, CarbonImmutable $now): array
    {
        $window = $schedule->window_json ?? null;
        if (!$window) {
            return [true, 'no_window'];
        }

        // support array of windows
        $windows = isset($window[0]) ? $window : [$window];
        foreach ($windows as $win) {
            $days = $win['days'] ?? ['mon','tue','wed','thu','fri','sat','sun'];
            $start = $win['start'] ?? '00:00';
            $end = $win['end'] ?? '23:59';

            $dayKey = strtolower($now->format('D'));
            if (!in_array($dayKey, array_map('strtolower', $days))) {
                continue;
            }

            $startToday = CarbonImmutable::parse($now->format('Y-m-d') . ' ' . $start, $now->timezone);
            $endToday = CarbonImmutable::parse($now->format('Y-m-d') . ' ' . $end, $now->timezone);

            // midnight crossing
            if ($endToday->lessThanOrEqualTo($startToday)) {
                $endToday = $endToday->addDay();
                if ($now->lt($startToday)) {
                    $startToday = $startToday->subDay();
                }
            }

            if ($now->betweenIncluded($startToday, $endToday)) {
                return [true, 'in_window'];
            }
        }

        return [false, 'out_of_window'];
    }

    private function resetBucketsIfNeeded(StrategyInstanceRuntime $runtime, CarbonImmutable $now, string $tz): void
    {
        $currentHour = $now->setTimezone($tz)->format('Y-m-d H:00');
        $currentDay = $now->setTimezone($tz)->format('Y-m-d');

        $dirty = false;
        if ($runtime->hour_bucket !== $currentHour) {
            $runtime->hour_bucket = $currentHour;
            $runtime->trades_in_current_hour = 0;
            $dirty = true;
        }

        if ($runtime->day_bucket !== $currentDay) {
            $runtime->day_bucket = $currentDay;
            $runtime->trades_in_current_day = 0;
            $dirty = true;
        }

        if ($dirty) {
            $runtime->save();
        }
    }

    private function runtimeTimestamp(StrategyInstanceRuntime $runtime, string $field, string $tz): ?CarbonImmutable
    {
        $raw = $runtime->getRawOriginal($field);
        if (is_string($raw) && trim($raw) !== '') {
            return CarbonImmutable::parse($raw, $tz);
        }

        $value = $runtime->{$field};

        return $value ? $value->toImmutable()->setTimezone($tz) : null;
    }
}
