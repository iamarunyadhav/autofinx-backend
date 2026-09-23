<?php

namespace App\Domain\Trading;

use App\Domain\MarketIntelligence\UnifiedForecastEngine;
use App\Domain\MarketIntelligence\UnifiedForecastLiveGate;
use App\Domain\Ops\FeatureFlagService;
use App\Models\QuickTrade;
use App\Models\QuickTradeResearchSample;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Observational only: no trading decisions, settlement or model promotion. */
class QuickTradeResearchService
{
    public function capture(array $candles, array $signal, array $payload): array
    {
        $captured = now()->toIso8601String();
        $result = ['schema_version' => 1, 'captured_at' => $captured, 'origin' => 'server_pre_entry',
            'mode' => 'shadow_only', 'config' => Arr::only($payload, [
                'symbol', 'timeframe', 'expiry_seconds', 'stake_amount', 'side', 'mode', 'provider',
                'indicator_set', 'stop_loss_pct', 'take_profit_pct', 'leverage_enabled', 'leverage',
            ]), 'primary' => null, 'shadow' => null, 'decision_source' => 'internal'];
        try {
            $forecasts = app(TradeForecastService::class);
            // Part B1: the entry snapshot's decision is computed from CLOSED
            // candles only, exactly like the /quick-trade/forecast preview the
            // user was looking at -- otherwise the recorded "prediction at
            // entry" would be a different computation from the one shown.
            $timing = app(CandleCloseResolver::class)->resolve($candles, (string) $payload['timeframe']);
            $decisionCandles = $timing['candles'];
            $result['primary'] = $forecasts->forecast($decisionCandles, $payload['timeframe'],
                (int) $payload['expiry_seconds'], $forecasts->decisionFromSignalPreview($signal),
                null, null, isset($payload['symbol']) ? (string) $payload['symbol'] : null, null, $timing);
            $result['input_candles'] = array_map(fn ($row) => Arr::only($row,
                ['timestamp', 'time', 'opens_at', 'open', 'high', 'low', 'close', 'volume']), array_slice($decisionCandles, -100));
            $result['input_candles_include_forming_bar'] = (bool) ($timing['include_forming_bar'] ?? true);
            // Part B3 -- entry timing fields.
            $result['decision_candle_close_at'] = $timing['decision_candle_close_at'];
            $result['seconds_since_last_close'] = $timing['seconds_since_last_close'];
            $result['candle_timing'] = Arr::only($timing, [
                'timeframe_seconds', 'grace_ms', 'within_grace', 'candle_gap',
                'forming_bar_dropped', 'timestamps_available',
            ]);
            $result['market_exchange'] = (string) config('unified_forecast.quick_trade_default_exchange', 'binance');
            $isLiveBinance = ($payload['mode'] ?? 'paper') === 'live' && ($payload['provider'] ?? 'binance') === 'binance';
            $botInstanceId = $result['market_exchange'].':'.$payload['symbol'].':'.$payload['timeframe'];
            $shadowEngineResult = null;
            if ($isLiveBinance && app(FeatureFlagService::class)->enabled('unified_forecast.quick_trade_shadow', true)) {
                $shadowEngineResult = app(UnifiedForecastEngine::class)->evaluate('quick_trade',
                    $botInstanceId, $result['market_exchange'], $payload['symbol'], $payload['timeframe'], (int) $payload['expiry_seconds']);
                $result['shadow'] = array_merge($shadowEngineResult, []);
                $result['shadow']['horizon_seconds'] = (int) $payload['expiry_seconds'];
                $result['shadow']['current_price'] = $result['primary']['current_price'] ?? null;
                $result['shadow']['price_target_available'] = false;
            }

            // Unified Forecast Engine, Phase 6 -- LIVE decision wiring for the
            // entry_prediction_snapshot pathway (`unified_forecast.quick_trade_live`,
            // default OFF). Same triple-fail-closed priority as the
            // /quick-trade/forecast preview endpoint's own wiring
            // (QuickTradeController::applyUnifiedForecastLiveDecision()): a
            // data-quality gate that already forced WAIT inside forecast()
            // itself is authoritative and is never second-guessed here.
            // Scoped to live+binance only, matching the shadow block above --
            // the engine's calibration evidence is only ever built from real
            // (execution_environment==='live') settled trades, so evaluating
            // it for a paper-mode capture would be gating live automation on
            // paper-mode inputs it was never validated against.
            if ($isLiveBinance && isset($payload['symbol']) && ($result['primary']['gate_reason'] ?? null) === null) {
                $exchange = $result['market_exchange'];
                $symbol = (string) $payload['symbol'];
                $timeframe = (string) $payload['timeframe'];
                $horizonSeconds = (int) $payload['expiry_seconds'];

                $liveDecision = app(UnifiedForecastLiveGate::class)->resolve(
                    'unified_forecast.quick_trade_live',
                    'quick_trade',
                    $botInstanceId,
                    $exchange,
                    $symbol,
                    $timeframe,
                    $horizonSeconds,
                    fn () => app(ForecastLiveEvidenceService::class)->quickTradeHoldoutPnlSummary($symbol, $timeframe, $horizonSeconds),
                    $shadowEngineResult,
                );

                $result['decision_source'] = $liveDecision['decision_source'];
                if ($liveDecision['decision_source'] === 'unified_forecast_engine' && is_array($liveDecision['engine_result'])) {
                    $engine = $liveDecision['engine_result'];
                    $direction = strtoupper((string) ($engine['direction'] ?? 'NEUTRAL'));
                    $result['primary']['decision_trend'] = in_array($direction, ['BUY', 'SELL'], true) ? $direction : 'WAIT';
                    if (($engine['confidence'] ?? null) !== null) {
                        $confidence = round((float) $engine['confidence'], 1);
                        $result['primary']['confidence'] = $confidence;
                        $result['primary']['confidence_status'] = $confidence >= 75 ? 'medium_high' : ($confidence >= 55 ? 'medium' : 'low');
                    }
                    $result['unified_forecast_decision'] = $engine;
                }
            }
        } catch (Throwable $e) {
            $result['capture_status'] = 'partial';
            $result['capture_reason'] = 'forecast_capture_failed';
        }
        // --- Part A3: the two predictions, stored separately at ENTRY time --
        // Copied verbatim from the primary forecast's own `direction` /
        // `trade_decision` blocks (which are themselves views onto the single
        // evaluation above) -- nothing is recomputed here, so the stored
        // prediction can never drift from the one the user was shown.
        $result['direction_prediction'] = $this->directionPrediction($result['primary'] ?? null);
        $result['trade_decision_prediction'] = $this->tradeDecisionPrediction($result['primary'] ?? null);

        $result['collect_until_ms'] = CarbonImmutable::parse($captured)->addSeconds(
            max((int) $payload['expiry_seconds'], (int) ($result['primary']['horizon_seconds'] ?? 0)) + 10)->getTimestampMs();
        return $result;
    }

