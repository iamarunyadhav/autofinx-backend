<?php

namespace Tests\Unit\Ml;

use App\Domain\Ml\Shadow\ClassifierPromotionPolicy;
use App\Domain\Ml\Shadow\FeatureLabelService;
use App\Domain\Ml\Shadow\FundingRateService;
use App\Domain\Ml\Shadow\MlModelRegistry;
use App\Domain\Ml\Shadow\MlShadowTrainer;
use App\Domain\Ml\Shadow\PromotionPolicy;
use App\Domain\Ml\Shadow\RetrainEligibility;
use App\Domain\Ml\Shadow\ShadowFeatureBuilder;
use App\Domain\Trading\QuickTradeCostModel;
use App\Jobs\TrainMlShadowModelJob;
use App\Models\MlChampion;
use App\Models\MlModel;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

final class ShadowTrainingPromotionTest extends TestCase
{
    use MlShadowTestHelpers;

    /** @var list<array<string, mixed>> metadata the fake trainer returns, in order */
    private array $runs = [];

    /** @var list<array<string, mixed>> manifests the fake trainer received */
    public array $manifests = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMlForTests();
        File::deleteDirectory(storage_path('framework/testing/ml-shadow'));
        config(['ml.training.min_total_mature_labels' => 50, 'ml.training.min_new_mature_labels' => 20, 'ml.promotion.min_holdout_rows' => 10]);

        $test = $this;
        $this->app->bind(MlShadowTrainer::class, fn ($app) => new class($app->make(MlModelRegistry::class), $app->make(PromotionPolicy::class), $app->make(ClassifierPromotionPolicy::class), $app->make(QuickTradeCostModel::class), $app->make(FundingRateService::class), $test) extends MlShadowTrainer
        {
            public function __construct($registry, $policy, $classifierPolicy, $costs, $funding, private readonly ShadowTrainingPromotionTest $test)
            {
                parent::__construct($registry, $policy, $classifierPolicy, $costs, $funding);
            }

            protected function runTrainer(array $export, string $directory, ?MlModel $champion, string $type = self::TYPE_REGRESSION): array
            {
                $this->test->manifests[] = json_decode((string) file_get_contents($export['manifest']), true);

                return $this->test->fakeTrainerRun($directory, $champion, $type);
            }
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('framework/testing/ml-shadow'));
        parent::tearDown();
    }

    /** @internal called by the fake trainer */
    public function fakeTrainerRun(string $directory, $champion, string $type = MlShadowTrainer::TYPE_REGRESSION): array
    {
        $metadata = array_shift($this->runs);
        if ($metadata instanceof \Throwable) {
            throw $metadata;
        }
        File::ensureDirectoryExists($directory);
        $files = $type === MlShadowTrainer::TYPE_SIDE_CLASSIFIER ? ['buy.txt', 'sell.txt'] : ['model.txt', 'q10.txt', 'q90.txt'];
        foreach ($files as $file) {
            file_put_contents($directory.'/'.$file, $file.'-'.uniqid('', true));
        }
        file_put_contents($directory.'/metadata.json', json_encode($metadata));

        return $metadata;
    }

    private function metadata(float $mae, float $totalNet = 0.0, ?array $champion = null, int $holdoutRows = 500, array $overrides = []): array
    {
        return array_replace_recursive([
            'symbol' => 'BTCUSDT', 'horizon_minutes' => 60, 'feature_schema_version' => ShadowFeatureBuilder::SCHEMA_VERSION,
            'features' => ShadowFeatureBuilder::FEATURES, 'target_unit' => 'return_pct',
            'rows' => 3000, 'train_rows' => 2400, 'holdout_rows' => $holdoutRows,
            'training_window_from_ms' => 1, 'training_window_to_ms' => 2, 'holdout_from_ms' => 3, 'holdout_to_ms' => 4,
            'metrics' => [
                'holdout' => ['rows' => $holdoutRows, 'mae_pct' => $mae, 'baseline_zero_mae_pct' => 0.5, 'direction_hit_pct' => 53,
                    'fee_aware' => ['trade_count' => $totalNet !== 0.0 ? 10 : 0, 'average_net_pct' => $totalNet !== 0.0 ? $totalNet / 10 : null, 'total_net_pct' => $totalNet],
                    'interval_coverage_pct' => 79],
                'walk_forward' => ['fold_count' => 3, 'folds' => []],
                'champion_holdout' => $champion,
                'champion_comparable' => $champion !== null,
            ],
        ], $overrides);
    }

    /** $candles usable training rows: v3 needs LONG_LOOKBACK candles of history first. */
    private function seedMatureLabels(int $candles): void
    {
        $total = $candles + ShadowFeatureBuilder::LONG_LOOKBACK;
        $this->seedCandles('BTCUSDT', $this->alignedOpen($total + 10), $total);
        app(FeatureLabelService::class)->build('BTCUSDT');
    }

