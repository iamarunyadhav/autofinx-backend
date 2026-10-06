<?php

namespace Tests\Feature\Ml\Support;

use App\Domain\Ml\Shadow\ClassifierPromotionPolicy;
use App\Domain\Ml\Shadow\FundingRateService;
use App\Domain\Ml\Shadow\MlModelRegistry;
use App\Domain\Ml\Shadow\MlShadowTrainer;
use App\Domain\Ml\Shadow\PromotionPolicy;
use App\Domain\Ml\Shadow\ShadowFeatureBuilder;
use App\Domain\Trading\QuickTradeCostModel;
use App\Models\MlTrainingRun;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Unit\Ml\MlShadowTestHelpers;

/** Shared setup for the ML training control-plane tests (sqlite, no network, no real python). */
trait MlTrainingTestSupport
{
    use MlShadowTestHelpers;

    protected FakeMlShadowTrainer $trainer;

    protected function bootMlTraining(): void
    {
        $this->configureMlForTests();
        File::deleteDirectory(storage_path('framework/testing/ml-shadow'));
        config([
            'ml.promotion.min_holdout_rows' => 10, 'ml.training.min_total_mature_labels' => 0, 'ml.training.candidate_types' => ['regression'],
            'ml.training.heartbeat_seconds' => 1, 'ml.training.poll_interval_ms' => 100,
            'ml.training.stall_seconds' => 180, 'ml.training.auto_retry' => true, 'ml.training.max_attempts' => 3,
            'ml.training.retry_backoff_minutes' => [2, 4, 8],
            'ml.horizons_minutes' => [15, 60, 120, 240, 1440],
        ]);
        $this->app->singleton(MlShadowTrainer::class, fn ($app) => new FakeMlShadowTrainer(
            $app->make(MlModelRegistry::class), $app->make(PromotionPolicy::class), $app->make(ClassifierPromotionPolicy::class),
            $app->make(QuickTradeCostModel::class), $app->make(FundingRateService::class),
        ));
        $this->trainer = $this->app->make(MlShadowTrainer::class);
    }

    protected function cleanMlTraining(): void
    {
        File::deleteDirectory(storage_path('framework/testing/ml-shadow'));
    }

    /** Trainer result that writes regression artifacts + metadata to the model directory. */
    protected function succeedWith(float $mae = 0.40, string $symbol = 'BTCUSDT', int $horizon = 60): void
    {
        $this->trainer->trainerResult = function (string $directory) use ($mae, $symbol, $horizon): array {
            return $this->writeArtifacts($directory, $this->metadata($mae, $symbol, $horizon));
        };
    }

    /** @param array<string, mixed> $metadata */
    protected function writeArtifacts(string $directory, array $metadata): array
    {
        File::ensureDirectoryExists($directory);
        foreach (['model.txt', 'q10.txt', 'q90.txt'] as $file) {
            file_put_contents($directory.'/'.$file, $file.'-'.uniqid('', true));
        }
        file_put_contents($directory.'/metadata.json', json_encode($metadata));

        return $metadata;
    }

    protected function metadata(float $mae = 0.40, string $symbol = 'BTCUSDT', int $horizon = 60): array
    {
        return [
            'symbol' => $symbol, 'horizon_minutes' => $horizon, 'feature_schema_version' => ShadowFeatureBuilder::SCHEMA_VERSION,
            'features' => ShadowFeatureBuilder::FEATURES, 'target_unit' => 'return_pct',
            'rows' => 3000, 'train_rows' => 2400, 'holdout_rows' => 500,
            'training_window_from_ms' => 1, 'training_window_to_ms' => 2, 'holdout_from_ms' => 3, 'holdout_to_ms' => 4,
            'metrics' => [
                'holdout' => ['rows' => 500, 'mae_pct' => $mae, 'baseline_zero_mae_pct' => 0.5, 'direction_hit_pct' => 53,
                    'fee_aware' => ['trade_count' => 0, 'average_net_pct' => null, 'total_net_pct' => 0.0], 'interval_coverage_pct' => 79],
                'walk_forward' => ['fold_count' => 3, 'folds' => []],
                'champion_holdout' => null, 'champion_comparable' => false,
            ],
        ];
    }

    /** A real child process that never needs python: runs the given PHP code. */
    protected function phpChild(string $code, int $timeout = 120): Process
    {
        return new Process([PHP_BINARY, '-r', $code], null, null, null, $timeout);
    }

    /** @param array<string, mixed> $attributes */
    protected function makeRun(array $attributes = []): MlTrainingRun
    {
        $now = MlTrainingRun::utcNow();

        return MlTrainingRun::query()->create($attributes + [
            'symbol' => 'BTCUSDT', 'horizon_minutes' => 60, 'model_type' => 'regression', 'status' => MlTrainingRun::QUEUED,
            'phase' => 'queued', 'source' => MlTrainingRun::SOURCE_UI, 'attempt' => 1, 'max_attempts' => 3, 'queued_at' => $now,
        ])->refresh();
    }

    protected function workspaceWithRole(string $role): array
    {
        $workspace = Workspace::create(['id' => (string) Str::uuid(), 'name' => 'WS']);
        $user = User::factory()->create();
        WorkspaceMember::create(['id' => (string) Str::uuid(), 'workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => $role]);

        return [$workspace, $user];
    }

    protected function utcAgo(int $seconds): string
    {
        return gmdate('Y-m-d H:i:s', time() - $seconds);
    }
}
