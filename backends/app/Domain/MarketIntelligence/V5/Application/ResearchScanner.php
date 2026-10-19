<?php

namespace App\Domain\MarketIntelligence\V5\Application;

use App\Domain\MarketIntelligence\V5\ShadowPrediction;
use App\Domain\MarketOpportunityBrain\Contracts\MarketSnapshotProvider;
use App\Domain\MarketOpportunityBrain\Contracts\MarketUniverseProvider;
use App\Domain\MarketOpportunityBrain\Data\Candle;
use App\Models\V5ResearchScannerCandidate;
use App\Models\V5ResearchScannerRun;
use App\Models\V5ShadowPrediction as StoredShadowPrediction;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/** Isolated, research-only scanner. It has no trading dependencies. */
final class ResearchScanner
{
    /** @var list<Candle> */
    private array $observations = [];

    public function __construct(private readonly MarketUniverseProvider $universe, private readonly ?MarketSnapshotProvider $snapshots = null) {}

    public function scan(?DateTimeImmutable $asOf = null, int $limit = 3): array
    {
        $asOf ??= new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->observations = [];
        $candidates = [];
        $quality = new ResearchCandidateScorer;
        foreach ($this->universe->universeAsOf($asOf) as $record) {
            $item = $record->toArray();
            if (($item['eligible_for_analysis'] ?? false) !== true) {
                continue;
            }
            $item['ranking_score'] = (float) ($item['opportunity_score'] ?? 0.0);
            if ($this->snapshots !== null) {
                $assessed = (new MarketAssessmentService($this->snapshots))->assessWithObservations($item['symbol'], $asOf);
                $item['assessment'] = $assessed['assessment'];
                array_push($this->observations, ...$assessed['observations']);
                $selection = $quality->score($item, $assessed['assessment']);
                $item['v5_selection'] = $selection;
                $item['ranking_score'] = $selection['score'];
                $item['research_status'] = $selection['eligible'] ? 'QUALITY_SHORTLIST_CANDIDATE' : 'QUALITY_GATE_REJECTED';
                if (! $selection['eligible']) {
                    continue;
                }
            }
            $item['research_status'] ??= 'UNVALIDATED_RESEARCH_CANDIDATES';
            $item['execution_authorized'] = false;
            $candidates[] = $item;
        }
        usort($candidates, static fn (array $a, array $b) => $b['ranking_score'] <=> $a['ranking_score']);
        // Hysteresis: retain an incumbent candidate when a newcomer is only marginally
        // better. Material score changes still replace it; this only prevents UI churn.
        try {
            $previous = V5ResearchScannerRun::with('candidates')->latest('as_of')->first();
            $margin = (float) config('market_opportunity_brain.v5_anti_churn_score_margin', 0.05);
            $shortlistSize = max(1, (int) config('market_opportunity_brain.v5_research_universe.shortlist_size', 3));
            if ($previous && $margin > 0 && count($candidates) > $shortlistSize) {
                $incumbents = $previous->candidates->sortBy('rank')->take($shortlistSize)->pluck('symbol')->all();
                foreach ($incumbents as $slot => $symbol) {
                    $old = collect($candidates)->firstWhere('symbol', $symbol);
                    if (! $old) {
                        continue;
                    }
                    $challenger = $candidates[$shortlistSize - 1] ?? null;
                    if ($challenger && $challenger['ranking_score'] <= $old['ranking_score'] + $margin) {
                        $candidates = array_values(array_filter($candidates, fn ($x) => $x['symbol'] !== $symbol));
                        array_splice($candidates, min($slot, count($candidates)), 0, [$old]);
                    }
                }
            }
        } catch (\Throwable) { /* scanner remains usable before research tables exist */
        }
        foreach ($candidates as $index => &$candidate) {
            $candidate['rank'] = $index + 1;
        }
        unset($candidate);

        $requested = min(max(1, (int) config('market_opportunity_brain.v5_research_universe.shortlist_size', 3)), $limit);
        return ['as_of' => $asOf->format(DATE_ATOM), 'execution_authorized' => false, 'ranked_candidates' => $candidates,
            'source_status' => ['universe' => 'available', 'funding' => 'unavailable', 'open_interest' => 'unavailable'],
            'candidates' => array_slice($candidates, 0, max(0, $requested))];
    }

    /** Idempotent per source mode/as-of/limit; never authorizes execution. */
    public function persist(array $result, string $sourceMode, int $limit): V5ResearchScannerRun
    {
        $key = hash('sha256', $sourceMode.'|'.$result['as_of'].'|'.$limit);

        return DB::transaction(function () use ($key, $result, $sourceMode, $limit) {
            if ($this->observations !== []) {
                (new MarketObservationRecorder)->record($this->observations);
            }

            $run = V5ResearchScannerRun::firstOrCreate(['run_key' => $key], [
                'as_of' => $result['as_of'], 'source_mode' => $sourceMode, 'status' => 'completed',
                'requested_limit' => $limit, 'eligible_count' => count($result['candidates']),
                'source_status' => $result['source_status'],
            ]);
            foreach (($result['ranked_candidates'] ?? $result['candidates']) as $candidate) {
                V5ResearchScannerCandidate::updateOrCreate(
                    ['scanner_run_id' => $run->id, 'symbol' => $candidate['symbol']],
                    ['rank' => $candidate['rank'], 'ranking_score' => $candidate['ranking_score'],
                        'direction' => $candidate['direction'] ?? null, 'evidence' => $candidate,
                        'execution_authorized' => false]
                );
                if (isset($candidate['assessment'])) {
                    $forecastAt = new DateTimeImmutable($result['as_of']);
                    $prediction = new ShadowPrediction($candidate['symbol'], '24h', 1440, $forecastAt->format(DATE_ATOM), $forecastAt->modify('+24 hours')->format(DATE_ATOM), $candidate['assessment']['direction'], null, null, false, $candidate['assessment'], 'v5-heuristic-observation-v1', 'valid', 'uncalibrated');
                    $payload = $prediction->toArray();
                    $payload['forecast_at'] = $forecastAt->format('Y-m-d H:i:s');
                    $payload['outcome_available_at'] = $forecastAt->modify('+24 hours')->format('Y-m-d H:i:s');
                    $payload['reference_price'] = data_get($candidate, 'assessment.horizons.15m.reference_close');
                    StoredShadowPrediction::updateOrCreate(['symbol' => $prediction->symbol, 'horizon' => '24h', 'forecast_at' => $payload['forecast_at'], 'model_version' => $prediction->modelVersion], $payload + ['outcome_status' => 'pending']);
                }
            }

            return $run->fresh('candidates');
        });
    }
}
