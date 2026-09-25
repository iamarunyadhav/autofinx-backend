<?php

namespace App\Domain\RatioBot;

use App\Domain\Trading\TimeframeRegistry;
use App\Models\MarketOhlc;
use App\Models\RatioBotConfig;
use App\Models\RatioBotDecision;
use App\Models\RatioBotLot;
use App\Models\RatioBotTradeOutcome;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Trade-level accuracy tracking for Ratio Bot -- OBSERVATION ONLY.
 *
 * Answers, per real filled spot BUY/SELL decision, "was this specific ladder
 * fill a good decision in hindsight?". It never influences a live decision:
 * nothing in RatioBotDecisionService / RatioBotLotService / the execution
 * path reads ratio_bot_trade_outcomes, and every public entry point here
 * swallows its own failures (log-and-continue), the same fail-safe
 * discipline AuditEventRecorder uses on this engine's hot path.
 *
 * Unit of scoring: one RatioBotDecision that reached onBuyFilled()/
 * onSellFilled(). Synthetic anchors (auto-anchor / manual-starting-entry /
 * starting-inventory lots) never pass through those methods, so they are
 * never scored -- no money moved, nothing to judge.
 *
 * Definitions (all ratios are fractions, e.g. 0.004 = 0.4%):
 *
 *  cost_ratio  the engine's own round-trip execution cost,
 *              (2 x fee_bps + max_slippage_bps) / 10000 -- taken from the
 *              decision's own stamped metadata `cost_ratio` when present,
 *              otherwise from executionCostRatio() below, a parity-tested
 *              mirror of RatioBotDecisionService::executionCostRatio()
 *              (see RatioBotTradeOutcomeServiceTest). Snapshotted at fill.
 *
 *  BUY         required_exit_ratio = cost_ratio + min_net_profit_ratio --
 *              exactly the engine's net-profit gate (finalizeTradeSignal():
 *              effective_ratio = ratio - cost_ratio >= minimum_net_profit_ratio;
 *              RatioBotRiskService's refresh gate uses the same rule). For a
 *              fill folded into the merged recovery-average lot it is
 *              RatioBotConfig::recoveryLotMinProfitRatio() instead -- the very
 *              floor RatioBotLotService::profitableFifoSellPlan() applies to
 *              that lot. `recovered_net_profitable` iff some closed candle
 *              (high) or a real sell fill of this lot inside the horizon
 *              reached executed_price x (1 + required_exit_ratio);
 *              `not_recovered_within_horizon` iff it did not AND candle
 *              coverage was sufficient to say so.
 *
 *  SELL        missed_edge_ratio = max(0, best_high_after / sell_price - 1)
 *              over the lookahead window. `well_timed` iff the missed upside
 *              is <= cost_ratio (an upside smaller than one round-trip cost is
 *              not a capturable edge -- the engine's own buy/sell boundaries
 *              treat sub-cost moves the same way), else `sold_early`.
 *              realized_net_ratio = sell_price / cost_basis - 1 - cost_ratio
 *              is recorded immediately at fill (cost basis from the lots'
 *              own sell_fills for this decision).
 *
 * Honesty rules (same convention as QuickTradeResearchService):
 *  - `pending` until the bounded window has closed; never a verdict early;
 *  - `insufficient_data` when candle coverage is short (after retries) or the
 *    timeframe cannot be mapped -- never a guessed verdict;
 *  - `not_applicable` for fills that cannot be judged (no price/qty);
 *  - summaries exclude non-evaluated rows from denominators and return null
 *    percentages below min_samples_for_accuracy.
 */
class RatioBotTradeOutcomeService
{
    private const THROTTLE_CACHE_PREFIX = 'ratio-bot-trade-outcomes-eval:';

    // -----------------------------------------------------------------
    // Capture (called from RatioBotStateService on every real fill)
    // -----------------------------------------------------------------

