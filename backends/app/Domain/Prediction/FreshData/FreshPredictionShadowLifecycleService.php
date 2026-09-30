<?php

namespace App\Domain\Prediction\FreshData;

use App\Models\PredictionFreshModelCandidate;
use App\Models\PredictionFreshModelRegistry;
use App\Models\PredictionObservation;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Controls which audited candidate is visible to the shadow predictor.
 * This is deliberately separate from training and never enables influence or
 * execution. The old artifact stays immutable in its dataset directory, so
 * rollback only moves the shadow pointer and republishes that verified file.
 */
final class FreshPredictionShadowLifecycleService
{
    public function activate(PredictionFreshModelCandidate $candidate): PredictionFreshModelRegistry
    {
        $this->assertEligible($candidate);

        return DB::transaction(function () use ($candidate): PredictionFreshModelRegistry {
            $current = PredictionFreshModelRegistry::query()
                ->where('symbol', $candidate->symbol)
                ->where('timeframe', $candidate->timeframe)
                ->where('provider', $candidate->provider)
                ->lockForUpdate()
                ->first();

            if ($current && $current->candidate_id !== $candidate->id) {
                PredictionFreshModelCandidate::query()
                    ->whereKey($current->candidate_id)
                    ->where('status', 'SHADOW_REGISTERED')
                    ->update(['status' => 'SHADOW_RETIRED']);
            }

            $entry = PredictionFreshModelRegistry::query()->updateOrCreate([
                'symbol' => $candidate->symbol,
                'timeframe' => $candidate->timeframe,
                'provider' => $candidate->provider,
            ], [
                'candidate_id' => $candidate->id,
                'status' => 'SHADOW_ONLY',
                'execution' => false,
                'influence' => false,
            ]);
            $candidate->forceFill(['status' => 'SHADOW_REGISTERED'])->save();

            return $entry->refresh();
        });
    }

    private function assertEligible(PredictionFreshModelCandidate $candidate): void
    {
        if (! in_array($candidate->status, ['QUALITY_PASSED', 'SHADOW_REGISTERED', 'SHADOW_RETIRED'], true)) {
            throw new InvalidArgumentException('Only a quality-passed or previously published shadow candidate can be activated.');
        }
        if (! $candidate->dataset || $candidate->dataset->status !== 'SEALED') {
            throw new InvalidArgumentException('Shadow activation requires a sealed dataset candidate.');
        }
        if (! is_file($candidate->artifact_path) || hash_file('sha256', $candidate->artifact_path) !== $candidate->artifact_sha256) {
            throw new RuntimeException('Candidate artifact integrity check failed.');
        }
        if (data_get($candidate->metrics, 'quality_gate.passed') !== true) {
            throw new InvalidArgumentException('Candidate metadata has not passed the quality gate.');
        }

        // Once an artifact has enough settled shadow evidence, do not silently
        // re-activate the same degraded artifact. A new candidate can still be
        // reviewed normally, and this remains a manual review gate only.
        $modelVersion = (string) data_get($candidate->metrics, 'model_version');
        if ($modelVersion !== '') {
            $observations = PredictionObservation::query()
                ->where('provider', 'external_ml')
                ->where('provider_version', $modelVersion)
                ->where('status', 'EVALUATED')
                ->whereNotNull('direction_correct')
                ->get(['direction_correct']);
            $samples = $observations->count();
            if ($samples > 0) {
                $accuracy = $observations->where('direction_correct', true)->count() / $samples * 100;
                $health = app(PredictionShadowPerformanceGuard::class)->assess(
                    (float) data_get($candidate->metrics, 'metrics.direction_accuracy_pct'),
                    $accuracy,
                    $samples,
                );
                if ($health['status'] === 'REVIEW_REQUIRED') {
                    throw new InvalidArgumentException('Shadow model requires review before re-activation: '.$health['reason']);
                }
            }
        }
    }
}
