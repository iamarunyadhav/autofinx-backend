<?php

namespace App\Domain\Trading;

use App\Domain\Market\MarketPriceCacheService;
use App\Models\QuickTradeWaitShadow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Gap-checklist Phase 1 item 4 -- shadow records for fee-aware WAIT
 * decisions nobody traded.
 *
 * Why: EvaluateQuickTradeForecastOutcomeJob only evaluates the WAIT shadow
 * (A4) for trades that were actually placed, and A5 blocks live orders
 * against WAIT -- so live saved/missed totals stayed near zero. This records
 * every WAIT the Quick Trade forecast shows, once per decision candle close,
 * and evaluates it at expiry with the SAME A4 rule
 * (QuickTradeResearchService::hypotheticalWaitOutcome()).
 *
 * Observation only. Nothing here can place, block or alter a trade, and
 * every public entry point is fail-safe (log and continue, never throw).
 *
 *  capture   -- hooked into QuickTradeForecastCalculator::forecast(), only
 *               when the B2 close-anchored decision cache just COMPUTED a
 *               fresh decision (one per candle close), the decision is WAIT,
 *               mode is live, and the market is the canonical Binance
 *               futures feed. Dedupe: unique (symbol, timeframe,
 *               expiry_seconds, decision_candle_close_ms) + insertOrIgnore.
 *  evaluate  -- driven by the EXISTING `quick-trades:sample-research`
 *               scheduler entry (every 5s); no new scheduler. The expiry
 *               price is the same cached futures quote the traded-sample
 *               path records, accepted by the same ForecastOutcomeEvaluator
 *               window (target .. target + max_outcome_delay_seconds).
 *  prune     -- same command, at most once an hour, deletes rows older than
 *               `shadow_untraded_waits_retention_days`.
 *
 * Feature flag: quick_trade.shadow_untraded_waits (default OFF).
 */
class QuickTradeWaitShadowService
{
    public function enabled(): bool
    {
        return (bool) config('quick_trade.shadow_untraded_waits', false)
            && Schema::hasTable('quick_trade_wait_shadows');
    }

