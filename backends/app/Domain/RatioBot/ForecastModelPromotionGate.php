<?php

namespace App\Domain\RatioBot;

use App\Domain\Trading\ForecastMetricsService;
use App\Models\RatioBotForecastBacktest;
use Illuminate\Support\Carbon;

/**
 * READ-ONLY promotion gate (Unified Forecast Engine plan -- "Model
 * promotion gate" task). Answers exactly one question -- "is this
 * (bot_type, bot_instance_id, horizon_seconds) combination validated enough
 * to trust" -- for a human or a future, explicitly separate promotion step
 * to act on. This class NEVER writes to `RatioBotForecastBacktest` (or any
 * other table), never flips a feature flag, and is never called from any
 * live execution path -- verified by construction: every method here is a
 * pure read + compute, and nothing in this task wires it into
 * RatioBotBrainService, QuickTradeService, RobotSession, or Future Trade's
 * execution path.
 *
 * Data sources, and why each is real (never fabricated):
 * - Sample size / calibration / holdout-existence come from
 *   `RatioBotForecastBacktest` rows written by
 *   `ForecastHorizonBacktestService::runForBotWithHoldout()` -- the
 *   `evaluation_period` column (`training`/`holdout`/`full`) it introduced
 *   is what lets this class tell a genuine out-of-sample row from an
 *   in-sample one.
 * - Net P&L / drawdown come from a `ForecastMetricsService::summarize()`
 *   result the CALLER supplies, scoped to real, settled, holdout-period
 *   trades (e.g. QuickTradeResearchService::report() rows for trades whose
 *   settled_at falls after the backtest's holdout cutoff). This class
 *   deliberately does NOT reach into QuickTrade/RatioBotDecision rows
 *   itself and join them to a holdout time window -- there is no existing
 *   linkage in this codebase between an OHLC-based walk-forward backtest
 *   run and a specific set of real executed trades, and inventing one here
 *   would be exactly the kind of fabricated-data shortcut this codebase's
 *   own rules forbid. If the caller has no such summary yet, this class
 *   reports NOT_VALIDATED rather than guessing.
 * - Staleness reuses `RatioBotForecastBacktest.computed_at` -- the same
 *   freshness timestamp every row already carries for exactly this
 *   purpose -- rather than inventing a new data-quality signal.
 */
class ForecastModelPromotionGate
{
    public const STATUS_INSUFFICIENT_DATA = 'INSUFFICIENT_DATA';
    public const STATUS_NOT_VALIDATED = 'NOT_VALIDATED';
    public const STATUS_VALIDATED = 'VALIDATED';
    public const STATUS_NOT_TRADEABLE = 'NOT_TRADEABLE';

    /**
     * Reasoned default: a strategy whose real, holdout-period cumulative
     * P&L has fallen more than 20% peak-to-trough is treated as too risky
     * to call VALIDATED, independent of how good its average hit-rate
     * looks -- an average can hide a single catastrophic drawdown. 20% is
     * a conservative, commonly-used circuit-breaker-style threshold (in the
     * same spirit as this codebase's existing daily-loss-tier breakers
     * elsewhere in the trading engines) rather than a number tuned to any
     * specific bot; it is a named constant specifically so it can be
     * revisited deliberately later instead of being buried inline.
     */
    public const MAX_HOLDOUT_DRAWDOWN_PCT = 20.0;

    /**
     * A backtest/calibration older than this has not been re-validated
     * against the current market regime and must not be presented as
     * currently trustworthy. Reuses the `computed_at` column every
     * `RatioBotForecastBacktest` row already carries -- no new freshness
     * mechanism invented for this gate.
     */
    public const MAX_CALIBRATION_AGE_DAYS = 30;

