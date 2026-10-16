<?php

namespace App\Domain\MarketOpportunityBrain\Decision;

use App\Domain\MarketOpportunityBrain\Contracts\DecisionPolicy;
use App\Domain\MarketOpportunityBrain\Data\DecisionRecord;
use App\Domain\MarketOpportunityBrain\Data\DiscoveryRecord;
use App\Domain\MarketOpportunityBrain\Data\ForecastRecord;
use App\Domain\MarketOpportunityBrain\Enums\ForecastStatus;
use App\Domain\MarketOpportunityBrain\Enums\ResearchDecision;

/**
 * Pure, deterministic, versioned research decision policy (MASTER_SPEC.md Layer G).
 *
 * Honest scope statement: with the adapters that exist in this codebase today (Phase 1's
 * `naive_last` and Phase 3's Chronos-2/Granite-TTM adapters), `forecast.probabilityCalibrated` is
 * ALWAYS false -- no `CalibrationValidator` has been built yet (that is explicitly future work:
 * MASTER_SPEC.md Layer F file-layout lists `Forecast/CalibrationValidator.php` as a separate,
 * not-yet-built component). Consequently this policy can currently only ever return `WAIT` or
 * `REJECT` -- it has a fully-implemented, tested branch for the calibrated case, but that branch
 * is intentionally unreachable in production right now. This is NOT a bug: it is the direct,
 * honest consequence of "Models propose probabilities; policy owns decisions" (Layer G) combined
 * with "no uncalibrated probability shown as success guarantee" (Section M test #8). The
 * calibrated branch additionally always returns `WAIT: COST_MODEL_NOT_IMPLEMENTED` rather than a
 * directional verdict, because neither `DiscoveryRecord` nor `ForecastRecord` carries a raw price
 * yet (needed for a real fee/slippage/funding net-expected-value gate, Layer G) -- inventing one
 * would fabricate a number this module has no real basis for.
 */
final class ResearchDecisionPolicy implements DecisionPolicy
{
    public const POLICY_ID = 'mob-research-decision';

    public const POLICY_VERSION = 'v1.0.0';

    public function decide(
        DiscoveryRecord $candidate,
        ForecastRecord $forecast,
        \DateTimeImmutable $decisionAsOfUtc,
    ): DecisionRecord {
        $decisionId = sprintf('decision-%s-%d', $candidate->symbol, $decisionAsOfUtc->getTimestamp());
        $inputHash = hash('sha256', json_encode([$candidate->toArray(), $forecast->toArray()], JSON_THROW_ON_ERROR));
        $decisionAsOfIso = $decisionAsOfUtc->format('Y-m-d\TH:i:s\Z');

        // Causal safety gate FIRST, before any other reasoning (Section M test #10: never use
        // data whose known_at > decision_ts). This is checked even before eligibility so a
        // future-leaking input can never be masked by a different rejection reason.
        $forecastKnownBy = new \DateTimeImmutable($forecast->knownByUtc === '' ? '@0' : $forecast->knownByUtc);
        if ($forecastKnownBy > $decisionAsOfUtc) {
            return $this->build(
                $decisionId, $candidate, $forecast, $decisionAsOfIso, $inputHash,
                ResearchDecision::REJECT, ['KNOWN_AT_AFTER_DECISION'], false,
            );
        }

        if ($candidate->symbol !== $forecast->symbol) {
            return $this->build(
                $decisionId, $candidate, $forecast, $decisionAsOfIso, $inputHash,
                ResearchDecision::REJECT, ['CANDIDATE_FORECAST_SYMBOL_MISMATCH'], false,
            );
        }

        if (! $candidate->eligibleForAnalysis) {
            $reasons = array_values(array_unique(array_merge(['CANDIDATE_NOT_ELIGIBLE'], $candidate->reasonCodes)));

            return $this->build($decisionId, $candidate, $forecast, $decisionAsOfIso, $inputHash, ResearchDecision::REJECT, $reasons, false);
        }

        if ($forecast->status !== ForecastStatus::OK) {
            $reasons = ['FORECAST_'.$forecast->status->value];
            if ($forecast->reason !== null) {
                $reasons[] = $forecast->reason;
            }

            return $this->build($decisionId, $candidate, $forecast, $decisionAsOfIso, $inputHash, ResearchDecision::WAIT, $reasons, false);
        }

        if (! $forecast->probabilityCalibrated) {
            return $this->build($decisionId, $candidate, $forecast, $decisionAsOfIso, $inputHash, ResearchDecision::WAIT, ['NO_CALIBRATION_EVIDENCE'], false);
        }

        // Calibrated branch: fully implemented logic, currently unreachable (see class docblock).
        // No real cost/price model is wired yet, so this never emits a directional verdict either.
        return $this->build($decisionId, $candidate, $forecast, $decisionAsOfIso, $inputHash, ResearchDecision::WAIT, ['COST_MODEL_NOT_IMPLEMENTED'], true);
    }

    /**
     * @param  list<string>  $reasonCodes
     */
    private function build(
        string $decisionId,
        DiscoveryRecord $candidate,
        ForecastRecord $forecast,
        string $decisionAsOfIso,
        string $inputHash,
        ResearchDecision $decision,
        array $reasonCodes,
        bool $forecastCalibrated,
    ): DecisionRecord {
        return new DecisionRecord(
            decisionId: $decisionId,
            workspaceId: $candidate->workspaceId,
            exchange: $candidate->exchange,
            market: $candidate->market,
            symbol: $candidate->symbol,
            baseTimeframe: $forecast->timeframe,
            horizonBars: $forecast->horizonBars,
            decisionAsOfUtc: $decisionAsOfIso,
            inputsKnownByUtc: $forecast->knownByUtc,
            candidateId: $candidate->snapshotId,
            forecastId: $forecast->forecastId,
            policyId: self::POLICY_ID,
            policyVersion: self::POLICY_VERSION,
            decision: $decision,
            directionalBias: 'UNDETERMINED',
            reasonCodes: $reasonCodes,
            forecastCalibrated: $forecastCalibrated,
            dataQuality: $candidate->eligibleForAnalysis ? 'ELIGIBLE' : 'INELIGIBLE',
            simulationCostProfile: 'not-implemented-no-cost-model',
            inputHashSha256: $inputHash,
        );
    }
}