    /**
     * @param  array<string,mixed>|null  $forecast
     * @return array{value:string|null, expiry_score:float|null, expected_move_pct:float|null, fee_aware:bool, available:bool}
     */
    public function directionPrediction(?array $forecast): array
    {
        $block = is_array($forecast['direction'] ?? null) ? $forecast['direction'] : null;
        // Fall back to the legacy `bias` field so snapshots captured before
        // Part A landed still evaluate, rather than being silently dropped.
        $value = $block['value'] ?? ($forecast['bias'] ?? null);

        return [
            'value' => is_string($value) ? strtoupper($value) : null,
            'expiry_score' => isset($block['expiry_score']) ? (float) $block['expiry_score'] : null,
            'expected_move_pct' => $block['expected_move_pct'] ?? ($forecast['predicted_move_pct'] ?? null),
            'fee_aware' => false,
            'available' => $value !== null,
        ];
    }

    /**
     * @param  array<string,mixed>|null  $forecast
     * @return array{value:string|null, required_move_pct:float|null, max_achievable_move_pct:float|null,
     *               cost_pct:float|null, gate_reason:string|null, fee_model:string|null, fee_aware:bool, available:bool}
     */
    public function tradeDecisionPrediction(?array $forecast): array
    {
        $block = is_array($forecast['trade_decision'] ?? null) ? $forecast['trade_decision'] : null;
        $value = $block['value'] ?? ($forecast['decision_trend'] ?? null);

        return [
            'value' => is_string($value) ? strtoupper($value) : null,
            'required_move_pct' => isset($block['required_move_pct']) ? (float) $block['required_move_pct'] : null,
            'max_achievable_move_pct' => isset($block['max_achievable_move_pct']) ? (float) $block['max_achievable_move_pct'] : null,
            'cost_pct' => isset($block['cost_pct']) ? (float) $block['cost_pct'] : null,
            'gate_reason' => $block['gate_reason'] ?? ($forecast['gate_reason'] ?? null),
            'fee_model' => $block['fee_model'] ?? null,
            'fee_aware' => true,
            'available' => $value !== null,
        ];
    }

