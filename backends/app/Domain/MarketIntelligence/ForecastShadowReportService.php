<?php

namespace App\Domain\MarketIntelligence;

use App\Domain\RatioBot\ForecastHorizonBacktestService;
use App\Domain\Trading\TimeframeRegistry;
use App\Models\AuditEvent;
use App\Models\MarketOhlc;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Read-only reporting layer over the `unified_forecast_shadow_comparison`
 * AuditEvent rows QuickTradeController::recordUnifiedForecastShadowComparison()
 * already writes (see that method + UnifiedForecastEngineTest /
 * QuickTradeForecastShadowTest for the exact event/metadata shape this class
 * reads). Never influences a real trade decision -- purely resolves what
 * ALREADY happened, for a human to look at with their own eyes.
 *
 * Outcome resolution deliberately reuses
 * ForecastHorizonBacktestService::walkForward()'s exact contract (documented
 * there): the "prediction point" candle is the last CLOSED candle at/before
 * occurred_at on the same (exchange, symbol, timeframe) MarketOhlc reads
 * elsewhere in this app; the "outcome" candle is the one exactly
 * horizonCandles = ceil(horizon_seconds / timeframe_seconds) positions later
 * in that same ordered series -- never a timestamp-nearest guess, never an
 * interpolated price. If that later candle doesn't exist yet in MarketOhlc,
 * the event's outcome is honestly reported as 'pending' (horizon not
 * elapsed) or 'unknown' (horizon elapsed, but no matching candle data was
 * ever ingested for this exchange/symbol/timeframe) -- never fabricated.
 *
 * NEUTRAL calls (either mechanism) are excluded from that mechanism's own
 * correct/incorrect tally, for the same reason
 * ForecastHorizonBacktestService excludes them from its hit-rate: a
 * non-directional call was never a bet on direction, so scoring it either
 * way would make the accuracy % dishonest. A 'flat' real outcome (close
 * price genuinely unchanged) is excluded the same way, for both mechanisms,
 * for the same reason -- there was no directional move to have been right
 * or wrong about.
 */
class ForecastShadowReportService
{
    /**
     * Mirrors ForecastHorizonBacktestService::MIN_BUCKET_SAMPLE_SIZE's own
     * discipline: below this many determinable, non-neutral samples, an
     * accuracy percentage is not shown as a trustworthy figure.
     */
    public const MIN_SAMPLE_SIZE = ForecastHorizonBacktestService::MIN_BUCKET_SAMPLE_SIZE;

    private const MAX_EVENTS = 500;

    /** Candles fetched around each event's window; generous but bounded. */
    private const CANDLE_WINDOW_PADDING = 5;

    public function __construct(
        private readonly TimeframeRegistry $timeframes,
    ) {
    }

    /**
     * @return array{
     *   range: array{days:int, since:string, until:string},
     *   summary: array{
     *     total_events:int, determinable_events:int, pending_events:int, unknown_events:int,
     *     old: array{evaluable_samples:int, correct:int, accuracy_pct: ?float, insufficient_data:bool},
     *     new: array{evaluable_samples:int, correct:int, accuracy_pct: ?float, insufficient_data:bool},
     *   },
     *   rows: array<int, array<string, mixed>>,
     * }
     */
    public function report(int $days = 7, ?string $symbol = null): array
    {
        $days = max(1, min(90, $days));
        $since = CarbonImmutable::now()->subDays($days);
        $until = CarbonImmutable::now();

        $events = AuditEvent::query()
            ->where('event_type', 'unified_forecast_shadow_comparison')
            ->where('occurred_at', '>=', $since)
            ->when($symbol, fn ($q) => $q->where('symbol', $symbol))
            ->orderByDesc('occurred_at')
            ->limit(self::MAX_EVENTS)
            ->get();

        $rows = $events->map(fn (AuditEvent $event) => $this->resolveRow($event))->values();

        $determinable = $rows->filter(fn (array $row) => $row['outcome_status'] === 'determined');
        $pending = $rows->filter(fn (array $row) => $row['outcome_status'] === 'pending');
        $unknown = $rows->filter(fn (array $row) => $row['outcome_status'] === 'unknown');

        $oldEvaluable = $determinable->filter(fn (array $row) => $row['old_correct'] !== null);
        $newEvaluable = $determinable->filter(fn (array $row) => $row['new_correct'] !== null);

        $oldCorrect = $oldEvaluable->filter(fn (array $row) => $row['old_correct'] === true)->count();
        $newCorrect = $newEvaluable->filter(fn (array $row) => $row['new_correct'] === true)->count();

        return [
            'range' => [
                'days' => $days,
                'since' => $since->toIso8601String(),
                'until' => $until->toIso8601String(),
            ],
            'summary' => [
                'total_events' => $rows->count(),
                'determinable_events' => $determinable->count(),
                'pending_events' => $pending->count(),
                'unknown_events' => $unknown->count(),
                'old' => $this->mechanismSummary($oldEvaluable->count(), $oldCorrect),
                'new' => $this->mechanismSummary($newEvaluable->count(), $newCorrect),
            ],
            'rows' => $rows->all(),
        ];
    }

