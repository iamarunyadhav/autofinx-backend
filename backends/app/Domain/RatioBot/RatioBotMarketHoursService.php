<?php

namespace App\Domain\RatioBot;

use App\Models\RatioBotConfig;
use Illuminate\Support\Carbon;

/**
 * Single source of truth for interpreting `config('assets.{class}.market_hours')`
 * for Ratio Bot -- used by both RatioBotRiskService::preTradeBlockReason()
 * (the actual gate) and the API/frontend (the display status), so the two
 * can never disagree about whether a market is open.
 *
 * Confirmed this pass: `config/assets.php` already has a real `market_hours`
 * block for both `metals` and `forex`, but the only existing reader
 * (`BotRiskEngine::enforceMetalsSessionAndTradeRate()`) belongs to a
 * completely separate, older bot system -- Ratio Bot has never had any
 * market-hours awareness until this service.
 */
class RatioBotMarketHoursService
{
    /**
     * @return array{applicable: bool, is_open: bool, reason: string, resumes_at: ?string, timezone: ?string}
     */
    public function status(RatioBotConfig $config): array
    {
        $assetClass = $config->assetClass();
        if ($assetClass === 'CRYPTO') {
            // Cheap, immediate short-circuit -- crypto bots must see zero
            // added per-tick cost from this check (same pattern as the
            // Liquidation Guard's spot short-circuit).
            return $this->result(false, true, 'crypto trades 24/7 -- market hours not applicable', null, null);
        }

        $marketHours = (array) config('assets.' . strtolower($assetClass) . '.market_hours', []);
        if ($marketHours === []) {
            // No configured window for this asset class -- fail open rather
            // than block trading on a missing config entry that has nothing
            // to do with real market availability.
            return $this->result(false, true, 'no market_hours configured for this asset class', null, null);
        }

        $timezone = (string) ($marketHours['timezone'] ?? 'UTC');
        $open = (string) ($marketHours['open'] ?? '00:00');
        $close = (string) ($marketHours['close'] ?? '23:59');
        $closedWeekdays = array_map('intval', (array) ($marketHours['closed_weekdays'] ?? []));

        $now = Carbon::now($timezone);
        if (in_array((int) $now->dayOfWeek, $closedWeekdays, true)) {
            return $this->result(true, false, "market closed for the week (weekday {$now->dayOfWeek})", $this->nextOpen($now, $open, $close, $closedWeekdays)->toIso8601String(), $timezone);
        }

        if (! $this->isWithinSession($now, $open, $close)) {
            return $this->result(true, false, "outside trading session {$open}-{$close} {$timezone}", $this->nextOpen($now, $open, $close, $closedWeekdays)->toIso8601String(), $timezone);
        }

        return $this->result(true, true, "within trading session {$open}-{$close} {$timezone}", null, $timezone);
    }

    public function isOpenForNewEntries(RatioBotConfig $config): bool
    {
        return (bool) $this->status($config)['is_open'];
    }

    /**
     * Mirrors `BotRiskEngine::isWithinSession()`'s overnight-session handling
     * (open > close, e.g. 22:00-21:00 next day) so the same config shape is
     * interpreted identically across both bot systems.
     */
    private function isWithinSession(Carbon $now, string $open, string $close): bool
    {
        if ($open === $close) {
            // e.g. forex's "22:00-22:00": the session spans continuously
            // across day boundaries, closed_weekdays alone marks the actual
            // gap -- not a zero-width window that's only ever open for one
            // instant.
            return true;
        }

        $openAt = $now->copy()->setTimeFromTimeString($open);
        $closeAt = $now->copy()->setTimeFromTimeString($close);

        if ($openAt->lessThanOrEqualTo($closeAt)) {
            return $now->greaterThanOrEqualTo($openAt) && $now->lessThanOrEqualTo($closeAt);
        }

        // Overnight session: open today OR before close (which lands "tomorrow" conceptually).
        return $now->greaterThanOrEqualTo($openAt) || $now->lessThanOrEqualTo($closeAt);
    }

    private function nextOpen(Carbon $now, string $open, string $close, array $closedWeekdays): Carbon
    {
        if ($open === $close) {
            // Continuous session -- reopens at the start of the next
            // non-closed day, not at a specific time-of-day.
            $candidate = $now->copy()->startOfDay()->addDay();
        } else {
            $candidate = $now->copy()->setTimeFromTimeString($open);
            if ($candidate->lessThanOrEqualTo($now)) {
                $candidate->addDay();
            }
        }

        $guard = 0;
        while (in_array((int) $candidate->dayOfWeek, $closedWeekdays, true) && $guard < 8) {
            $candidate->addDay();
            $guard++;
        }

        return $candidate;
    }

    private function result(bool $applicable, bool $isOpen, string $reason, ?string $resumesAt, ?string $timezone): array
    {
        return [
            'applicable' => $applicable,
            'is_open' => $isOpen,
            'reason' => $reason,
            'resumes_at' => $resumesAt,
            'timezone' => $timezone,
        ];
    }
}