    /**
     * Part A3 -- post-settlement evaluation of the TWO predictions,
     * separately and honestly.
     *
     *  direction_correct       sign of (exit - entry) vs the cost-blind
     *                          direction. A NEUTRAL direction is
     *                          `not_applicable`, NEVER counted as false.
     *  trade_decision_correct  for a BUY/SELL decision: did the trade book a
     *                          positive NET P&L. For WAIT: not applicable
     *                          here -- correctness of a WAIT is only
     *                          answerable from the shadow evaluation (A4),
     *                          which is reported separately as
     *                          wait_shadow_* rather than folded in as a
     *                          pass/fail.
     *
     * Returns null-valued fields (never false) whenever the data to judge is
     * missing -- the existing "unknown is excluded, never counted as false"
     * convention this file already uses everywhere.
     *
     * @param  array<string,mixed>  $research
     * @return array<string,mixed>
     */
    public function evaluatePredictions(array $research, ?float $entryPrice, ?float $exitPrice, ?float $netPnl, bool $closed): array
    {
        $direction = is_array($research['direction_prediction'] ?? null)
            ? $research['direction_prediction']
            : $this->directionPrediction($research['primary'] ?? null);
        $tradeDecision = is_array($research['trade_decision_prediction'] ?? null)
            ? $research['trade_decision_prediction']
            : $this->tradeDecisionPrediction($research['primary'] ?? null);

        $directionValue = $direction['value'] ?? null;
        $decisionValue = $tradeDecision['value'] ?? null;

        $actualDirection = $closed && $entryPrice !== null && $exitPrice !== null && $entryPrice > 0
            ? $this->direction($entryPrice, $exitPrice)
            : null;

        $directionCorrect = null;
        $directionStatus = 'unavailable';
        if ($directionValue === 'NEUTRAL') {
            $directionStatus = 'not_applicable';
        } elseif (in_array($directionValue, ['BUY', 'SELL'], true) && $actualDirection !== null) {
            $directionCorrect = strtolower($directionValue) === $actualDirection;
            $directionStatus = 'evaluated';
        }

        $tradeDecisionCorrect = null;
        $tradeDecisionStatus = 'unavailable';
        if ($decisionValue === 'WAIT') {
            // A WAIT was not a trade; its correctness lives in the shadow
            // evaluation (A4), never in this pass/fail column.
            $tradeDecisionStatus = 'not_applicable_wait';
        } elseif (in_array($decisionValue, ['BUY', 'SELL'], true) && $closed && $netPnl !== null) {
            $tradeDecisionCorrect = $netPnl > 0;
            $tradeDecisionStatus = 'evaluated';
        }

        return [
            'direction_prediction' => $direction,
            'trade_decision_prediction' => $tradeDecision,
            'actual_direction' => $actualDirection,
            'direction_correct' => $directionCorrect,
            'direction_status' => $directionStatus,
            'trade_decision_correct' => $tradeDecisionCorrect,
            'trade_decision_status' => $tradeDecisionStatus,
        ];
    }

