<?php

namespace App\Domain\MarketIntelligence\V5\Application;

use App\Domain\MarketOpportunityBrain\Contracts\MarketUniverseProvider;
use App\Domain\MarketOpportunityBrain\Contracts\MarketSnapshotProvider;
use App\Domain\MarketIntelligence\V5\ShadowPrediction;
use App\Models\V5ShadowPrediction as StoredShadowPrediction;
use App\Models\V5ResearchScannerCandidate;
use App\Models\V5ResearchScannerRun;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/** Isolated, research-only scanner. It has no trading dependencies. */
final class ResearchScanner
{
    public function __construct(private readonly MarketUniverseProvider $universe, private readonly ?MarketSnapshotProvider $snapshots = null) {}

    public function scan(?DateTimeImmutable $asOf = null, int $limit = 3): array
    {
        $asOf ??= new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $candidates = [];
        foreach ($this->universe->universeAsOf($asOf) as $record) {
            $item = $record->toArray();
            if (($item['eligible_for_analysis'] ?? false) !== true) continue;
            $item['ranking_score'] = (float) ($item['opportunity_score'] ?? 0.0);
            if ($this->snapshots !== null) $item['assessment'] = (new MarketAssessmentService($this->snapshots))->assess($item['symbol'], $asOf);
            $item['research_status'] = 'UNVALIDATED_RESEARCH_CANDIDATES';
            $item['execution_authorized'] = false;
            $candidates[] = $item;
        }
        usort($candidates, static fn (array $a, array $b) => $b['ranking_score'] <=> $a['ranking_score']);
        foreach ($candidates as $index => &$candidate) $candidate['rank'] = $index + 1;
        unset($candidate);
        return ['as_of' => $asOf->format(DATE_ATOM), 'execution_authorized' => false,
            'source_status' => ['universe' => 'available', 'funding' => 'unavailable', 'open_interest' => 'unavailable'],
            'candidates' => array_slice($candidates, 0, max(0, min(3, $limit)))];
    }

    /** Idempotent per source mode/as-of/limit; never authorizes execution. */
    public function persist(array $result, string $sourceMode, int $limit): V5ResearchScannerRun
    {
        $key = hash('sha256', $sourceMode.'|'.$result['as_of'].'|'.$limit);
        return DB::transaction(function () use ($key, $result, $sourceMode, $limit) {
            $run = V5ResearchScannerRun::firstOrCreate(['run_key' => $key], [
                'as_of' => $result['as_of'], 'source_mode' => $sourceMode, 'status' => 'completed',
                'requested_limit' => $limit, 'eligible_count' => count($result['candidates']),
                'source_status' => $result['source_status'],
            ]);
            foreach ($result['candidates'] as $candidate) {
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
                    StoredShadowPrediction::updateOrCreate(['symbol' => $prediction->symbol, 'horizon' => '24h', 'forecast_at' => $payload['forecast_at'], 'model_version' => $prediction->modelVersion], $payload + ['outcome_status' => 'pending']);
                }
            }
            return $run->fresh('candidates');
        });
    }
}