    /**
     * @param  array<string,mixed>  $data            validated forecast request
     * @param  array<string,mixed>  $forecastResult  final forecast payload
     */
    public function captureFromForecast(array $data, array $forecastResult): void
    {
        try {
            if (! $this->enabled()
                || strtolower((string) ($data['mode'] ?? 'paper')) !== 'live'
                || empty($data['symbol'])
                || ($forecastResult['available'] ?? false) !== true
                || ($forecastResult['decision_cache']['status'] ?? null) !== 'computed') {
                return;
            }
            $exchange = strtolower(trim((string) ($data['exchange'] ?? '')));
            if (! in_array($exchange, ['', 'binance_futures'], true)) {
                return;
            }
            $decision = strtoupper((string) ($forecastResult['trade_decision']['value'] ?? $forecastResult['decision_trend'] ?? ''));
            if ($decision !== 'WAIT') {
                return;
            }
            $closeMs = $forecastResult['decision_cache']['anchor_candle_close_ms'] ?? null;
            $generatedAt = $forecastResult['generated_at'] ?? null;
            $expirySeconds = (int) $data['expiry_seconds'];
            if (! is_numeric($closeMs) || (int) $closeMs <= 0 || ! $generatedAt || $expirySeconds <= 0) {
                return;
            }

            $referenceAt = CarbonImmutable::parse((string) $generatedAt);
            $direction = is_array($forecastResult['direction'] ?? null) ? $forecastResult['direction'] : [];
            $tradeDecision = is_array($forecastResult['trade_decision'] ?? null) ? $forecastResult['trade_decision'] : [];
            $referencePrice = (float) ($forecastResult['reference_price'] ?? $forecastResult['current_price'] ?? 0);
            $costModel = app(QuickTradeCostModel::class)->costs($tradeDecision['fee_model'] ?? null);
            $now = now();

            DB::table('quick_trade_wait_shadows')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'exchange' => 'binance_futures',
                'symbol' => strtoupper((string) $data['symbol']),
                'timeframe' => (string) $data['timeframe'],
                'expiry_seconds' => $expirySeconds,
                'decision_candle_close_ms' => (int) $closeMs,
                'decision_candle_close_at' => CarbonImmutable::createFromTimestampMs((int) $closeMs),
                'reference_at' => $referenceAt,
                'target_ms' => $referenceAt->addSeconds($expirySeconds)->getTimestampMs(),
                'mode' => 'live',
                'direction' => isset($direction['value']) ? strtoupper((string) $direction['value']) : ($forecastResult['bias'] ?? null),
                'trade_decision' => 'WAIT',
                'expiry_score' => $direction['expiry_score'] ?? null,
                'expected_move_pct' => $tradeDecision['expected_move_pct'] ?? ($direction['expected_move_pct'] ?? null),
                'required_move_pct' => $tradeDecision['required_move_pct'] ?? null,
                'max_achievable_move_pct' => $tradeDecision['max_achievable_move_pct'] ?? null,
                'gate_reason' => $tradeDecision['gate_reason'] ?? ($forecastResult['gate_reason'] ?? null),
                'reference_price' => $referencePrice > 0 ? $referencePrice : null,
                'reference_price_source' => $forecastResult['reference_price_source'] ?? null,
                'horizon_seconds' => $forecastResult['horizon_seconds'] ?? $expirySeconds,
                'horizon_candles' => $forecastResult['horizon_candles'] ?? null,
                'cost_model' => json_encode($costModel),
                'reference_notional_usdt' => $this->referenceNotional(),
                'status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (Throwable $e) {
            Log::warning('[QuickTradeWaitShadow] capture failed (non-fatal, forecast response unaffected)', [
                'symbol' => $data['symbol'] ?? null, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Evaluate every pending shadow whose target has passed. Called from the
     * existing research sampler; returns counts for its console output.
     *
     * @return array{evaluated:int, missing:int, not_applicable:int, unavailable:int}
     */
    public function evaluateDue(MarketPriceCacheService $prices, int $limit = 500): array
    {
        $counts = ['evaluated' => 0, 'missing' => 0, 'not_applicable' => 0, 'unavailable' => 0];
        if (! $this->enabled()) {
            return $counts;
        }

        try {
            $nowMs = now()->getTimestampMs();
            $windowMs = 1000 * (int) config('quick_trade_research.max_outcome_delay_seconds', 10);
            $due = QuickTradeWaitShadow::query()
                ->where('status', 'pending')
                ->where('target_ms', '<=', $nowMs)
                ->orderBy('target_ms')
                ->limit(max(1, min(5000, $limit)))
                ->get();
            $quotes = [];

            foreach ($due as $shadow) {
                try {
                    if (! array_key_exists($shadow->symbol, $quotes)) {
                        $quotes[$shadow->symbol] = $prices->getLiveFeedSnapshot('binance_futures', $shadow->symbol,
                            (int) config('quick_trade_research.max_quote_age_seconds', 5));
                    }
                    $samples = $this->sampleFrom($quotes[$shadow->symbol]);
                    $outcome = app(ForecastOutcomeEvaluator::class)->evaluate([
                        'available' => true,
                        'reference_price' => $shadow->reference_price,
                        // The evaluator needs a predicted price only to label
                        // its own predicted_direction; the saved/missed
                        // figures below never read it.
                        'predicted_expiry_price' => $shadow->reference_price,
                        'horizon_seconds' => $shadow->expiry_seconds,
                        'generated_at' => $shadow->reference_at?->toIso8601String(),
                    ], $samples);

                    if (($outcome['status'] ?? null) !== 'evaluated') {
                        // Only give up once the whole acceptance window has
                        // passed -- the next 5s sampler tick may still land
                        // inside it.
                        if ($nowMs > $shadow->target_ms + $windowMs) {
                            $shadow->update(['status' => $outcome['status'] === 'missing_horizon_sample'
                                ? 'missing_horizon_sample' : 'unavailable', 'evaluated_at' => now()]);
                            $counts[$outcome['status'] === 'missing_horizon_sample' ? 'missing' : 'unavailable']++;
                        }

                        continue;
                    }

                    $result = app(QuickTradeResearchService::class)->hypotheticalWaitOutcome(
                        $shadow->direction,
                        'WAIT',
                        (float) $shadow->reference_price,
                        (float) $outcome['actual_price'],
                        (float) $shadow->reference_notional_usdt,
                        $shadow->cost_model['fee_model'] ?? null,
                    );
                    $shadow->update([
                        'status' => $result['status'],
                        'expiry_price' => $result['expiry_price'],
                        'expiry_sample_delay_ms' => $outcome['sample_delay_ms'] ?? null,
                        'gross_move_pct' => $result['gross_move_pct'],
                        'hypothetical_gross_usdt' => $result['hypothetical_gross_usdt'],
                        'hypothetical_cost_usdt' => $result['hypothetical_cost_usdt'],
                        'hypothetical_net_usdt' => $result['hypothetical_net_usdt'],
                        'wait_saved_usdt' => $result['wait_saved_usdt'],
                        'wait_missed_usdt' => $result['wait_missed_usdt'],
                        'evaluated_at' => now(),
                    ]);
                    $counts[match ($result['status']) {
                        'evaluated' => 'evaluated',
                        'not_applicable' => 'not_applicable',
                        default => 'unavailable',
                    }]++;
                } catch (Throwable $e) {
                    Log::warning('[QuickTradeWaitShadow] evaluation failed for one row', ['id' => $shadow->id, 'error' => $e->getMessage()]);
                }
            }
        } catch (Throwable $e) {
            Log::warning('[QuickTradeWaitShadow] evaluation pass failed', ['error' => $e->getMessage()]);
        }

        return $counts;
    }

    /** Bounded retention; throttled to one delete pass per hour. */
    public function pruneExpired(bool $force = false): int
    {
        if (! $this->enabled()) {
            return 0;
        }
        try {
            if (! $force && ! Cache::add('quick_trade:wait_shadow:prune_lock', 1, 3600)) {
                return 0;
            }
            $days = max(1, (int) config('quick_trade.shadow_untraded_waits_retention_days', 30));

            return QuickTradeWaitShadow::query()->where('created_at', '<', now()->subDays($days))->limit(10000)->delete();
        } catch (Throwable $e) {
            Log::warning('[QuickTradeWaitShadow] prune failed', ['error' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * @param  array{symbol?:string|null, timeframe?:string|null, expiry_seconds?:int|string|null}  $scope
     * @return array<string,mixed>
     */
    public function summary(array $scope = []): array
    {
        $base = [
            'enabled' => (bool) config('quick_trade.shadow_untraded_waits', false),
            'scope' => 'market_level_untraded_waits',
            'reference_notional_usdt' => $this->referenceNotional(),
            'wait_decisions_recorded' => 0,
            'wait_decisions_evaluated' => 0,
            'wait_decisions_pending' => 0,
            'wait_decisions_missing_expiry_price' => 0,
            'wait_decisions_not_applicable' => 0,
            'wait_decision_saved_usdt' => 0.0,
            'wait_decision_missed_usdt' => 0.0,
            'wait_decision_net_benefit_usdt' => 0.0,
            'note' => 'Untraded WAIT decisions (one per symbol/timeframe/expiry/decision candle close), evaluated at expiry with the same fee-aware hypothetical-net rule as traded WAITs, quoted on the fixed reference notional above -- NOT this workspace\'s trades and never merged into the traded-WAIT totals.',
        ];
        if (! Schema::hasTable('quick_trade_wait_shadows')) {
            return $base + ['status' => 'table_missing'];
        }

        try {
            $query = QuickTradeWaitShadow::query()
                ->when($scope['symbol'] ?? null, fn ($q, $v) => $q->where('symbol', strtoupper((string) $v)))
                ->when($scope['timeframe'] ?? null, fn ($q, $v) => $q->where('timeframe', $v))
                ->when($scope['expiry_seconds'] ?? null, fn ($q, $v) => $q->where('expiry_seconds', (int) $v));
            $byStatus = (clone $query)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->all();
            $evaluated = (clone $query)->where('status', 'evaluated')
                ->selectRaw('coalesce(sum(wait_saved_usdt), 0) as saved, coalesce(sum(wait_missed_usdt), 0) as missed')
                ->first();
            $saved = (float) ($evaluated->saved ?? 0);
            $missed = (float) ($evaluated->missed ?? 0);

            return array_merge($base, [
                'status' => 'ok',
                'wait_decisions_recorded' => (int) array_sum($byStatus),
                'wait_decisions_evaluated' => (int) ($byStatus['evaluated'] ?? 0),
                'wait_decisions_pending' => (int) ($byStatus['pending'] ?? 0),
                'wait_decisions_missing_expiry_price' => (int) (($byStatus['missing_horizon_sample'] ?? 0) + ($byStatus['unavailable'] ?? 0)),
                'wait_decisions_not_applicable' => (int) ($byStatus['not_applicable'] ?? 0),
                'wait_decision_saved_usdt' => round($saved, 8),
                'wait_decision_missed_usdt' => round($missed, 8),
                'wait_decision_net_benefit_usdt' => round($saved - $missed, 8),
            ]);
        } catch (Throwable $e) {
            Log::warning('[QuickTradeWaitShadow] summary failed', ['error' => $e->getMessage()]);

            return $base + ['status' => 'unavailable'];
        }
    }

    private function referenceNotional(): float
    {
        return max(1.0, (float) config('quick_trade.shadow_untraded_waits_reference_notional_usdt', 100));
    }

    /**
     * Same acceptance rules SampleQuickTradeResearchCommand applies to a
     * cached quote before persisting it as a traded-trade sample.
     *
     * @return array<int, array{time_ms:int, price:float}>
     */
    private function sampleFrom(?array $quote): array
    {
        if (! is_array($quote)) {
            return [];
        }
        $time = (int) ($quote['event_timestamp_ms'] ?? 0);
        $price = (float) ($quote['price'] ?? 0);
        $nowMs = now()->getTimestampMs();
        if (! ($quote['fresh'] ?? false) || ($quote['market_type'] ?? '') !== 'futures'
            || ! is_finite($price) || $price <= 0 || $time <= 0 || $time > $nowMs || $nowMs - $time > 5000) {
            return [];
        }

        return [['time_ms' => $time, 'price' => $price]];
    }
}