    public function report(QuickTrade $trade): array
    {
        $payload = (array) $trade->signal_payload;
        $research = (array) ($payload['research'] ?? []);
        $cost = (array) ($payload['execution_cost'] ?? []);
        $samples = Schema::hasTable('quick_trade_research_samples')
            ? QuickTradeResearchSample::where('quick_trade_id', $trade->id)->orderBy('quote_time_ms')->get() : collect();
        $rows = $samples->map(fn ($row) => ['time' => CarbonImmutable::createFromTimestampMs($row->quote_time_ms)->toIso8601String(),
            'time_ms' => $row->quote_time_ms, 'price' => (float) $row->price, 'source' => $row->source,
            'kind' => 'sampled_quote', 'recorded_at' => $row->recorded_at->toIso8601String()])->all();
        $closed = $trade->status !== 'open' && $trade->settled_at !== null && (float) $trade->exit_price > 0;
        $direction = $closed ? $this->direction((float) $trade->entry_price, (float) $trade->exit_price) : null;
        $signal = $this->normalize($payload['direction'] ?? null);
        $primary = $this->comparison($research['primary'] ?? null, $research['captured_at'] ?? null, $rows);
        $shadow = $this->comparison($research['shadow'] ?? null, $research['captured_at'] ?? null, $rows);
        $predictions = $this->evaluatePredictions(
            $research,
            (float) $trade->entry_price,
            $closed ? (float) $trade->exit_price : null,
            $closed ? (float) $trade->profit_loss : null,
            $closed,
        );
        $timeline = [['time' => $trade->entry_at?->toIso8601String(), 'time_ms' => $trade->entry_at?->getTimestampMs(),
            'price' => (float) $trade->entry_price, 'kind' => 'entry_fill', 'source' => 'stored_trade_execution']];
        foreach ($rows as $row) {
            $delta = ($row['price'] - (float) $trade->entry_price) * ($trade->side === 'sell' ? -1 : 1);
            $row['position_open_at_quote'] = ! $closed || $row['time_ms'] <= $trade->settled_at->getTimestampMs();
            $row['gross_pnl_at_quote'] = $row['position_open_at_quote'] && $trade->mode === 'live' && (float) $trade->quantity > 0
                ? round($delta * (float) $trade->quantity, 8) : null;
            $row['direction_from_entry'] = $this->direction((float) $trade->entry_price, $row['price']);
            $timeline[] = $row;
        }
        if ($closed) {
            $timeline[] = ['time' => $trade->settled_at->toIso8601String(), 'time_ms' => $trade->settled_at->getTimestampMs(),
                'price' => (float) $trade->exit_price, 'kind' => 'exit_fill', 'source' => 'stored_trade_execution'];
        }
        usort($timeline, fn ($a, $b) => ($a['time_ms'] ?? 0) <=> ($b['time_ms'] ?? 0));
        return [
            'schema_version' => 1, 'trade_id' => $trade->id, 'symbol' => $trade->symbol,
            'mode' => $trade->mode, 'source' => $trade->source, 'status' => $trade->status,
            'execution_environment' => $research['execution_environment'] ?? ($trade->mode === 'paper' ? 'paper' : 'unknown'),
            'quote_currency' => $trade->quote_currency, 'side' => $trade->side,
            'entry_at' => $trade->entry_at?->toIso8601String(), 'expires_at' => $trade->expires_at?->toIso8601String(),
            'settled_at' => $trade->settled_at?->toIso8601String(), 'entry_price' => (float) $trade->entry_price,
            'exit_price' => $closed ? (float) $trade->exit_price : null,
            'quantity' => $trade->quantity !== null ? (float) $trade->quantity : null,
            'stake_amount' => (float) $trade->stake_amount, 'leverage' => $trade->leverage,
            'timeframe' => $trade->timeframe, 'expiry_seconds' => $trade->expiry_seconds,
            'stop_loss_pct' => $trade->stop_loss_pct, 'take_profit_pct' => $trade->take_profit_pct,
            'stop_loss_triggered_at' => $trade->stop_loss_triggered_at?->toIso8601String(),
            'take_profit_triggered_at' => $trade->take_profit_triggered_at?->toIso8601String(),
            'fees' => [
                'source' => $trade->fee_source ?? $cost['fee_source'] ?? 'unavailable',
                'entry_actual' => $trade->entry_fee_actual, 'exit_actual' => $trade->exit_fee_actual,
                'funding_actual_cost' => $trade->funding_fee_actual,
                'entry_used' => $cost['entry_fee'] ?? null, 'exit_used' => $cost['exit_fee'] ?? null,
                'funding_used' => $cost['funding_fee'] ?? null, 'total_used' => $trade->fees_total,
            ],
            'gross_pnl' => $closed ? ($trade->gross_pnl ?? $cost['gross_pnl'] ?? null) : null,
            'net_pnl_booked' => $closed ? (float) $trade->profit_loss : null,
            'trade_result' => $closed ? ((float) $trade->profit_loss > 0 ? 'profit' : ((float) $trade->profit_loss < 0 ? 'loss' : 'break_even')) : 'open',
            'net_profit_positive' => $closed ? (float) $trade->profit_loss > 0 : null,
            'gross_profit_positive' => $closed && $trade->gross_pnl !== null ? (float) $trade->gross_pnl > 0 : null,
            'stake_plus_booked_net' => $closed ? round((float) $trade->stake_amount + (float) $trade->profit_loss, 8) : null,
            'payout_booked' => $closed ? $trade->payout_amount : null,
            'slippage' => Arr::only($cost, ['entry_slippage_pct', 'exit_slippage_pct', 'entry_reference_price', 'exit_reference_price']),
            'slippage_already_in_fill_pnl' => true,
            'entry_signal' => Arr::only($payload, ['direction', 'confidence', 'reasons', 'bullish_score', 'bearish_score', 'neutral_score', 'indicator_values']),
            'prediction_direction' => $signal,
            'prediction_confidence' => isset($payload['confidence']) ? (float) $payload['confidence'] : null,
            'indicators_at_entry' => $trade->indicator_snapshot,
            'trade_exit_direction' => $direction,
            'actual_trade_direction' => $direction,
            'direction_match_at_trade_exit' => $signal !== null && $direction !== null ? $signal === $direction : null,
            'entry_signal_correct_at_trade_exit' => $signal !== null && $direction !== null ? $signal === $direction : null,
            'prediction_horizon_direction' => $primary['actual_direction'] ?? null,
            'prediction_horizon_direction_correct' => $primary['direction_correct'] ?? null,
            // Async evaluator writes this after settlement without touching
            // the execution path. Keep it separate from the legacy comparison
            // so partial/missing historical samples remain explicit.
            'outcome_evaluation' => $research['outcome_evaluation'] ?? null,
            // --- Part A3: both predictions, stored at entry and evaluated
            // separately after settlement. `gross_direction_correct` and
            // `net_profit_positive` stay as their own separate fields above --
            // these are additive, not a replacement.
            'direction_prediction' => $predictions['direction_prediction'],
            'trade_decision_prediction' => $predictions['trade_decision_prediction'],
            'direction_correct' => $predictions['direction_correct'],
            'direction_evaluation_status' => $predictions['direction_status'],
            'trade_decision_correct' => $predictions['trade_decision_correct'],
            'trade_decision_evaluation_status' => $predictions['trade_decision_status'],
            'gross_direction_correct' => $closed && $trade->gross_pnl !== null && $signal !== null && $direction !== null
                ? $signal === $direction : null,
            // Part A4 -- what a trade in the DIRECTION would have netted at
            // expiry, recorded whether or not the user actually traded.
            'wait_shadow' => $research['wait_shadow'] ?? null,
            // Part A5 -- did the user act against the fee-aware decision.
            'manual_override' => $trade->manual_action_disagreed_with_prediction,
            'entry_fill_type' => $research['entry_fill_type'] ?? null,
            'limit_unfilled' => $research['limit_unfilled'] ?? null,
            'fee_rate_source' => $trade->fee_source === 'real' ? 'account' : ($research['primary']['trade_decision']['fee_rate_source'] ?? 'config'),
            'decision_candle_close_at' => $research['decision_candle_close_at'] ?? null,
            'seconds_since_last_close' => $research['seconds_since_last_close'] ?? null,
            'input_candles_include_forming_bar' => $research['input_candles_include_forming_bar'] ?? null,
            'research_snapshot' => $research ?: null, 'primary_comparison' => $primary, 'shadow_comparison' => $shadow,
            'timeline' => $timeline,
            'data_quality' => ['snapshot_available' => $research !== [], 'sample_count' => count($rows),
                'price_history' => $rows === [] ? 'entry_exit_only_no_intratrade_samples' : 'sampled_not_every_tick',
                'paper_is_synthetic' => $trade->mode !== 'live',
                'training_eligible' => $trade->mode === 'live' && ($research['execution_environment'] ?? '') === 'live'
                    && $closed && $primary['status'] === 'evaluated'
                    && ($trade->fee_source ?? '') === 'real',
                'notes' => 'Features are pre-entry snapshots; comparisons/PnL/timeline are post-entry labels. Split chronologically; do not train features on future outcomes.'],
        ];
    }