    /**
     * @return array{evaluable_samples:int, correct:int, accuracy_pct: ?float, insufficient_data:bool}
     */
    private function mechanismSummary(int $sampleSize, int $correct): array
    {
        $insufficientData = $sampleSize < self::MIN_SAMPLE_SIZE;

        return [
            'evaluable_samples' => $sampleSize,
            'correct' => $correct,
            'accuracy_pct' => (! $insufficientData && $sampleSize > 0) ? round(($correct / $sampleSize) * 100, 2) : null,
            'insufficient_data' => $insufficientData,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveRow(AuditEvent $event): array
    {
        $metadata = (array) ($event->metadata ?? []);
        $old = (array) ($metadata['old'] ?? []);
        $new = (array) ($metadata['new'] ?? []);

        [$exchange, $timeframe] = $this->parseBotInstanceId((string) $event->bot_instance_id);
        $symbol = (string) $event->symbol;

        $oldDirection = $this->normalizeOldDirection($old['bias'] ?? null, (bool) ($old['available'] ?? false));
        $oldConfidence = $old['confidence'] ?? null;

        $newDirection = $this->normalizeDirection($new['direction'] ?? null);
        $newConfidence = $new['confidence'] ?? null;
        $newCalibrated = (bool) ($new['calibrated'] ?? false);

        $horizonSeconds = (int) ($old['horizon_seconds'] ?? 0);

        $outcome = $this->resolveOutcome(
            $exchange,
            $symbol,
            $timeframe,
            $event->occurred_at,
            $horizonSeconds,
        );

        $oldCorrect = $this->judgeCorrectness($oldDirection, $outcome['direction']);
        $newCorrect = $this->judgeCorrectness($newDirection, $outcome['direction']);

        return [
            'id' => $event->id,
            'occurred_at' => optional($event->occurred_at)->toIso8601String(),
            'symbol' => $symbol,
            'exchange' => $exchange,
            'timeframe' => $timeframe,
            'horizon_seconds' => $horizonSeconds,
            'old_direction' => $oldDirection,
            'old_confidence' => $oldConfidence !== null ? (float) $oldConfidence : null,
            'new_direction' => $newDirection,
            'new_confidence' => $newConfidence !== null ? (float) $newConfidence : null,
            'new_calibrated' => $newCalibrated,
            'outcome_status' => $outcome['status'],
            'outcome_direction' => $outcome['direction'],
            'old_correct' => $oldCorrect,
            'new_correct' => $newCorrect,
        ];
    }

    /**
     * bot_instance_id is written by
     * QuickTradeController::recordUnifiedForecastShadowComparison() as
     * "{exchange}:{symbol}:{timeframe}" -- parsed back out here rather than
     * duplicated as separate columns (schema is additive-only, no new
     * columns needed for this report).
     *
     * @return array{0:string, 1:string}
     */
    private function parseBotInstanceId(string $botInstanceId): array
    {
        $parts = explode(':', $botInstanceId, 3);

        return [
            $parts[0] ?? 'binance',
            $parts[2] ?? '1m',
        ];
    }

    private function normalizeOldDirection(mixed $bias, bool $available): ?string
    {
        if (! $available || ! is_string($bias)) {
            return null;
        }

        return $this->normalizeDirection($bias);
    }

    private function normalizeDirection(mixed $direction): ?string
    {
        if (! is_string($direction)) {
            return null;
        }

        $upper = strtoupper($direction);
        if (! in_array($upper, ['BUY', 'SELL', 'NEUTRAL'], true)) {
            return null;
        }

        return $upper;
    }

    /**
     * @return array{status:string, direction: ?string}
     */
    private function resolveOutcome(string $exchange, string $symbol, string $timeframe, ?\DateTimeInterface $occurredAt, int $horizonSeconds): array
    {
        if ($occurredAt === null || $horizonSeconds <= 0 || $symbol === '') {
            return ['status' => 'unknown', 'direction' => null];
        }

        $occurred = CarbonImmutable::instance($occurredAt);
        $horizonElapsesAt = $occurred->addSeconds($horizonSeconds);

        if ($horizonElapsesAt->isAfter(CarbonImmutable::now())) {
            return ['status' => 'pending', 'direction' => null];
        }

        $tfSeconds = max(1, $this->timeframes->seconds($timeframe));
        $horizonCandles = max(1, (int) ceil($horizonSeconds / $tfSeconds));

        $windowStart = $occurred->subSeconds($tfSeconds * self::CANDLE_WINDOW_PADDING);
        $windowEnd = $horizonElapsesAt->addSeconds($tfSeconds * self::CANDLE_WINDOW_PADDING);

        $candles = MarketOhlc::query()
            ->where('exchange', $exchange)
            ->where('symbol', $symbol)
            ->where('timeframe', $timeframe)
            // ts is UTC wall time; occurred_at is app-timezone. Bind UTC.
            ->where('ts', '>=', $windowStart->utc())
            ->where('ts', '<=', $windowEnd->utc())
            ->orderBy('ts')
            ->get(['ts', 'close'])
            ->values();

        if ($candles->isEmpty()) {
            return ['status' => 'unknown', 'direction' => null];
        }

        $baseIndex = $this->lastIndexAtOrBefore($candles, $occurred);
        if ($baseIndex === null) {
            return ['status' => 'unknown', 'direction' => null];
        }

        $outcomeIndex = $baseIndex + $horizonCandles;
        if ($outcomeIndex >= $candles->count()) {
            return ['status' => 'unknown', 'direction' => null];
        }

        $baseClose = (float) $candles[$baseIndex]->close;
        $futureClose = (float) $candles[$outcomeIndex]->close;

        if ($baseClose <= 0) {
            return ['status' => 'unknown', 'direction' => null];
        }

        if ($futureClose > $baseClose) {
            $direction = 'up';
        } elseif ($futureClose < $baseClose) {
            $direction = 'down';
        } else {
            $direction = 'flat';
        }

        return ['status' => 'determined', 'direction' => $direction];
    }

    /**
     * @param  Collection<int, MarketOhlc>  $candles  Already ordered ascending by ts.
     */
    private function lastIndexAtOrBefore(Collection $candles, CarbonImmutable $timestamp): ?int
    {
        $lastIndex = null;
        foreach ($candles as $index => $candle) {
            if (CarbonImmutable::parse($candle->ts)->lessThanOrEqualTo($timestamp)) {
                $lastIndex = $index;
            } else {
                break;
            }
        }

        return $lastIndex;
    }

    private function judgeCorrectness(?string $direction, ?string $actualDirection): ?bool
    {
        if ($direction === null || $direction === 'NEUTRAL' || $actualDirection === null || $actualDirection === 'flat') {
            return null;
        }

        return ($direction === 'BUY' && $actualDirection === 'up')
            || ($direction === 'SELL' && $actualDirection === 'down');
    }
}
