<?php

namespace Tests\Unit\MarketOpportunityBrain\Decision;

use App\Domain\MarketOpportunityBrain\Data\DiscoveryRecord;
use App\Domain\MarketOpportunityBrain\Data\ForecastRecord;
use App\Domain\MarketOpportunityBrain\Decision\ResearchDecisionPolicy;
use App\Domain\MarketOpportunityBrain\Enums\ForecastStatus;
use App\Domain\MarketOpportunityBrain\Enums\ResearchDecision;
use Tests\TestCase;

/**
 * Covers PHASES_AND_TEST_GATES.md invariants:
 *  - test_no_future_feature_known_at_after_decision
 *  - test_stale_or_unavailable_prediction_yields_wait
 *  - test_fee_funding_slippage_can_reject_gross_positive (here: cost model absence can never be
 *    silently skipped into a directional verdict)
 */
class ResearchDecisionPolicyTest extends TestCase
{
    private function eligibleCandidate(array $overrides = []): DiscoveryRecord
    {
        return new DiscoveryRecord(
            snapshotId: 'cand-1', exchange: 'BINANCE', market: 'USDM_PERPETUAL', symbol: $overrides['symbol'] ?? 'BTCUSDT',
            workspaceId: 'local-research-workspace', asOfUtc: '2026-10-15T00:00:00Z', lastClosedCandleUtc: '2026-10-14T23:45:00Z',
            listedAsOf: true, eligibleForAnalysis: $overrides['eligible'] ?? true, rank: 1, opportunityScore: 1.0,
            direction: null, sourceFreshnessSeconds: 0, reasonCodes: $overrides['reasonCodes'] ?? ['ELIGIBLE'],
            featureSchemaVersion: 'mob-discovery-v1', featureWindowHashSha256: 'abc',
        );
    }

    private function forecast(array $overrides = []): ForecastRecord
    {
        return new ForecastRecord(
            forecastId: 'f-1', workspaceId: 'local-research-workspace', exchange: 'BINANCE', market: 'USDM_PERPETUAL',
            symbol: $overrides['symbol'] ?? 'BTCUSDT', timeframe: '15m', horizonBars: 4,
            asOfUtc: '2026-10-15T00:00:00Z', knownByUtc: $overrides['knownByUtc'] ?? '2026-10-15T00:00:00Z',
            labelAvailableAtUtc: null, modelName: 'naive_last', modelRevision: 'v1',
            featureSchemaVersion: 'v1', datasetHashSha256: null,
            predictionQuantiles: ['p10' => 100.0, 'p50' => 100.0, 'p90' => 100.0],
            probabilities: $overrides['probabilities'] ?? ['up' => null, 'neutral' => null, 'down' => null],
            probabilityCalibrated: $overrides['calibrated'] ?? false,
            status: $overrides['status'] ?? ForecastStatus::OK,
            reason: $overrides['reason'] ?? null,
        );
    }

    /** @test */
    public function rejects_a_forecast_known_after_the_decision_timestamp(): void
    {
        $policy = new ResearchDecisionPolicy();
        $candidate = $this->eligibleCandidate();
        $forecast = $this->forecast(['knownByUtc' => '2026-10-15T01:00:00Z']); // known AFTER decision time below

        $decision = $policy->decide($candidate, $forecast, new \DateTimeImmutable('2026-10-15T00:00:00Z'));

        $this->assertSame(ResearchDecision::REJECT, $decision->decision);
        $this->assertContains('KNOWN_AT_AFTER_DECISION', $decision->reasonCodes);
    }

    /** @test */
    public function rejects_a_candidate_forecast_symbol_mismatch(): void
    {
        $policy = new ResearchDecisionPolicy();
        $decision = $policy->decide(
            $this->eligibleCandidate(['symbol' => 'BTCUSDT']),
            $this->forecast(['symbol' => 'ETHUSDT']),
            new \DateTimeImmutable('2026-10-15T00:00:00Z'),
        );

        $this->assertSame(ResearchDecision::REJECT, $decision->decision);
        $this->assertContains('CANDIDATE_FORECAST_SYMBOL_MISMATCH', $decision->reasonCodes);
    }