    public function comparison(?array $forecast, ?string $capturedAt, array $samples): array
    {
        $unknown = ['status' => 'unavailable', 'direction_correct' => null, 'price_in_predicted_range' => null,
            'predicted_direction' => null, 'actual_direction' => null, 'predicted_price' => null, 'actual_price' => null];
        if (! $forecast || ! $capturedAt || ($forecast['available'] ?? true) === false
            || ($forecast['status'] ?? 'clear') !== 'clear') {
            return $unknown;
        }
        $direction = $this->normalize($forecast['bias'] ?? $forecast['direction'] ?? null);
        $base = (float) ($forecast['current_price'] ?? 0);
        $horizon = (int) ($forecast['horizon_seconds'] ?? 0);
        if ($direction === null || $base <= 0 || $horizon <= 0) {
            return $unknown;
        }
        $target = CarbonImmutable::parse($capturedAt)->addSeconds($horizon);
        $result = array_merge($unknown, ['predicted_direction' => $direction,
            'predicted_price' => $forecast['expected_mid_price'] ?? null, 'target_at' => $target->toIso8601String()]);
        if ($target->isFuture()) {
            return array_merge($result, ['status' => 'pending']);
        }
        $matching = array_values(array_filter($samples, fn ($row) => $row['time_ms'] >= $target->getTimestampMs()
            && $row['time_ms'] <= $target->getTimestampMs() + 1000 * (int) config('quick_trade_research.max_outcome_delay_seconds', 10)));
        usort($matching, fn ($a, $b) => $a['time_ms'] <=> $b['time_ms']);
        if ($matching === []) {
            return array_merge($result, ['status' => 'missing_horizon_sample']);
        }
        $actual = (float) $matching[0]['price'];
        $actualDirection = $this->direction($base, $actual);
        return array_merge($result, ['status' => 'evaluated', 'actual_price' => $actual,
            'actual_direction' => $actualDirection, 'direction_correct' => $direction === $actualDirection,
            'sample_delay_ms' => $matching[0]['time_ms'] - $target->getTimestampMs(),
            'price_in_predicted_range' => isset($forecast['expected_low'], $forecast['expected_high'])
                ? $actual >= $forecast['expected_low'] && $actual <= $forecast['expected_high'] : null,
            'price_error' => isset($forecast['expected_mid_price']) ? $actual - (float) $forecast['expected_mid_price'] : null,
            'price_error_pct' => isset($forecast['expected_mid_price']) && (float) $forecast['expected_mid_price'] > 0
                ? round(($actual - (float) $forecast['expected_mid_price']) / (float) $forecast['expected_mid_price'] * 100, 6) : null,
        ]);
    }

