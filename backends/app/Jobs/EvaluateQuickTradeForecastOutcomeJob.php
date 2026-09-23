<?php

namespace App\Jobs;

use App\Domain\Trading\ForecastOutcomeEvaluator;
use App\Models\QuickTrade;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Low-priority research work; never runs on the execution request path. */
class EvaluateQuickTradeForecastOutcomeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Deliberately NOT redeclaring a $queue property here (pre-existing bug
     * found and fixed independently of this task's own scope): PHP rejects
     * ANY class-level redeclaration of a trait property with a different
     * default value -- not just a type mismatch -- as an incompatible
     * composition (fatal "definition differs" error, verified with a
     * minimal repro: a class overriding Illuminate\Bus\Queueable's own
     * untyped `public $queue;` with `public $queue = 'analytics';` still
     * fails the same way). This class never actually needed the property:
     * every real dispatch site already calls `->onQueue('analytics')`
     * explicitly (see QuickTradeService::settle()), which is what actually
     * sets the queue at runtime; the property was fully redundant.
     */
    public int $tries = 2;
    public int $timeout = 20;

    public function __construct(public readonly string $quickTradeId) {}

    public function handle(ForecastOutcomeEvaluator $evaluator): void
    {
        $trade = QuickTrade::find($this->quickTradeId);
        if (! $trade || $trade->status === 'open') return;

        try {
            $payload = (array) $trade->signal_payload;
            $snapshot = (array) ($payload['research'] ?? []);
            $samples = \App\Models\QuickTradeResearchSample::where('quick_trade_id', $trade->id)
                ->orderBy('quote_time_ms')->get()
                ->map(static fn ($row) => ['time_ms' => (int) $row->quote_time_ms, 'price' => (float) $row->price])
                ->all();
            $primary = $evaluator->evaluate((array) ($snapshot['primary'] ?? []), $samples);
            $payload['research']['outcome_evaluation'] = [
                'evaluated_at' => now()->toIso8601String(),
                'primary' => $primary,
                'trade_status' => $trade->status,
                'net_pnl' => $trade->profit_loss !== null ? (float) $trade->profit_loss : null,
            ];

            // Part A4 -- WAIT shadow. "If a trade HAD been placed in the
            // Direction at this price, what would net P&L be at expiry after
            // fees?" Recorded for every trade (not just WAITs) so the WAIT
            // decision can be judged both ways: money saved by not trading,
            // and money missed by not trading. Reuses this same existing
            // post-settlement evaluator pass -- no new scheduler, no new
            // sampling path.
            $payload['research']['wait_shadow'] = $this->waitShadow($snapshot, $primary, $trade);

            $trade->update(['signal_payload' => $payload]);
        } catch (Throwable $e) {
            Log::warning('[QuickTradeResearch] async outcome evaluation failed', [
                'quick_trade_id' => $this->quickTradeId, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Hypothetical net P&L of following the cost-blind DIRECTION from the
     * snapshot's reference price to the realised price at expiry, charged
     * the same round-trip cost the live decision gate itself uses.
     *
     * Honest-by-construction:
     *  - `status` is 'unavailable' whenever the direction, the reference
     *    price or the realised expiry price is missing. Nothing is guessed.
     *  - a NEUTRAL direction is 'not_applicable' -- there is no trade to
     *    simulate, and it is never scored as a loss.
     *  - the stake used is the trade's own real stake, so the USDT figures
     *    are comparable with the trade's booked net P&L.
     *
     * @param  array<string,mixed>  $snapshot
     * @param  array<string,mixed>  $primary   ForecastOutcomeEvaluator result
     * @return array<string,mixed>
     */
    private function waitShadow(array $snapshot, array $primary, QuickTrade $trade): array
    {
        $research = app(\App\Domain\Trading\QuickTradeResearchService::class);
        $costModel = app(\App\Domain\Trading\QuickTradeCostModel::class);

        $direction = is_array($snapshot['direction_prediction'] ?? null)
            ? $snapshot['direction_prediction']
            : $research->directionPrediction($snapshot['primary'] ?? null);
        $decision = is_array($snapshot['trade_decision_prediction'] ?? null)
            ? $snapshot['trade_decision_prediction']
            : $research->tradeDecisionPrediction($snapshot['primary'] ?? null);

        $directionValue = $direction['value'] ?? null;
        $decisionValue = $decision['value'] ?? null;
        $base = (float) ($snapshot['primary']['reference_price'] ?? $snapshot['primary']['current_price'] ?? 0);
        $actual = $primary['actual_price'] ?? null;
        $stake = (float) $trade->stake_amount;

        $result = [
            'evaluated_at' => now()->toIso8601String(),
            'direction' => $directionValue,
            'trade_decision' => $decisionValue,
            'reference_price' => $base > 0 ? $base : null,
            'expiry_price' => $actual !== null ? (float) $actual : null,
            'stake' => $stake,
            'status' => 'unavailable',
            'gross_move_pct' => null,
            'hypothetical_gross_usdt' => null,
            'hypothetical_cost_usdt' => null,
            'hypothetical_net_usdt' => null,
            'would_have_profited' => null,
            'wait_saved_usdt' => null,
            'wait_missed_usdt' => null,
        ];

        if ($directionValue === 'NEUTRAL') {
            $result['status'] = 'not_applicable';

            return $result;
        }
        if (! in_array($directionValue, ['BUY', 'SELL'], true) || $base <= 0 || $actual === null || $stake <= 0) {
            return $result;
        }

        $costs = $costModel->costs($decision['fee_model'] ?? null);
        $signedMovePct = (((float) $actual - $base) / $base) * 100 * ($directionValue === 'SELL' ? -1 : 1);
        $grossUsdt = $stake * ($signedMovePct / 100);
        $costUsdt = $stake * ($costs['cost_pct'] / 100);
        $netUsdt = $grossUsdt - $costUsdt;

        $result['status'] = 'evaluated';
        $result['gross_move_pct'] = round($signedMovePct, 6);
        $result['cost_pct'] = $costs['cost_pct'];
        $result['fee_model'] = $costs['fee_model'];
        $result['hypothetical_gross_usdt'] = round($grossUsdt, 8);
        $result['hypothetical_cost_usdt'] = round($costUsdt, 8);
        $result['hypothetical_net_usdt'] = round($netUsdt, 8);
        $result['would_have_profited'] = $netUsdt > 0;

        // Only a WAIT decision can save or miss money by not trading.
        if ($decisionValue === 'WAIT') {
            $result['wait_saved_usdt'] = $netUsdt < 0 ? round(abs($netUsdt), 8) : 0.0;
            $result['wait_missed_usdt'] = $netUsdt > 0 ? round($netUsdt, 8) : 0.0;
        }

        return $result;
    }
}
