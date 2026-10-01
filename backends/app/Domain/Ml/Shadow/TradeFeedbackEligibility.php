<?php

namespace App\Domain\Ml\Shadow;

use App\Models\QuickTrade;
use Illuminate\Support\Carbon;

/**
 * Step 3.5 execution-feedback tiering for stored Quick Trades.
 *
 * Decided from the trade's own recorded FIELDS only — never from a stored
 * eligibility flag (the old data_quality.training_eligible marked some
 * forming-bar trades eligible). Missing/unknown values are never eligible.
 *
 *   TIER_EXCLUDED  — estimated/partial fees, non-live or unknown environment,
 *                    or missing decision data. Not used anywhere.
 *   TIER_COST_ONLY — real live fills whose decision features are contaminated
 *                    (forming bar / unknown) or that pre-date the cutoff. Fee &
 *                    slippage vs the cost model ONLY.
 *   TIER_FULL      — passes every rule; usable for execution feedback.
 *
 * No tier makes a trade a training row for the price/return model, and
 * manual trades are never scored as model decisions.
 */
final class TradeFeedbackEligibility
{
    public const RULES_VERSION = 'tfe-v1';

    public const TIER_EXCLUDED = 'TIER_EXCLUDED';

    public const TIER_COST_ONLY = 'TIER_COST_ONLY';

    public const TIER_FULL = 'TIER_FULL';

    /** @return array{tier:string, reasons:list<string>, decision_source:string} */
    public function classify(QuickTrade $trade): array
    {
        $research = (array) data_get((array) $trade->signal_payload, 'research', []);
        $decisionSource = (string) ($trade->source ?: 'unknown');

        $excluded = [];
        if (($trade->fee_source ?? null) !== 'real') {
            $excluded[] = 'fees_not_real:'.($trade->fee_source ?? 'null');
        }
        if ($trade->mode !== 'live' || ($research['execution_environment'] ?? null) !== 'live') {
            $excluded[] = 'execution_environment_not_live:'.($research['execution_environment'] ?? 'unknown');
        }
        if (empty($research['decision_candle_close_at'])) {
            $excluded[] = 'missing_decision_candle_close_at';
        }
        if (data_get($research, 'trade_decision_prediction.available') !== true) {
            $excluded[] = 'trade_decision_prediction_unavailable';
        }
        if ($trade->entry_at === null) {
            $excluded[] = 'missing_entry_at';
        }
        if ($excluded !== []) {
            return ['tier' => self::TIER_EXCLUDED, 'reasons' => $excluded, 'decision_source' => $decisionSource];
        }

        $costOnly = [];
        // Strict identity: only an explicit false is clean. null = unknown = contaminated.
        $forming = $research['input_candles_include_forming_bar'] ?? null;
        if ($forming !== false) {
            $costOnly[] = $forming === true ? 'input_candles_include_forming_bar' : 'forming_bar_flag_missing';
        }
        $cutoff = $this->cutoff();
        if ($cutoff !== null && $trade->entry_at->lt($cutoff)) {
            $costOnly[] = 'entry_before_trade_feedback_min_entry_at';
        }
        if ($costOnly !== []) {
            return ['tier' => self::TIER_COST_ONLY, 'reasons' => $costOnly, 'decision_source' => $decisionSource];
        }

        return ['tier' => self::TIER_FULL, 'reasons' => [], 'decision_source' => $decisionSource];
    }

    private function cutoff(): ?Carbon
    {
        $value = config('ml.trade_feedback_min_entry_at');

        return $value ? Carbon::parse((string) $value) : null;
    }
}