    /**
     * @param  array|null  $holdoutPnlSummary  The result of
     *         `ForecastMetricsService::summarize()` scoped to real,
     *         settled trades from the holdout period only (see class
     *         docblock). Pass null if no such real-trade evidence exists
     *         yet -- this method will correctly report NOT_VALIDATED rather
     *         than fabricate a verdict from backtest-only (no real P&L)
     *         data.
     * @return array{status:string, reasons:array<int,string>, details:array<string,mixed>}
     */
    public function evaluate(
        string $botType,
        string $botInstanceId,
        int $horizonSeconds,
        ?array $holdoutPnlSummary = null,
    ): array {
        $rows = RatioBotForecastBacktest::query()
            ->where('bot_type', $botType)
            ->where('bot_instance_id', $botInstanceId)
            ->where('horizon_seconds', $horizonSeconds)
            ->get();

        $training = $rows->where('evaluation_period', 'training');
        $holdout = $rows->where('evaluation_period', 'holdout');

        $trainingSampleTotal = (int) $training->sum('sample_size');
        $holdoutSampleTotal = (int) $holdout->sum('sample_size');

        $details = [
            'training_rows' => $training->count(),
            'holdout_rows' => $holdout->count(),
            'training_sample_total' => $trainingSampleTotal,
            'holdout_sample_total' => $holdoutSampleTotal,
        ];

        // Gate 1: is there even any real training/calibration data at all?
        if ($rows->isEmpty() || $trainingSampleTotal === 0) {
            return $this->result(self::STATUS_INSUFFICIENT_DATA, ['no_training_backtest_data'], $details);
        }

        // Gate 2: has this bot/horizon ever actually been evaluated
        // out-of-sample, not just in-sample?
        if ($holdout->isEmpty() || $holdoutSampleTotal === 0) {
            return $this->result(self::STATUS_NOT_VALIDATED, ['holdout_not_evaluated'], $details);
        }

        // Gate 3: does at least one confidence bucket have real calibration
        // (both in training AND with real holdout volume to check it
        // against) -- reuses ForecastHorizonBacktestService's own sample
        // floor, never a separate, invented one.
        $calibratedTrainingBuckets = $training->filter(
            fn (RatioBotForecastBacktest $row): bool => (int) $row->sample_size >= ForecastHorizonBacktestService::MIN_BUCKET_SAMPLE_SIZE,
        );
        $calibratedHoldoutBuckets = $holdout->filter(
            fn (RatioBotForecastBacktest $row): bool => (int) $row->sample_size >= ForecastHorizonBacktestService::MIN_BUCKET_SAMPLE_SIZE,
        );
        $details['calibrated_training_buckets'] = $calibratedTrainingBuckets->count();
        $details['calibrated_holdout_buckets'] = $calibratedHoldoutBuckets->count();
        if ($calibratedTrainingBuckets->isEmpty() || $calibratedHoldoutBuckets->isEmpty()) {
            return $this->result(self::STATUS_NOT_VALIDATED, ['no_calibrated_confidence_buckets_with_real_volume'], $details);
        }

        // Gate 4: is the evidence fresh, or was it computed against a
        // market regime that may no longer apply?
        $latestComputedAt = $holdout->max(fn (RatioBotForecastBacktest $row) => $row->computed_at);
        $details['latest_holdout_computed_at'] = $latestComputedAt?->toIso8601String();
        if ($latestComputedAt === null || Carbon::parse($latestComputedAt)->lt(now()->subDays(self::MAX_CALIBRATION_AGE_DAYS))) {
            return $this->result(self::STATUS_NOT_VALIDATED, ['stale_backtest_data'], $details);
        }

        // Gate 5: do we have REAL, holdout-period trade P&L evidence at
        // all? Never fabricated -- if the caller has none, this is
        // "not yet validated", not "validated by default".
        if ($holdoutPnlSummary === null) {
            return $this->result(self::STATUS_NOT_VALIDATED, ['holdout_pnl_evidence_not_supplied'], $details);
        }

        $pnlSampleSize = (int) ($holdoutPnlSummary['sample_size'] ?? 0);
        $details['holdout_pnl_sample_size'] = $pnlSampleSize;
        if ($pnlSampleSize < ForecastMetricsService::MIN_TRUSTED_SAMPLES) {
            return $this->result(self::STATUS_NOT_VALIDATED, ['holdout_pnl_sample_too_small'], $details);
        }

        // Gate 6 (financial gates -- evidence IS sufficient at this point,
        // so a failure here means "tested and failed", i.e. NOT_TRADEABLE,
        // not merely "not validated yet"):
        $netPnl = $holdoutPnlSummary['net_pnl'] ?? null;
        $details['holdout_net_pnl'] = $netPnl;
        $reasons = [];
        if ($netPnl === null || (float) $netPnl <= 0.0) {
            $reasons[] = 'holdout_net_pnl_not_positive';
        }

        $maxDrawdownPct = $holdoutPnlSummary['max_drawdown_pct'] ?? null;
        $details['holdout_max_drawdown_pct'] = $maxDrawdownPct;
        if ($maxDrawdownPct !== null && (float) $maxDrawdownPct > self::MAX_HOLDOUT_DRAWDOWN_PCT) {
            $reasons[] = 'holdout_drawdown_exceeds_threshold';
        }

        if ($reasons !== []) {
            return $this->result(self::STATUS_NOT_TRADEABLE, $reasons, $details);
        }

        return $this->result(self::STATUS_VALIDATED, ['all_gates_passed'], $details);
    }

    private function result(string $status, array $reasons, array $details): array
    {
        return ['status' => $status, 'reasons' => $reasons, 'details' => $details];
    }
}