    public function test_window_comparison_scores_every_window_on_one_export_and_registers_nothing(): void
    {
        $this->seedMatureLabels(400);
        config(['ml.training.recency_half_life_days' => 90]);
        $this->runs = [$this->metadata(0.4), $this->metadata(0.4), new RuntimeException('python blew up')];

        $report = app(MlShadowTrainer::class)->compareWindows('BTCUSDT', 60, [12, 0, 36]);

        $this->assertSame(0, MlModel::query()->count());                         // nothing registered
        $this->assertSame(0, MlChampion::query()->count());                       // nothing promoted
        $this->assertSame([12, 0, 36], array_column($this->manifests, 'window_months'));
        $this->assertEquals([90, 90, 90], array_column($this->manifests, 'recency_half_life_days'));
        $this->assertCount(1, array_unique(array_column($this->manifests, 'rows'))); // one export, same rows
        $this->assertSame([12, 0, 36], array_column($report['results'], 'window_months'));
        $this->assertArrayHasKey('would_pass_gate', $report['results'][0]);
        $this->assertSame('python blew up', $report['results'][2]['error']);      // one failure never stops the rest
        $this->assertCount(0, File::glob(config('ml.training.export_dir').'/experiment-*'));  // temp files cleaned
        $this->assertNotEmpty(File::glob(storage_path('app/ml-shadow/experiments').'/BTCUSDT_h60_*.json'));
        File::deleteDirectory(storage_path('app/ml-shadow/experiments'));
    }

    public function test_insufficient_mature_labels_means_no_training_and_no_dispatch(): void
    {
        Queue::fake();
        config(['ml.training.min_total_mature_labels' => 100000]);
        $this->seedMatureLabels(120);

        $check = app(RetrainEligibility::class)->check('BTCUSDT', 60);
        $this->assertFalse($check['eligible']);
        $this->assertSame('insufficient_mature_labels', $check['reason']);

        $this->artisan('ml:retrain:check', ['--symbol' => ['BTCUSDT']])->assertSuccessful();
        Queue::assertNothingPushed();

        (new TrainMlShadowModelJob('BTCUSDT', 60))->handle(app(RetrainEligibility::class), app(MlShadowTrainer::class));
        $this->assertSame(0, MlModel::query()->count());
    }

    public function test_enough_labels_dispatches_one_job_per_horizon(): void
    {
        Queue::fake();
        $this->seedMatureLabels(400);
        $this->artisan('ml:retrain:check', ['--symbol' => ['BTCUSDT']])->assertSuccessful();
        Queue::assertPushed(TrainMlShadowModelJob::class, 4); // 60, 120, 240, 1440
    }

    public function test_first_candidate_becomes_champion_with_checksummed_artifacts(): void
    {
        $this->seedMatureLabels(400);
        $this->runs = [$this->metadata(0.40)];

        $model = app(MlShadowTrainer::class)->train('BTCUSDT', 60);

        $this->assertSame('CHAMPION', $model->status);
        $this->assertSame('first_champion_passed_absolute_checks', $model->decision_reason);
        $manifest = json_decode((string) file_get_contents(config('ml.training.champion_dir').'/BTCUSDT-h60.json'), true);
        $this->assertSame($model->model_version, $manifest['model_version']);
        $this->assertFalse($manifest['execution']);
        foreach ($manifest['files'] as $file) {
            $this->assertSame($file['sha256'], hash_file('sha256', config('ml.training.champion_dir').'/'.$file['file']));
        }
    }

    public function test_worse_candidate_is_rejected_and_champion_is_kept(): void
    {
        $this->seedMatureLabels(400);
        $this->runs = [
            $this->metadata(0.40),
            // Better on its own, but WORSE than the champion on the same holdout.
            $this->metadata(0.38, champion: ['mae_pct' => 0.35, 'fee_aware' => ['total_net_pct' => 0.0]]),
        ];
        $trainer = app(MlShadowTrainer::class);
        $champion = $trainer->train('BTCUSDT', 60);
        config(['ml.training.min_new_mature_labels' => 0]);

        $candidate = $trainer->train('BTCUSDT', 60);

        $this->assertSame('REJECTED', $candidate->status);
        $this->assertSame('mae_not_better_than_champion', $candidate->decision_reason);
        $this->assertSame($champion->id, MlChampion::query()->where('symbol', 'BTCUSDT')->where('horizon_minutes', 60)->value('model_id'));
        $this->assertSame('CHAMPION', $champion->refresh()->status);
        $manifest = json_decode((string) file_get_contents(config('ml.training.champion_dir').'/BTCUSDT-h60.json'), true);
        $this->assertSame($champion->model_version, $manifest['model_version']);
    }