    /**
     * Part A3 -- the four aggregate audit metrics, computed over rows
     * produced by report().
     *
     * Conventions kept identical to the rest of this file:
     *  - unknown/unavailable is EXCLUDED from the denominator, never counted
     *    as a failure;
     *  - a NEUTRAL direction is `not_applicable` and is excluded, never
     *    counted as wrong;
     *  - an accuracy percentage is null (with `insufficient_data`) below the
     *    configured minimum sample size rather than being reported off a
     *    handful of trades.
     *
     * @param  iterable<array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    public function auditSummary(iterable $rows): array
    {
        $minSamples = max(1, (int) config('quick_trade.bucket_min_samples', 30));

        $directionEvaluated = 0;
        $directionCorrect = 0;
        $directionNotApplicable = 0;
        $decisionEvaluated = 0;
        $decisionCorrect = 0;
        $waitSaved = 0.0;
        $waitMissed = 0.0;
        $waitEvaluated = 0;
        $overrides = 0;
        $total = 0;

        foreach ($rows as $row) {
            $total++;
            $directionStatus = $row['direction_evaluation_status'] ?? null;
            if ($directionStatus === 'not_applicable') {
                $directionNotApplicable++;
            } elseif ($directionStatus === 'evaluated' && $row['direction_correct'] !== null) {
                $directionEvaluated++;
                $directionCorrect += $row['direction_correct'] === true ? 1 : 0;
            }

            if (($row['trade_decision_evaluation_status'] ?? null) === 'evaluated' && $row['trade_decision_correct'] !== null) {
                $decisionEvaluated++;
                $decisionCorrect += $row['trade_decision_correct'] === true ? 1 : 0;
            }

            $shadow = is_array($row['wait_shadow'] ?? null) ? $row['wait_shadow'] : null;
            if ($shadow !== null && ($shadow['status'] ?? null) === 'evaluated' && ($shadow['trade_decision'] ?? null) === 'WAIT') {
                $waitEvaluated++;
                $waitSaved += (float) ($shadow['wait_saved_usdt'] ?? 0);
                $waitMissed += (float) ($shadow['wait_missed_usdt'] ?? 0);
            }

            if (($row['manual_override'] ?? null) === true) {
                $overrides++;
            }
        }

        return [
            'trade_count' => $total,
            'direction_evaluated' => $directionEvaluated,
            'direction_correct_count' => $directionCorrect,
            'direction_not_applicable' => $directionNotApplicable,
            'direction_accuracy_pct' => $directionEvaluated >= $minSamples
                ? round($directionCorrect / $directionEvaluated * 100, 2) : null,
            'trade_decision_evaluated' => $decisionEvaluated,
            'trade_decision_correct_count' => $decisionCorrect,
            'trade_decision_accuracy_pct' => $decisionEvaluated >= $minSamples
                ? round($decisionCorrect / $decisionEvaluated * 100, 2) : null,
            'wait_decisions_evaluated' => $waitEvaluated,
            'wait_decision_saved_usdt' => round($waitSaved, 8),
            'wait_decision_missed_usdt' => round($waitMissed, 8),
            'wait_decision_net_benefit_usdt' => round($waitSaved - $waitMissed, 8),
            'manual_override_count' => $overrides,
            'min_samples_for_accuracy' => $minSamples,
            'status' => $directionEvaluated >= $minSamples ? 'observed_sample' : 'insufficient_data',
            'note' => 'Direction accuracy is fee-free; trade-decision accuracy is fee-aware and covers BUY/SELL decisions only. WAIT correctness is measured by the shadow saved/missed columns, never as a pass/fail. Unknown data is excluded, never counted as false.',
        ];
    }

    private function direction(float $from, float $to): string
    {
        return abs($to - $from) < max(1e-8, abs($from) * 1e-10) ? 'neutral' : ($to > $from ? 'buy' : 'sell');
    }

    private function normalize(?string $direction): ?string
    {
        return match (strtolower($direction ?? '')) {
            'buy', 'long', 'up' => 'buy', 'sell', 'short', 'down' => 'sell', 'neutral', 'hold', 'flat' => 'neutral', default => null,
        };
    }
}