    public function captureBuyFill(
        RatioBotConfig $config,
        RatioBotDecision $decision,
        float $executedPrice,
        float $executedQty,
        string $orderId,
    ): ?RatioBotTradeOutcome {
        return $this->safely(function () use ($config, $decision, $executedPrice, $executedQty, $orderId) {
            if (! $this->shouldCapture($config, $decision, RatioBotDecision::DECISION_BUY)) {
                return null;
            }

            $metadata = (array) ($decision->metadata ?? []);
            $isRecoveryFill = (string) data_get($metadata, 'stage.type', '') === RatioBotLot::TYPE_RECOVERY;

            $lot = RatioBotLot::query()
                ->where('ratio_bot_config_id', $config->id)
                ->where('buy_decision_id', $decision->id)
                ->first();
            if (! $lot && $isRecoveryFill) {
                // RatioBotLotService::recordRecoveryBuyFill() merges into the
                // existing averaged lot (which keeps the FIRST fill's
                // buy_decision_id), so look that lot up instead.
                $lot = RatioBotLot::query()
                    ->where('ratio_bot_config_id', $config->id)
                    ->where('status', RatioBotLot::STATUS_OPEN)
                    ->where('is_recovery_average', true)
                    ->orderByDesc('updated_at')
                    ->first();
            }

            [$costRatio, $costSource] = $this->costRatioFor($config, $metadata);
            $minNet = $this->minNetProfitRatio($config);
            $isRecoveryLot = (bool) ($lot?->is_recovery_average ?? false);
            $requiredExit = $isRecoveryLot
                ? $config->recoveryLotMinProfitRatio()
                : $costRatio + $minNet;

            return $this->persist($config, $decision, RatioBotTradeOutcome::SIDE_BUY, $executedPrice, $executedQty, $orderId, [
                'ratio_bot_lot_id' => $lot?->id,
                'lot_type' => $lot?->lot_type ?? (data_get($metadata, 'stage.type') ?: null),
                'stage_index' => data_get($metadata, 'stage.index') !== null ? (int) data_get($metadata, 'stage.index') : $lot?->buy_stage_index,
                'cycle_number' => $lot?->cycle_number,
                'is_recovery_lot' => $isRecoveryLot,
                'cost_ratio' => $costRatio,
                'min_net_profit_ratio' => $minNet,
                'required_exit_ratio' => $requiredExit,
            ], [
                'cost_ratio_source' => $costSource,
                'required_exit_source' => $isRecoveryLot ? 'recovery_lot_min_profit_ratio' : 'cost_ratio_plus_min_net_profit_ratio',
                'decision_minimum_net_profit_ratio' => data_get($metadata, 'minimum_net_profit_ratio'),
            ]);
        });
    }

    public function captureSellFill(
        RatioBotConfig $config,
        RatioBotDecision $decision,
        float $executedPrice,
        float $executedQty,
        string $orderId,
        float $fallbackCostBasis,
    ): ?RatioBotTradeOutcome {
        return $this->safely(function () use ($config, $decision, $executedPrice, $executedQty, $orderId, $fallbackCostBasis) {
            if (! $this->shouldCapture($config, $decision, RatioBotDecision::DECISION_SELL)) {
                return null;
            }

            $metadata = (array) ($decision->metadata ?? []);
            [$costRatio, $costSource] = $this->costRatioFor($config, $metadata);
            [$costBasis, $basisSource, $lots] = $this->sellCostBasis($config, $decision, $metadata, $fallbackCostBasis);

            $realizedNet = ($costBasis !== null && $costBasis > 0 && $executedPrice > 0)
                ? ($executedPrice / $costBasis) - 1 - $costRatio
                : null;

            return $this->persist($config, $decision, RatioBotTradeOutcome::SIDE_SELL, $executedPrice, $executedQty, $orderId, [
                'ratio_bot_lot_id' => $lots->count() === 1 ? $lots->first()->id : null,
                'lot_type' => $lots->pluck('lot_type')->unique()->count() === 1 ? $lots->first()->lot_type : null,
                'stage_index' => data_get($metadata, 'stage.index') !== null ? (int) data_get($metadata, 'stage.index') : null,
                'cycle_number' => $lots->first()?->cycle_number,
                'is_recovery_lot' => $lots->contains(fn ($lot) => (bool) $lot->is_recovery_average),
                'protective_exit' => (bool) data_get($metadata, 'protective_exit', false),
                'cost_ratio' => $costRatio,
                'min_net_profit_ratio' => $this->minNetProfitRatio($config),
                'cost_basis_price' => $costBasis,
                'realized_net_ratio' => $realizedNet,
                'net_profitable' => $realizedNet !== null ? $realizedNet > 0 : null,
            ], [
                'cost_ratio_source' => $costSource,
                'cost_basis_source' => $basisSource,
                'sell_lot_ids' => array_values(array_map('strval', (array) data_get($metadata, 'sell_lot_ids', []))),
                'decision_minimum_net_profit_ratio' => data_get($metadata, 'minimum_net_profit_ratio'),
            ]);
        });
    }

