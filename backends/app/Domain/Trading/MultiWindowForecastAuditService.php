<?php

namespace App\Domain\Trading;

use App\Domain\Market\MarketPriceCacheService;
use App\Models\MultiWindowForecastAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * VNext Dynamic Decision Engine, Phase A -- persistence + hindsight
 * evaluation for MultiWindowForecastComparisonService's per-window
 * forecasts. Mirrors QuickTradeWaitShadowService's established shape
 * (capture -> evaluateDue -> report) rather than inventing a new one.
 *
 * Observation only. Nothing here can place, block or alter a trade, and
 * every public entry point is fail-safe (log and continue, never throw) --
 * the same guarantee QuickTradeWaitShadowService already carries.
 */
class MultiWindowForecastAuditService
{
    public function enabled(): bool
    {
        return (bool) config('multi_window_forecast.capture_audit', true)
            && Schema::hasTable('multi_window_forecast_audits');
    }

    /**
     * Persists one window's already-computed forecast. Idempotent per
     * (correlation_id, window_label) -- a client re-polling the same
     * in-flight comparison (AsyncTradeForecast's own 202-then-poll pattern)
     * never produces a duplicate row.
     *
     * @param  array<string,mixed>  $requestData  the per-window payload sent to AsyncTradeForecast::request()
     * @param  array<string,mixed>  $forecastResult  QuickTradeForecastCalculator::forecast()['forecast']
     */
    public function capture(string $correlationId, string $windowLabel, array $requestData, array $forecastResult): void
    {
        if (! $this->enabled() || ($forecastResult['available'] ?? false) !== true) {
            return;
        }

        try {
            $direction = is_array($forecastResult['direction'] ?? null) ? $forecastResult['direction'] : [];
            $tradeDecision = is_array($forecastResult['trade_decision'] ?? null) ? $forecastResult['trade_decision'] : [];
            $generatedAt = $forecastResult['generated_at'] ?? null;
            $expirySeconds = (int) $requestData['expiry_seconds'];
            $exchange = $this->liveFeedExchange($requestData['exchange'] ?? null);
            $mode = strtolower((string) ($requestData['mode'] ?? 'paper'));
            // Hindsight scoring only against the canonical live futures feed
            // -- see config/multi_window_forecast.php's docblock for why.
            $scoreable = $mode === 'live' && $exchange === 'binance_futures' && $generatedAt;
            $referenceAt = $generatedAt ? \Carbon\CarbonImmutable::parse((string) $generatedAt) : null;

            DB::table('multi_window_forecast_audits')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'correlation_id' => $correlationId,
                'symbol' => strtoupper((string) $requestData['symbol']),
                'exchange' => $exchange ?? strtolower((string) ($requestData['exchange'] ?? 'binance')),
                'window_label' => $windowLabel,
                'timeframe' => (string) $requestData['timeframe'],
                'expiry_seconds' => $expirySeconds,
                'direction' => $direction['value'] ?? ($forecastResult['bias'] ?? null),
                'trade_decision' => $tradeDecision['value'] ?? ($forecastResult['decision_trend'] ?? null),
                'expiry_score' => $direction['expiry_score'] ?? null,
                'expected_move_pct' => $tradeDecision['expected_move_pct'] ?? ($direction['expected_move_pct'] ?? null),
                'required_move_pct' => $tradeDecision['required_move_pct'] ?? null,
                'cost_model' => json_encode($forecastResult['cost_model'] ?? []),
                'generated_at' => $generatedAt ? \Carbon\CarbonImmutable::parse((string) $generatedAt) : null,
                'reference_price' => $forecastResult['reference_price'] ?? $forecastResult['current_price'] ?? null,
                'workspace_id' => $requestData['workspace_id'] ?? null,
                'mode' => $mode,
                'target_ms' => $scoreable ? $referenceAt->addSeconds($expirySeconds)->getTimestampMs() : null,
                'status' => $scoreable ? 'pending' : 'not_applicable',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('[MultiWindowForecastAudit] capture failed (non-fatal, comparison response unaffected)', [
                'correlation_id' => $correlationId, 'window_label' => $windowLabel, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Evaluate every `pending` row whose target has passed, using the same
     * cached-live-quote acceptance rule QuickTradeWaitShadowService uses.
     * Intended to ride the SAME existing `quick-trades:sample-research`
     * scheduler tick (see that command) -- no new scheduler entry.
     *
     * @return array{evaluated:int, missing:int, unavailable:int}
     */
    public function evaluateDue(MarketPriceCacheService $prices, int $limit = 500): array
    {
        $counts = ['evaluated' => 0, 'missing' => 0, 'unavailable' => 0];
        if (! $this->enabled()) {
            return $counts;
        }

        try {
            $nowMs = now()->getTimestampMs();
            $windowMs = 1000 * (int) config('quick_trade_research.max_outcome_delay_seconds', 10);
            $due = MultiWindowForecastAudit::query()
                ->where('status', 'pending')
                ->where('target_ms', '<=', $nowMs)
                ->orderBy('target_ms')
                ->limit(max(1, min(5000, $limit)))
                ->get();
            $quotes = [];

            foreach ($due as $row) {
                try {
                    if (! array_key_exists($row->symbol, $quotes)) {
                        $quotes[$row->symbol] = $prices->getLiveFeedSnapshot('binance_futures', $row->symbol,
                            (int) config('quick_trade_research.max_quote_age_seconds', 5));
                    }
                    $samples = $this->sampleFrom($quotes[$row->symbol]);
                    $outcome = app(ForecastOutcomeEvaluator::class)->evaluate([
                        'available' => true,
                        'reference_price' => $row->reference_price,
                        'predicted_expiry_price' => $row->reference_price,
                        'horizon_seconds' => $row->expiry_seconds,
                        'generated_at' => $row->generated_at?->toIso8601String(),
                    ], $samples);

                    if (($outcome['status'] ?? null) !== 'evaluated') {
                        if ($nowMs > $row->target_ms + $windowMs) {
                            $status = $outcome['status'] === 'missing_horizon_sample' ? 'missing_horizon_sample' : 'unavailable';
                            $row->update(['status' => $status, 'evaluated_at' => now()]);
                            $counts[$status === 'missing_horizon_sample' ? 'missing' : 'unavailable']++;
                        }
                        continue;
                    }

                    $hypothetical = app(QuickTradeResearchService::class)->hypotheticalWaitOutcome(
                        $row->direction,
                        $row->trade_decision,
                        (float) $row->reference_price,
                        (float) $outcome['actual_price'],
                        max(1.0, (float) config('multi_window_forecast.reference_notional_usdt', 100)),
                        // `cost_model` is cast to `array` on the model
                        // (MultiWindowForecastAudit::$casts) -- already
                        // decoded, never a JSON string here.
                        data_get($row->cost_model, 'fee_model'),
                    );
                    $row->update([
                        'status' => 'evaluated',
                        'actual_price' => $outcome['actual_price'],
                        // NOT $outcome['direction_correct']: this evaluator
                        // call was seeded with predicted_expiry_price ==
                        // reference_price (same trick QuickTradeWaitShadowService
                        // uses) purely to get a real historical price sample
                        // match out of it -- its own predicted_direction is
                        // therefore always 'neutral' and not meaningful here.
                        // The row's OWN captured `direction` (BUY/SELL from
                        // the actual forecast) is what gets scored against
                        // the realised move instead.
                        'direction_correct' => $this->directionCorrect($row->direction, (float) $row->reference_price, (float) $outcome['actual_price']),
                        'hypothetical_net_usdt' => $hypothetical['hypothetical_net_usdt'],
                        'evaluated_at' => now(),
                    ]);
                    $counts['evaluated']++;
                } catch (Throwable $e) {
                    Log::warning('[MultiWindowForecastAudit] evaluation failed for one row', ['id' => $row->id, 'error' => $e->getMessage()]);
                }
            }
        } catch (Throwable $e) {
            Log::warning('[MultiWindowForecastAudit] evaluation pass failed', ['error' => $e->getMessage()]);
        }

        return $counts;
    }

    /**
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

    /**
     * BUY/SELL vs the realised move, entry -> expiry-sample. A NEUTRAL/
     * WAIT-only captured direction has nothing to score and returns null
     * (never a fabricated correct/incorrect). Mirrors
     * ForecastOutcomeEvaluator::direction()'s own epsilon-based neutral
     * threshold so a genuinely flat move is neither a hit nor a miss.
     */
    private function directionCorrect(?string $capturedDirection, float $referencePrice, float $actualPrice): ?bool
    {
        if (! in_array($capturedDirection, ['BUY', 'SELL'], true) || $referencePrice <= 0) {
            return null;
        }
        $epsilon = max(1e-8, abs($referencePrice) * 1e-10);
        if (abs($actualPrice - $referencePrice) < $epsilon) {
            return false;
        }
        $actualDirection = $actualPrice > $referencePrice ? 'BUY' : 'SELL';

        return $capturedDirection === $actualDirection;
    }

    /**
     * Same canonical-namespace mapping as
     * QuickTradeForecastCalculator::liveFeedExchange() (kept private there);
     * duplicated here narrowly rather than extracted into a shared helper
     * for a 3-line mapping, per the repo's no-drive-by-refactor rule.
     */
    private function liveFeedExchange(mixed $exchange): ?string
    {
        return match (strtolower(trim((string) $exchange))) {
            'binance' => 'binance',
            'binance_futures' => 'binance_futures',
            '' => 'binance_futures',
            default => null,
        };
    }
}
