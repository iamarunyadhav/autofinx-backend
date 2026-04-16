<?php

namespace App\Support;

use App\Models\Schedule;
use App\Models\ScheduleState;
use Carbon\Carbon;

class SchedulerEngine
{
    public static function evaluate(Schedule $schedule, ?ScheduleState $state = null, ?Carbon $now = null): array
    {
        $now = $now ?? FakeClock::now($schedule->workspace->timezone ?? $schedule->timezone ?? 'Asia/Colombo');
        $state = $state ?? ScheduleState::firstOrCreate(['schedule_id' => $schedule->id], ['id' => (string) \Illuminate\Support\Str::uuid()]);

        $window = $schedule->window_json ?? [];
        $intervalMs = (int) ($schedule->interval_ms ?? 60000);
        $cooldownSeconds = (int) ($schedule->cooldown_s ?? 0);
        $maxTrades = $schedule->max_trades_json ?? [];

        // window check
        $allowed = SchedulerSimulator::simulate(
            $window,
            $intervalMs,
            $schedule->workspace->timezone ?? 'Asia/Colombo',
            $cooldownSeconds,
            $maxTrades,
            $now
        );
        $nextAllowed = $allowed[0] ?? null;
        if (! $nextAllowed || Carbon::parse($nextAllowed)->ne($now)) {
            return ['should_run' => false, 'reason' => 'outside_window_or_interval'];
        }

        // cooldown check
        if ($state->cooldown_until && $now->lessThan($state->cooldown_until)) {
            return ['should_run' => false, 'reason' => 'cooldown'];
        }

        // counters
        $hourKey = $now->format('Y-m-d-H');
        $dayKey = $now->format('Y-m-d');
        $hourCount = $state->hour_key === $hourKey ? $state->hour_count : 0;
        $dayCount = $state->day_key === $dayKey ? $state->day_count : 0;

        if (($maxTrades['per_hour'] ?? null) !== null && $hourCount >= $maxTrades['per_hour']) {
            return ['should_run' => false, 'reason' => 'hour_limit'];
        }
        if (($maxTrades['per_day'] ?? null) !== null && $dayCount >= $maxTrades['per_day']) {
            return ['should_run' => false, 'reason' => 'day_limit'];
        }

        // update state
        $state->fill([
            'last_run_at' => $now,
            'cooldown_until' => $now->copy()->addSeconds($cooldownSeconds),
            'hour_key' => $hourKey,
            'day_key' => $dayKey,
            'hour_count' => $hourCount + 1,
            'day_count' => $dayCount + 1,
        ])->save();

        return ['should_run' => true, 'reason' => 'ok', 'state_id' => $state->id];
    }
}
