<?php

namespace App\Domain\Ops;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Worker-latency telemetry for the `predictions` queue (gap-checklist N2).
 *
 * `App\Jobs\CalculateTradeForecast` already measures `queue_wait_ms` and
 * `compute_ms` per job and attaches them to the result it publishes, but
 * nothing aggregated them, and the jobs its 20-second validity rule throws
 * away were counted nowhere. This keeps a small rolling window of per-job
 * outcomes so the existing Jobs Control Center can show p50/p95 and the drop
 * count.
 *
 * Observational only. record() never throws into the forecast worker: a
 * metrics failure is logged and the sample is lost, the forecast is not.
 * Samples live in the same `redis` cache store the forecast pipeline itself
 * uses (AsyncTradeForecast / CalculateTradeForecast), so no new
 * infrastructure is involved.
 */
class PredictionQueueLatencyMetrics
{
    public const QUEUE = 'predictions';

    /** Result computed in time and published to the cache. */
    public const OUTCOME_PUBLISHED = 'published';

    /** Picked up 20s+ after the request -- discarded before computing. */
    public const OUTCOME_DROPPED_BEFORE_COMPUTE = 'dropped_expired_before_compute';

    /** Computed, but finished after the 20s validity window -- discarded. */
    public const OUTCOME_DROPPED_AFTER_COMPUTE = 'dropped_expired_after_compute';

    public const STORE = 'redis';

    public const SAMPLES_KEY = 'ops:prediction-queue-latency:v1:samples';

    public const LOCK_KEY = 'ops:prediction-queue-latency:v1:lock';

    /** Hard cap on retained samples, independent of the time window. */
    public const MAX_SAMPLES = 1000;

    /** Reporting window. */
    public const WINDOW_SECONDS = 3600;

    public function record(string $outcome, ?int $queueWaitMs, ?int $computeMs, ?string $kind = null, ?int $at = null): void
    {
        try {
            $cache = $this->cache();
            // Non-blocking: under contention the sample is skipped rather than
            // making a forecast worker wait on a metrics lock.
            $lock = $cache->lock(self::LOCK_KEY, 5);
            if (! $lock->get()) {
                return;
            }
            try {
                $now = $at ?? time();
                $samples = $this->prune((array) $cache->get(self::SAMPLES_KEY, []), $now);
                $samples[] = [
                    't' => $now,
                    'outcome' => $outcome,
                    'kind' => $kind,
                    'queue_wait_ms' => $queueWaitMs === null ? null : max(0, $queueWaitMs),
                    'compute_ms' => $computeMs === null ? null : max(0, $computeMs),
                ];
                if (count($samples) > self::MAX_SAMPLES) {
                    $samples = array_slice($samples, -self::MAX_SAMPLES);
                }
                $cache->put(self::SAMPLES_KEY, array_values($samples), self::WINDOW_SECONDS * 2);
            } finally {
                $lock->release();
            }
        } catch (Throwable $e) {
            Log::debug('[PredictionQueueLatencyMetrics] sample not recorded (non-fatal)', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(?int $now = null): array
    {
        $now = $now ?? time();
        $base = [
            'queue' => self::QUEUE,
            'window_seconds' => self::WINDOW_SECONDS,
            'max_samples' => self::MAX_SAMPLES,
            'validity_seconds' => 20,
        ];

        try {
            $samples = $this->prune((array) $this->cache()->get(self::SAMPLES_KEY, []), $now);
        } catch (Throwable $e) {
            return $base + [
                'available' => false,
                'reason' => 'metrics_store_unavailable',
            ];
        }

        $published = array_values(array_filter($samples, fn (array $s) => ($s['outcome'] ?? null) === self::OUTCOME_PUBLISHED));
        $droppedBefore = count(array_filter($samples, fn (array $s) => ($s['outcome'] ?? null) === self::OUTCOME_DROPPED_BEFORE_COMPUTE));
        $droppedAfter = count(array_filter($samples, fn (array $s) => ($s['outcome'] ?? null) === self::OUTCOME_DROPPED_AFTER_COMPUTE));

        // Queue wait is meaningful for every job that was picked up, including
        // the dropped ones (a long wait is exactly why they were dropped).
        $queueWaits = $this->column($samples, 'queue_wait_ms');
        // Compute time only exists for jobs that actually computed.
        $computeTimes = $this->column(array_values(array_filter(
            $samples,
            fn (array $s) => ($s['outcome'] ?? null) !== self::OUTCOME_DROPPED_BEFORE_COMPUTE,
        )), 'compute_ms');

        $total = count($samples);

        return $base + [
            'available' => true,
            'samples' => $total,
            'published' => count($published),
            'dropped_total' => $droppedBefore + $droppedAfter,
            'dropped_expired_before_compute' => $droppedBefore,
            'dropped_expired_after_compute' => $droppedAfter,
            'drop_rate_pct' => $total > 0 ? round((($droppedBefore + $droppedAfter) / $total) * 100, 2) : null,
            'queue_wait_ms' => [
                'p50' => $this->percentile($queueWaits, 50),
                'p95' => $this->percentile($queueWaits, 95),
                'max' => $queueWaits === [] ? null : max($queueWaits),
                'n' => count($queueWaits),
            ],
            'compute_ms' => [
                'p50' => $this->percentile($computeTimes, 50),
                'p95' => $this->percentile($computeTimes, 95),
                'max' => $computeTimes === [] ? null : max($computeTimes),
                'n' => count($computeTimes),
            ],
            'oldest_sample_at' => $total > 0 ? date(DATE_ATOM, (int) $samples[0]['t']) : null,
            'newest_sample_at' => $total > 0 ? date(DATE_ATOM, (int) $samples[$total - 1]['t']) : null,
        ];
    }

    /**
     * Nearest-rank percentile. Null (never a fabricated 0) when there is no
     * data.
     *
     * @param  array<int, int>  $values
     */
    public function percentile(array $values, int $pct): ?int
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $rank = (int) ceil(($pct / 100) * count($values));

        return (int) $values[max(0, min(count($values) - 1, $rank - 1))];
    }

    /**
     * @param  array<int, mixed>  $samples
     * @return array<int, array<string, mixed>>
     */
    private function prune(array $samples, int $now): array
    {
        $cutoff = $now - self::WINDOW_SECONDS;

        return array_values(array_filter(
            $samples,
            fn ($s) => is_array($s) && isset($s['t']) && (int) $s['t'] >= $cutoff,
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $samples
     * @return array<int, int>
     */
    private function column(array $samples, string $key): array
    {
        $values = [];
        foreach ($samples as $sample) {
            if (isset($sample[$key]) && is_numeric($sample[$key])) {
                $values[] = (int) $sample[$key];
            }
        }

        return $values;
    }

    private function cache(): Repository
    {
        return Cache::store(self::STORE);
    }
}