    public function test_better_candidate_replaces_champion_and_rollback_restores_it(): void
    {
        $this->seedMatureLabels(400);
        $this->runs = [
            $this->metadata(0.40),
            $this->metadata(0.30, totalNet: 1.5, champion: ['mae_pct' => 0.40, 'fee_aware' => ['total_net_pct' => 0.0]]),
        ];
        $trainer = app(MlShadowTrainer::class);
        $first = $trainer->train('BTCUSDT', 60);
        $second = $trainer->train('BTCUSDT', 60);

        $this->assertSame('CHAMPION', $second->status);
        $this->assertSame('RETIRED', $first->refresh()->status);

        $restored = app(MlModelRegistry::class)->rollback('BTCUSDT', 60);
        $this->assertSame($first->id, $restored->id);
        $this->assertSame('CHAMPION', $restored->status);
        $this->assertSame('RETIRED', $second->refresh()->status);
        $manifest = json_decode((string) file_get_contents(config('ml.training.champion_dir').'/BTCUSDT-h60.json'), true);
        $this->assertSame($first->model_version, $manifest['model_version']);
    }

    public function test_training_failure_records_failed_and_keeps_champion(): void
    {
        $this->seedMatureLabels(400);
        $this->runs = [$this->metadata(0.40), new RuntimeException('python exploded')];
        $trainer = app(MlShadowTrainer::class);
        $champion = $trainer->train('BTCUSDT', 60);

        try {
            $trainer->train('BTCUSDT', 60);
            $this->fail('expected failure');
        } catch (RuntimeException) {
        }

        $this->assertSame(1, MlModel::query()->where('status', 'FAILED')->count());
        $this->assertSame($champion->id, app(MlModelRegistry::class)->champion('BTCUSDT', 60)?->id);
    }

    public function test_feature_schema_mismatch_from_trainer_is_rejected(): void
    {
        $this->seedMatureLabels(400);
        $this->runs = [$this->metadata(0.40, overrides: ['feature_schema_version' => 'some-other-schema'])];

        try {
            app(MlShadowTrainer::class)->train('BTCUSDT', 60);
            $this->fail('expected schema mismatch');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('schema', $exception->getMessage());
        }
        $this->assertNull(app(MlModelRegistry::class)->champion('BTCUSDT', 60));
        $this->assertSame('FAILED', MlModel::query()->firstOrFail()->status);
    }

    public function test_policy_rejection_reasons(): void
    {
        $policy = new PromotionPolicy;
        $this->assertSame('mae_not_better_than_zero_return_baseline', $policy->decide($this->metadata(0.6), false)['reason']);
        $this->assertSame('fee_aware_net_negative_on_holdout', $policy->decide($this->metadata(0.4, totalNet: -2.0), false)['reason']);
        $this->assertSame('insufficient_holdout_rows', $policy->decide($this->metadata(0.4, holdoutRows: 5), false)['reason']);
        $this->assertSame('champion_not_comparable_on_same_holdout', $policy->decide($this->metadata(0.4), true)['reason']);
        $this->assertSame('fee_aware_net_worse_than_champion', $policy->decide(
            $this->metadata(0.30, totalNet: 0.5, champion: ['mae_pct' => 0.40, 'fee_aware' => ['total_net_pct' => 1.0]]), true)['reason']);
        $this->assertTrue($policy->decide($this->metadata(0.4), false)['promote']);
    }

    public function test_noise_level_improvement_over_baseline_is_not_a_champion(): void
    {
        // Production BTC 15m case: 0.12420 vs 0.12423 baseline (~0.02% better).
        $noise = $this->metadata(0.4999, overrides: ['metrics' => ['holdout' => ['baseline_zero_mae_pct' => 0.5]]]);
        $this->assertSame('mae_not_better_than_zero_return_baseline', (new PromotionPolicy)->decide($noise, false)['reason']);
        $real = $this->metadata(0.49, overrides: ['metrics' => ['holdout' => ['baseline_zero_mae_pct' => 0.5]]]);
        $this->assertTrue((new PromotionPolicy)->decide($real, false)['promote']); // 2% better
    }

    public function test_export_is_chronological_single_symbol_single_horizon(): void
    {
        $this->seedMatureLabels(400);
        $this->seedCandles('ETHUSDT', $this->alignedOpen(1300), 1290);
        app(FeatureLabelService::class)->build('ETHUSDT');

        $export = app(MlShadowTrainer::class)->export('BTCUSDT', 60, '5m', 'export-test');
        $lines = array_map(fn ($l) => json_decode($l, true), array_filter(explode("\n", (string) file_get_contents($export['data']))));
        // Only MATURE labels that have a training-schema (v3) feature row.
        $expected = MlShadowTrainer::matureTrainingRows('BTCUSDT', 60, '5m', ShadowFeatureBuilder::SCHEMA_VERSION)->count();
        $this->assertGreaterThan(0, $expected);

        $this->assertCount($expected, $lines);
        $times = array_column($lines, 't');
        $sorted = $times;
        sort($sorted);
        $this->assertSame($sorted, $times);
        $this->assertCount(count(ShadowFeatureBuilder::FEATURES), $lines[0]['x']);
        $manifest = json_decode((string) file_get_contents($export['manifest']), true);
        $this->assertSame(12, $manifest['horizon_steps']);
        $this->assertSame(ShadowFeatureBuilder::FEATURES, $manifest['features']);
    }
}
