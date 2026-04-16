<?php

namespace App\Support;

use Carbon\Carbon;

class SchedulerSimulator
{
    /**
        * Simulate next 24h execution timestamps respecting window, interval, cooldown, and max trades.
        *
        * @param array $window ['days'=>['mon'...], 'start'=>'HH:MM', 'end'=>'HH:MM']
        * @param int $intervalMs
        * @param string $timezone
        * @param int $cooldownSeconds
        * @param array $maxTrades ['per_hour'=>int, 'per_day'=>int]
        * @param Carbon|null $start
        * @return array
        */
    public static function simulate(
        array $window,
        int $intervalMs,
        string $timezone = 'Asia/Colombo',
        int $cooldownSeconds = 0,
        array $maxTrades = [],
        ?Carbon $start = null
    ): array {
        $start = $start?->copy()->setTimezone($timezone) ?? Carbon::now($timezone);
        $end = $start->copy()->addDay();

        $intervalSeconds = max(1, (int) ($intervalMs / 1000));
        $cooldownSeconds = max(0, $cooldownSeconds);

        $allowedDays = self::normalizeDays($window['days'] ?? []);
        $startTime = $window['start'] ?? '00:00';
        $endTime = $window['end'] ?? '23:59';

        $perHourLimit = $maxTrades['per_hour'] ?? null;
        $perDayLimit = $maxTrades['per_day'] ?? null;
        $perHourCount = 0;
        $perDayCount = 0;
        $currentHour = $start->format('Y-m-d-H');
        $currentDay = $start->format('Y-m-d');

        $timestamps = [];
        $cursor = $start->copy();
        $nextAllowedTime = $cursor->copy();

        while ($cursor->lessThan($end)) {
            // reset counters on hour/day boundaries
            if ($cursor->format('Y-m-d-H') !== $currentHour) {
                $currentHour = $cursor->format('Y-m-d-H');
                $perHourCount = 0;
            }
            if ($cursor->format('Y-m-d') !== $currentDay) {
                $currentDay = $cursor->format('Y-m-d');
                $perDayCount = 0;
            }

            if ($cursor->greaterThanOrEqualTo($nextAllowedTime)
                && self::withinWindow($cursor, $allowedDays, $startTime, $endTime)) {
                if (($perHourLimit === null || $perHourCount < $perHourLimit)
                    && ($perDayLimit === null || $perDayCount < $perDayLimit)) {
                    $timestamps[] = $cursor->toIso8601String();
                    $perHourCount++;
                    $perDayCount++;
                    $nextAllowedTime = $cursor->copy()->addSeconds($cooldownSeconds);
                }
            }

            $cursor->addSeconds($intervalSeconds);
        }

        return $timestamps;
    }

    private static function withinWindow(Carbon $time, array $allowedDays, string $start, string $end): bool
    {
        $dayKey = strtolower($time->format('D')); // mon..sun
        if (! in_array($dayKey, $allowedDays, true)) {
            return false;
        }

        [$startH, $startM] = array_map('intval', explode(':', $start));
        [$endH, $endM] = array_map('intval', explode(':', $end));

        $startMinutes = $startH * 60 + $startM;
        $endMinutes = $endH * 60 + $endM;
        $currentMinutes = (int) $time->format('G') * 60 + (int) $time->format('i');

        if ($startMinutes <= $endMinutes) {
            return $currentMinutes >= $startMinutes && $currentMinutes <= $endMinutes;
        }

        // Window crosses midnight: valid if after start OR before end
        return $currentMinutes >= $startMinutes || $currentMinutes <= $endMinutes;
    }

    private static function normalizeDays(array $days): array
    {
        $map = [
            'mon' => 'mon', 'monday' => 'mon',
            'tue' => 'tue', 'tuesday' => 'tue',
            'wed' => 'wed', 'wednesday' => 'wed',
            'thu' => 'thu', 'thursday' => 'thu',
            'fri' => 'fri', 'friday' => 'fri',
            'sat' => 'sat', 'saturday' => 'sat',
            'sun' => 'sun', 'sunday' => 'sun',
        ];
        return array_values(array_unique(array_map(function ($d) use ($map) {
            $key = strtolower($d);
            return $map[$key] ?? $key;
        }, $days)));
    }
}