    // -----------------------------------------------------------------
    // Lazy evaluation (piggybacks on RunRatioBotEvaluationJob)
    // -----------------------------------------------------------------

    /**
     * Scores this bot's due `pending` rows. From the evaluation loop
     * ($force=false) it is gated by `evaluate_in_loop`, throttled to one run
     * per bot per `evaluation_throttle_seconds` (atomic Cache::add), and
     * batch-capped. Never throws.
     */
    public function evaluateDueForConfig(RatioBotConfig $config, bool $force = false, ?int $limit = null): int
    {
        try {
            if (! $force) {
                if (! (bool) config('ratio_bot_outcomes.evaluate_in_loop', true)) {
                    return 0;
                }
                $throttle = max(1, (int) config('ratio_bot_outcomes.evaluation_throttle_seconds', 60));
                if (! Cache::add(self::THROTTLE_CACHE_PREFIX . $config->id, 1, $throttle)) {
                    return 0;
                }
            }

            $limit ??= max(1, (int) config('ratio_bot_outcomes.evaluation_batch_size', 5));
            $rows = RatioBotTradeOutcome::query()
                ->where('ratio_bot_config_id', $config->id)
                ->where('evaluation_status', RatioBotTradeOutcome::STATUS_PENDING)
                ->where(fn ($q) => $q->whereNull('next_evaluation_at')->orWhere('next_evaluation_at', '<=', now()))
                ->orderBy('next_evaluation_at')
                ->limit($limit)
                ->get();

            $done = 0;
            foreach ($rows as $row) {
                $this->evaluate($row);
                $done++;
            }

            return $done;
        } catch (Throwable $e) {
            Log::warning('[RatioBotTradeOutcome] evaluation sweep failed', [
                'config_id' => $config->id,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    public function evaluate(RatioBotTradeOutcome $row, ?Carbon $now = null): RatioBotTradeOutcome
    {
        try {
            return $this->evaluateRow($row, $now ?? now());
        } catch (Throwable $e) {
            Log::warning('[RatioBotTradeOutcome] row evaluation failed', [
                'outcome_id' => $row->id,
                'error' => $e->getMessage(),
            ]);

            return $row;
        }
    }

    // -----------------------------------------------------------------
    // Reporting
    // -----------------------------------------------------------------

    /**
     * Aggregate accuracy over a pre-scoped RatioBotTradeOutcome query.
     *
     * @param  Builder<RatioBotTradeOutcome>  $query
     * @return array<string, mixed>
     */
    public function summary(Builder $query): array
    {
        $minSamples = max(1, (int) config('ratio_bot_outcomes.min_samples_for_accuracy', 30));
        $rows = $query->get([
            'side', 'evaluation_status', 'decision_correct', 'best_net_edge_ratio', 'max_adverse_ratio',
            'missed_edge_ratio', 'avoided_drawdown_ratio', 'realized_net_ratio', 'net_profitable', 'protective_exit',
        ]);

        $buys = $rows->where('side', RatioBotTradeOutcome::SIDE_BUY);
        $sells = $rows->where('side', RatioBotTradeOutcome::SIDE_SELL);

        $buyEvaluated = $buys->where('evaluation_status', RatioBotTradeOutcome::STATUS_EVALUATED)->filter(fn ($r) => $r->decision_correct !== null);
        $sellEvaluated = $sells->where('evaluation_status', RatioBotTradeOutcome::STATUS_EVALUATED)->filter(fn ($r) => $r->decision_correct !== null);
        $sellRealized = $sells->filter(fn ($r) => $r->net_profitable !== null);

        $buyGood = $buyEvaluated->filter(fn ($r) => $r->decision_correct === true)->count();
        $sellGood = $sellEvaluated->filter(fn ($r) => $r->decision_correct === true)->count();
        $sellNetProfitable = $sellRealized->filter(fn ($r) => $r->net_profitable === true)->count();

        return [
            'buy' => $this->statusCounts($buys) + [
                'good_count' => $buyGood,
                'bad_count' => $buyEvaluated->count() - $buyGood,
                'good_pct' => $this->pct($buyGood, $buyEvaluated->count(), $minSamples),
                'avg_best_net_edge_pct' => $this->avgPct($buyEvaluated, 'best_net_edge_ratio'),
                'avg_max_adverse_pct' => $this->avgPct($buyEvaluated, 'max_adverse_ratio'),
                'status' => $buyEvaluated->count() >= $minSamples ? 'observed_sample' : 'insufficient_data',
            ],
            'sell' => $this->statusCounts($sells) + [
                'well_timed_count' => $sellGood,
                'sold_early_count' => $sellEvaluated->count() - $sellGood,
                'well_timed_pct' => $this->pct($sellGood, $sellEvaluated->count(), $minSamples),
                'avg_missed_edge_pct' => $this->avgPct($sellEvaluated, 'missed_edge_ratio'),
                'avg_avoided_drawdown_pct' => $this->avgPct($sellEvaluated, 'avoided_drawdown_ratio'),
                'realized_evaluated' => $sellRealized->count(),
                'net_profitable_count' => $sellNetProfitable,
                'net_profitable_pct' => $this->pct($sellNetProfitable, $sellRealized->count(), $minSamples),
                'avg_realized_net_pct' => $this->avgPct($sellRealized, 'realized_net_ratio'),
                'protective_exit_count' => $sells->filter(fn ($r) => (bool) $r->protective_exit)->count(),
                'status' => $sellEvaluated->count() >= $minSamples ? 'observed_sample' : 'insufficient_data',
            ],
            'min_samples_for_accuracy' => $minSamples,
            'buy_horizon_hours' => (float) config('ratio_bot_outcomes.buy_horizon_hours', 24),
            'sell_lookahead_hours' => (float) config('ratio_bot_outcomes.sell_lookahead_hours', 4),
            'definitions' => [
                'buy_good' => 'Within the BUY horizon, a closed candle high (or a real sell fill of this lot) reached executed_price x (1 + cost_ratio + min_net_profit_ratio) -- the engine\'s own net-profit gate; recovery-average lots use recoveryLotMinProfitRatio().',
                'sell_well_timed' => 'Within the SELL lookahead window, the best closed-candle high exceeded the sell price by no more than one round-trip cost_ratio.',
                'net_profitable' => 'sell_price / cost_basis - 1 - cost_ratio > 0, cost basis from the lots this sell consumed.',
            ],
            'note' => 'Observation only -- never read by any trading decision. Pending/insufficient_data/not_applicable rows are excluded from every percentage, never counted as wrong. Percentages are null below min_samples_for_accuracy.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentRow(RatioBotTradeOutcome $row): array
    {
        return [
            'id' => (string) $row->id,
            'ratio_bot_config_id' => (string) $row->ratio_bot_config_id,
            'ratio_bot_decision_id' => (string) $row->ratio_bot_decision_id,
            'ratio_bot_lot_id' => $row->ratio_bot_lot_id !== null ? (string) $row->ratio_bot_lot_id : null,
            'side' => $row->side,
            'execution_mode' => $row->execution_mode,
            'trigger' => $row->trigger,
            'stage_index' => $row->stage_index,
            'is_recovery_lot' => (bool) $row->is_recovery_lot,
            'protective_exit' => (bool) $row->protective_exit,
            'symbol' => $row->symbol,
            'timeframe' => $row->timeframe,
            'executed_price' => (float) $row->executed_price,
            'executed_qty' => (float) $row->executed_qty,
            'executed_at' => optional($row->executed_at)->toIso8601String(),
            'cost_ratio' => $this->floatOrNull($row->cost_ratio),
            'required_exit_ratio' => $this->floatOrNull($row->required_exit_ratio),
            'cost_basis_price' => $this->floatOrNull($row->cost_basis_price),
            'realized_net_ratio' => $this->floatOrNull($row->realized_net_ratio),
            'net_profitable' => $row->net_profitable,
            'horizon_seconds' => $row->horizon_seconds,
            'window_ends_at' => optional($row->window_ends_at)->toIso8601String(),
            'evaluation_status' => $row->evaluation_status,
            'status_reason' => $row->status_reason,
            'verdict' => $row->verdict,
            'decision_correct' => $row->decision_correct,
            'best_price' => $this->floatOrNull($row->best_price),
            'worst_price' => $this->floatOrNull($row->worst_price),
            'best_net_edge_ratio' => $this->floatOrNull($row->best_net_edge_ratio),
            'max_adverse_ratio' => $this->floatOrNull($row->max_adverse_ratio),
            'missed_edge_ratio' => $this->floatOrNull($row->missed_edge_ratio),
            'avoided_drawdown_ratio' => $this->floatOrNull($row->avoided_drawdown_ratio),
            'reached_floor_at' => optional($row->reached_floor_at)->toIso8601String(),
            'candles_observed' => $row->candles_observed,
            'candles_expected' => $row->candles_expected,
            'evaluated_at' => optional($row->evaluated_at)->toIso8601String(),
        ];
    }

    /**
     * Parity-tested mirror of RatioBotDecisionService::executionCostRatio()
     * (private there, deliberately untouched). The formula is the one this
     * codebase already uses in RatioBotDecisionService, RatioBotRiskService
     * and RatioBotConfig::recoveryLotMinProfitRatio(); the test suite
     * asserts this returns the engine's exact value, so any drift fails CI.
     */
    public function executionCostRatio(RatioBotConfig $config): float
    {
        $feeBps = max(0.0, (float) ($config->fee_bps ?? 0));
        $slippageBps = max(0.0, (float) ($config->max_slippage_bps ?? 0));

        return (($feeBps * 2) + $slippageBps) / 10000;
    }

    /**
     * Candle window: candles that OPEN at/after the fill (so no pre-fill
     * price inside the fill's own candle can leak in) and before
     * executed_at + horizon; always at least one full candle.
     *
     * @return array{first_open: int, last_open: int, expected: int, ends_at: int}
     */
    public function window(int $executedAtTs, int $horizonSeconds, int $tfSeconds): array
    {
        $tf = max(1, $tfSeconds);
        $firstOpen = (int) (ceil($executedAtTs / $tf) * $tf);
        $end = max($executedAtTs + max(1, $horizonSeconds), $firstOpen + $tf);
        $lastOpen = (int) (floor(($end - 1) / $tf) * $tf);
        if ($lastOpen < $firstOpen) {
            $lastOpen = $firstOpen;
        }

        return [
            'first_open' => $firstOpen,
            'last_open' => $lastOpen,
            'expected' => intdiv($lastOpen - $firstOpen, $tf) + 1,
            'ends_at' => $lastOpen + $tf,
        ];
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    private function evaluateRow(RatioBotTradeOutcome $row, Carbon $now): RatioBotTradeOutcome
    {
        if ($row->evaluation_status !== RatioBotTradeOutcome::STATUS_PENDING) {
            return $row;
        }

        $tfSeconds = $this->timeframeSeconds((string) $row->timeframe);
        $entry = (float) $row->executed_price;
        if ($tfSeconds === null || $row->executed_at === null || $entry <= 0) {
            $row->update([
                'evaluation_status' => $tfSeconds === null ? RatioBotTradeOutcome::STATUS_INSUFFICIENT_DATA : RatioBotTradeOutcome::STATUS_NOT_APPLICABLE,
                'status_reason' => $tfSeconds === null ? 'unsupported_timeframe' : 'invalid_fill',
                'evaluated_at' => $now,
            ]);

            return $row;
        }

        $window = $this->window($row->executed_at->getTimestamp(), (int) $row->horizon_seconds, $tfSeconds);
        $grace = max(0, (int) config('ratio_bot_outcomes.close_grace_seconds', 120));
        if ($now->getTimestamp() < $window['ends_at'] + $grace) {
            // Bounded window still open -- honest "not yet evaluable".
            $row->update(['next_evaluation_at' => Carbon::createFromTimestamp($window['ends_at'] + $grace)]);

            return $row;
        }

        $candles = MarketOhlc::query()
            ->where('exchange', $row->exchange)
            ->where('symbol', $row->symbol)
            ->where('timeframe', $row->timeframe)
            ->where('ts', '>=', Carbon::createFromTimestamp($window['first_open']))
            ->where('ts', '<=', Carbon::createFromTimestamp($window['last_open']))
            ->orderBy('ts')
            ->get(['high', 'low', 'ts']);

        $observed = $candles->count();
        $coverage = $window['expected'] > 0 ? $observed / $window['expected'] : 0.0;
        $minCoverage = min(1.0, max(0.0, (float) config('ratio_bot_outcomes.min_candle_coverage', 0.8)));
        $sufficient = $observed > 0 && $coverage >= $minCoverage;
        $best = $observed > 0 ? (float) $candles->max(fn ($c) => (float) $c->high) : null;
        $worst = $observed > 0 ? (float) $candles->min(fn ($c) => (float) $c->low) : null;
        $attempts = (int) $row->evaluation_attempts + 1;

        $base = [
            'candles_observed' => $observed,
            'candles_expected' => $window['expected'],
            'evaluation_attempts' => $attempts,
            'window_ends_at' => Carbon::createFromTimestamp($window['ends_at']),
        ];

        if ($row->side === RatioBotTradeOutcome::SIDE_BUY) {
            $floorPrice = $entry * (1 + (float) $row->required_exit_ratio);
            $reachedAt = null;
            $firstHit = $candles->first(fn ($c) => (float) $c->high >= $floorPrice);
            if ($firstHit) {
                $reachedAt = Carbon::parse($firstHit->ts);
            }
            // A real sell fill of this lot inside the window is real traded
            // price evidence too (robust to candle gaps).
            foreach ($this->lotSellFillsInWindow($row, $window['ends_at']) as $fill) {
                $best = $best === null ? $fill['price'] : max($best, $fill['price']);
                if ($fill['price'] >= $floorPrice && ($reachedAt === null || $fill['at']->lt($reachedAt))) {
                    $reachedAt = $fill['at'];
                }
            }

            if ($reachedAt === null && ! $sufficient) {
                return $this->deferOrGiveUp($row, $base, $attempts, $now, $best, $worst);
            }

            $row->update($base + [
                'evaluation_status' => RatioBotTradeOutcome::STATUS_EVALUATED,
                'status_reason' => $reachedAt !== null ? ($sufficient ? null : 'positive_evidence_with_partial_coverage') : null,
                'verdict' => $reachedAt !== null ? RatioBotTradeOutcome::VERDICT_BUY_RECOVERED : RatioBotTradeOutcome::VERDICT_BUY_NOT_RECOVERED,
                'decision_correct' => $reachedAt !== null,
                'best_price' => $best,
                'worst_price' => $worst,
                'best_net_edge_ratio' => $best !== null ? ($best / $entry) - 1 - (float) $row->cost_ratio : null,
                'max_adverse_ratio' => $worst !== null ? ($worst / $entry) - 1 : null,
                'reached_floor_at' => $reachedAt,
                'evaluated_at' => $now,
                'next_evaluation_at' => null,
            ]);

            return $row;
        }

        if (! $sufficient) {
            return $this->deferOrGiveUp($row, $base, $attempts, $now, $best, $worst);
        }

        $missed = max(0.0, ($best / $entry) - 1);
        $wellTimed = $missed <= (float) $row->cost_ratio;
        $row->update($base + [
            'evaluation_status' => RatioBotTradeOutcome::STATUS_EVALUATED,
            'status_reason' => null,
            'verdict' => $wellTimed ? RatioBotTradeOutcome::VERDICT_SELL_WELL_TIMED : RatioBotTradeOutcome::VERDICT_SELL_EARLY,
            'decision_correct' => $wellTimed,
            'best_price' => $best,
            'worst_price' => $worst,
            'missed_edge_ratio' => $missed,
            'avoided_drawdown_ratio' => max(0.0, 1 - ($worst / $entry)),
            'evaluated_at' => $now,
            'next_evaluation_at' => null,
        ]);

        return $row;
    }

    /**
     * Window closed but candle coverage is short: retry a few times (a
     * backfill may close the gap), then settle on insufficient_data --
     * never a guessed verdict.
     */
    private function deferOrGiveUp(RatioBotTradeOutcome $row, array $base, int $attempts, Carbon $now, ?float $best, ?float $worst): RatioBotTradeOutcome
    {
        $maxAttempts = max(1, (int) config('ratio_bot_outcomes.max_evaluation_attempts', 3));
        if ($attempts < $maxAttempts) {
            $row->update($base + [
                'status_reason' => 'awaiting_candle_coverage',
                'next_evaluation_at' => $now->copy()->addSeconds(max(60, (int) config('ratio_bot_outcomes.recheck_interval_seconds', 300))),
            ]);

            return $row;
        }

        $row->update($base + [
            'evaluation_status' => RatioBotTradeOutcome::STATUS_INSUFFICIENT_DATA,
            'status_reason' => $base['candles_observed'] === 0 ? 'no_candles_in_window' : 'insufficient_candle_coverage',
            'best_price' => $best,
            'worst_price' => $worst,
            'evaluated_at' => $now,
            'next_evaluation_at' => null,
        ]);

        return $row;
    }

    /**
     * @return list<array{price: float, at: Carbon}>
     */
    private function lotSellFillsInWindow(RatioBotTradeOutcome $row, int $windowEndTs): array
    {
        if ($row->ratio_bot_lot_id === null || $row->executed_at === null) {
            return [];
        }
        $lot = RatioBotLot::query()->whereKey($row->ratio_bot_lot_id)->first();
        if (! $lot) {
            return [];
        }

        $fills = [];
        foreach ((array) ($lot->sell_fills ?? []) as $fill) {
            $price = (float) ($fill['price'] ?? 0);
            $soldAt = isset($fill['sold_at']) ? Carbon::parse((string) $fill['sold_at']) : null;
            if ($price <= 0 || $soldAt === null) {
                continue;
            }
            if ($soldAt->getTimestamp() < $row->executed_at->getTimestamp() || $soldAt->getTimestamp() > $windowEndTs) {
                continue;
            }
            $fills[] = ['price' => $price, 'at' => $soldAt];
        }

        return $fills;
    }

    private function shouldCapture(RatioBotConfig $config, RatioBotDecision $decision, string $side): bool
    {
        if (! (bool) config('ratio_bot_outcomes.capture_enabled', true)) {
            return false;
        }
        if ($config->marketType() !== 'spot' || $decision->decision !== $side || ! $decision->exists) {
            return false;
        }

        return ! RatioBotTradeOutcome::query()->where('ratio_bot_decision_id', $decision->id)->exists();
    }

    private function persist(
        RatioBotConfig $config,
        RatioBotDecision $decision,
        string $side,
        float $executedPrice,
        float $executedQty,
        string $orderId,
        array $columns,
        array $metadata,
    ): RatioBotTradeOutcome {
        $executedAt = now();
        $horizonHours = $side === RatioBotTradeOutcome::SIDE_BUY
            ? (float) config('ratio_bot_outcomes.buy_horizon_hours', 24)
            : (float) config('ratio_bot_outcomes.sell_lookahead_hours', 4);
        $horizonSeconds = max(60, (int) round($horizonHours * 3600));
        $timeframe = $config->timeFrame();
        $tfSeconds = $this->timeframeSeconds($timeframe);

        $status = RatioBotTradeOutcome::STATUS_PENDING;
        $reason = null;
        $windowEndsAt = null;
        $evaluateAfter = null;
        $expected = null;
        if ($executedPrice <= 0 || $executedQty <= 0) {
            $status = RatioBotTradeOutcome::STATUS_NOT_APPLICABLE;
            $reason = 'invalid_fill';
        } elseif ($tfSeconds === null) {
            $status = RatioBotTradeOutcome::STATUS_INSUFFICIENT_DATA;
            $reason = 'unsupported_timeframe';
        } else {
            $window = $this->window($executedAt->getTimestamp(), $horizonSeconds, $tfSeconds);
            $windowEndsAt = Carbon::createFromTimestamp($window['ends_at']);
            $evaluateAfter = $windowEndsAt->copy()->addSeconds(max(0, (int) config('ratio_bot_outcomes.close_grace_seconds', 120)));
            $expected = $window['expected'];
        }

        return RatioBotTradeOutcome::create($columns + [
            'ratio_bot_config_id' => $config->id,
            'ratio_bot_decision_id' => $decision->id,
            'side' => $side,
            'execution_mode' => $config->execution_mode,
            'trigger' => ($t = data_get($decision->metadata, 'trigger')) !== null ? substr((string) $t, 0, 64) : null,
            'order_id' => $orderId !== '' ? substr($orderId, 0, 128) : null,
            'exchange' => $config->exchangeKey(),
            'symbol' => $config->normalizedSymbol(),
            'timeframe' => $timeframe,
            'executed_price' => $executedPrice,
            'executed_qty' => $executedQty,
            'executed_at' => $executedAt,
            'horizon_seconds' => $horizonSeconds,
            'window_ends_at' => $windowEndsAt,
            'evaluate_after' => $evaluateAfter,
            'next_evaluation_at' => $evaluateAfter,
            'evaluation_status' => $status,
            'status_reason' => $reason,
            'candles_expected' => $expected,
            'evaluated_at' => $status === RatioBotTradeOutcome::STATUS_PENDING ? null : $executedAt,
            'metadata' => $metadata,
        ]);
    }

    /**
     * @return array{0: float|null, 1: string, 2: Collection<int, RatioBotLot>}
     */
    private function sellCostBasis(RatioBotConfig $config, RatioBotDecision $decision, array $metadata, float $fallback): array
    {
        $lotIds = array_values(array_filter(array_map('strval', (array) ($metadata['sell_lot_ids'] ?? []))));
        $lots = $lotIds === []
            ? collect()
            : RatioBotLot::query()->where('ratio_bot_config_id', $config->id)->whereIn('id', $lotIds)->get();

        $qty = 0.0;
        $cost = 0.0;
        foreach ($lots as $lot) {
            foreach ((array) ($lot->sell_fills ?? []) as $fill) {
                if ((string) ($fill['decision_id'] ?? '') !== (string) $decision->id) {
                    continue;
                }
                $fillQty = (float) ($fill['qty'] ?? 0);
                $qty += $fillQty;
                $cost += (float) $lot->buy_price * $fillQty;
            }
        }

        if ($qty > 0) {
            return [$cost / $qty, 'lot_sell_fills', $lots];
        }
        if ($fallback > 0) {
            return [$fallback, 'state_weighted_entry', $lots];
        }

        return [null, 'unavailable', $lots];
    }

    /**
     * @return array{0: float, 1: string}
     */
    private function costRatioFor(RatioBotConfig $config, array $metadata): array
    {
        $stamped = $metadata['cost_ratio'] ?? null;
        if (is_numeric($stamped) && (float) $stamped >= 0) {
            return [(float) $stamped, 'decision_metadata'];
        }

        return [$this->executionCostRatio($config), 'config_fee_slippage'];
    }

    private function minNetProfitRatio(RatioBotConfig $config): float
    {
        return max(0.0, (float) ($config->min_net_profit_bps ?? 0) / 10000);
    }

    private function timeframeSeconds(string $timeframe): ?int
    {
        try {
            return app(TimeframeRegistry::class)->seconds($timeframe);
        } catch (Throwable) {
            return null;
        }
    }

    private function statusCounts(Collection $rows): array
    {
        return [
            'captured' => $rows->count(),
            'pending' => $rows->where('evaluation_status', RatioBotTradeOutcome::STATUS_PENDING)->count(),
            'evaluated' => $rows->where('evaluation_status', RatioBotTradeOutcome::STATUS_EVALUATED)->count(),
            'insufficient_data' => $rows->where('evaluation_status', RatioBotTradeOutcome::STATUS_INSUFFICIENT_DATA)->count(),
            'not_applicable' => $rows->where('evaluation_status', RatioBotTradeOutcome::STATUS_NOT_APPLICABLE)->count(),
        ];
    }

    private function pct(int $numerator, int $denominator, int $minSamples): ?float
    {
        return $denominator >= $minSamples && $denominator > 0
            ? round($numerator / $denominator * 100, 2)
            : null;
    }

    private function avgPct(Collection $rows, string $column): ?float
    {
        $values = $rows->pluck($column)->filter(fn ($v) => $v !== null)->map(fn ($v) => (float) $v);

        return $values->isEmpty() ? null : round($values->avg() * 100, 4);
    }

    private function floatOrNull(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    private function safely(callable $callback): ?RatioBotTradeOutcome
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            Log::warning('[RatioBotTradeOutcome] capture failed (observation only, trading unaffected)', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