    /** @test */
    public function rejects_an_ineligible_candidate(): void
    {
        $policy = new ResearchDecisionPolicy();
        $decision = $policy->decide(
            $this->eligibleCandidate(['eligible' => false, 'reasonCodes' => ['INSUFFICIENT_LIQUIDITY_EVIDENCE']]),
            $this->forecast(),
            new \DateTimeImmutable('2026-10-15T00:00:00Z'),
        );

        $this->assertSame(ResearchDecision::REJECT, $decision->decision);
        $this->assertContains('CANDIDATE_NOT_ELIGIBLE', $decision->reasonCodes);
        $this->assertContains('INSUFFICIENT_LIQUIDITY_EVIDENCE', $decision->reasonCodes);
    }

    /** @test */
    public function waits_when_the_forecast_is_unavailable(): void
    {
        $policy = new ResearchDecisionPolicy();
        $decision = $policy->decide(
            $this->eligibleCandidate(),
            $this->forecast(['status' => ForecastStatus::UNAVAILABLE, 'reason' => 'NO_CLOSED_CANDLES_AVAILABLE']),
            new \DateTimeImmutable('2026-10-15T00:00:00Z'),
        );

        $this->assertSame(ResearchDecision::WAIT, $decision->decision);
        $this->assertContains('FORECAST_UNAVAILABLE', $decision->reasonCodes);
        $this->assertContains('NO_CLOSED_CANDLES_AVAILABLE', $decision->reasonCodes);
    }

    /** @test */
    public function waits_on_an_uncalibrated_forecast_matching_the_canonical_example_contract(): void
    {
        $policy = new ResearchDecisionPolicy();
        $decision = $policy->decide($this->eligibleCandidate(), $this->forecast(['calibrated' => false]), new \DateTimeImmutable('2026-10-15T00:00:00Z'));

        $this->assertSame(ResearchDecision::WAIT, $decision->decision);
        $this->assertContains('NO_CALIBRATION_EVIDENCE', $decision->reasonCodes);
        $this->assertFalse($decision->forecastCalibrated);
    }

    /** @test */
    public function never_emits_a_directional_verdict_even_when_hypothetically_calibrated_because_no_cost_model_exists(): void
    {
        $policy = new ResearchDecisionPolicy();
        $decision = $policy->decide(
            $this->eligibleCandidate(),
            $this->forecast(['calibrated' => true, 'probabilities' => ['up' => 0.7, 'neutral' => 0.2, 'down' => 0.1]]),
            new \DateTimeImmutable('2026-10-15T00:00:00Z'),
        );

        $this->assertSame(ResearchDecision::WAIT, $decision->decision);
        $this->assertContains('COST_MODEL_NOT_IMPLEMENTED', $decision->reasonCodes);
        $this->assertNotSame(ResearchDecision::LONG_CANDIDATE, $decision->decision);
        $this->assertNotSame(ResearchDecision::SHORT_CANDIDATE, $decision->decision);
    }

    /** @test */
    public function every_decision_is_execution_authorized_false_and_research_only(): void
    {
        $policy = new ResearchDecisionPolicy();
        $decision = $policy->decide($this->eligibleCandidate(), $this->forecast(), new \DateTimeImmutable('2026-10-15T00:00:00Z'));

        $arr = $decision->toArray();
        $this->assertFalse($arr['execution_authorized']);
        $this->assertSame('RESEARCH_ONLY', $arr['mode']);
    }

    /** @test */
    public function policy_is_deterministic_for_identical_inputs(): void
    {
        $policy = new ResearchDecisionPolicy();
        $candidate = $this->eligibleCandidate();
        $forecast = $this->forecast();
        $asOf = new \DateTimeImmutable('2026-10-15T00:00:00Z');

        $d1 = $policy->decide($candidate, $forecast, $asOf)->toArray();
        $d2 = $policy->decide($candidate, $forecast, $asOf)->toArray();

        $this->assertSame($d1, $d2);
    }
}
