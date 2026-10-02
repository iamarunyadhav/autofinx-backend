<?php

namespace Tests\Unit\Ml;

use App\Domain\Ml\Shadow\ClassifierDecision;
use App\Domain\Ml\Shadow\ClassifierPromotionPolicy;
use App\Domain\Ml\Shadow\FeatureLabelService;
use App\Domain\Ml\Shadow\FundingRateService;
use App\Domain\Ml\Shadow\MlModelRegistry;
use App\Domain\Ml\Shadow\MlShadowTrainer;
use App\Domain\Ml\Shadow\ShadowEvaluationService;
use App\Domain\Ml\Shadow\ShadowFeatureBuilder;
use App\Domain\Ml\Shadow\ShadowPredictionService;
use App\Models\MlChampion;
use App\Models\MlFundingRate;
use App\Models\MlModel;
use App\Models\MlShadowPrediction;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ShadowPhase3Test extends TestCase
{
    use MlShadowTestHelpers;

    private const HOUR = 3_600_000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMlForTests();
        Http::preventStrayRequests();
        File::deleteDirectory(storage_path('framework/testing/ml-shadow'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('framework/testing/ml-shadow'));
        parent::tearDown();
    }

    private function funding(string $symbol, int $timeMs, float $rate): void
    {
        MlFundingRate::query()->create(['symbol' => $symbol, 'funding_time_ms' => $timeMs, 'funding_rate' => $rate]);
    }

    // ---- funding ---------------------------------------------------------

    public function test_funding_window_includes_only_settlements_inside_t_to_t_plus_h(): void
    {
        $t = 1_700_000_000_000 - (1_700_000_000_000 % (8 * self::HOUR)); // on a settlement
        $this->funding('BTCUSDT', $t, 0.0009);                 // at T: position not yet open -> excluded
        $this->funding('BTCUSDT', $t + 8 * self::HOUR, 0.0001);   // inside
        $this->funding('BTCUSDT', $t + 16 * self::HOUR, -0.0003); // inside
        $this->funding('BTCUSDT', $t + 24 * self::HOUR, 0.0002);  // exactly T+h: still open -> included
        $this->funding('BTCUSDT', $t + 32 * self::HOUR, 0.0050);  // after exit -> excluded
        $this->funding('ETHUSDT', $t + 8 * self::HOUR, 0.0100);   // other symbol -> never mixed

        $buy = app(FundingRateService::class)->buyCostPct('BTCUSDT', [$t], 24 * self::HOUR)[$t];

        // Longs pay Σ(+0.0001 −0.0003 +0.0002) = 0 -> 0%; a +0.0001 net would be +0.01%.
        $this->assertEqualsWithDelta(0.0, $buy, 1e-12);
        $oneSettlement = app(FundingRateService::class)->buyCostPct('BTCUSDT', [$t], 8 * self::HOUR)[$t];
        $this->assertEqualsWithDelta(0.01, $oneSettlement, 1e-12);             // long pays +0.01%
        $this->assertEqualsWithDelta(0.0, app(FundingRateService::class)->buyCostPct('BTCUSDT', [$t], self::HOUR)[$t], 1e-12); // 1h: none

        // Per-side signs: a SELL's funding cost is exactly the negative.
        $decision = (new ClassifierDecision)->decide(0.9, 0.9, $this->params(1.0, -0.2), ['cost_pct' => 0.1], $oneSettlement, 0.5);
        $this->assertEqualsWithDelta(0.9 * 1.0 + 0.1 * -0.2 - 0.1 - 0.01, $decision['expected_net_buy_pct'], 1e-9);
        $this->assertEqualsWithDelta(0.9 * 1.0 + 0.1 * -0.2 - 0.1 + 0.01, $decision['expected_net_sell_pct'], 1e-9);
    }

    public function test_funding_sync_is_idempotent_resumable_and_validated(): void
    {
        $start = now('UTC')->subDays(5)->startOfDay()->getTimestampMs();
        $all = [];
        for ($i = 0; $i < 12; $i++) {
            $all[] = ['symbol' => 'BTCUSDT', 'fundingTime' => $start + $i * 8 * self::HOUR, 'fundingRate' => (string) (0.0001 * ($i % 3)), 'markPrice' => '60000'];
        }
        $all[] = ['symbol' => 'BTCUSDT', 'fundingTime' => $start + 99 * self::HOUR, 'fundingRate' => '0.9', 'markPrice' => '1']; // absurd -> rejected
        Http::fake(['*/fapi/v1/fundingRate*' => function (Request $request) use ($all) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

            return Http::response(array_values(array_filter($all, fn ($r) => $r['fundingTime'] >= (int) $q['startTime'])));
        }]);
        $service = app(FundingRateService::class);

        $first = $service->sync('BTCUSDT', $start);
        $again = $service->sync('BTCUSDT', $start);

        $this->assertSame(12, $first['written']);
        $this->assertSame(1, $first['rejected']);
        $this->assertSame(0, $again['written']);
        $this->assertSame(12, MlFundingRate::query()->count());
        // Resumes after the newest stored row, not from the start.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'startTime='.($start + 11 * 8 * self::HOUR + 1)));
    }

    // ---- classifier gate -------------------------------------------------

    private function classifierMetadata(float $net, int $trades, float $alwaysBuy, float $alwaysSell = -50.0, array $foldNets = [3, 2, 1], array $overrides = []): array
    {
        $folds = array_map(fn (float $n) => [
            'non_overlapping' => ['total_net_pct' => $n, 'trades' => 20],
            'baselines' => ['always_buy' => ['total_net_pct' => -1.0], 'always_sell' => ['total_net_pct' => -1.0], 'never_trade' => ['total_net_pct' => 0]],
        ], $foldNets);

        return array_replace_recursive([
            'model_type' => 'side_classifier',
            'metrics' => [
                'holdout' => [
                    'non_overlapping' => ['total_net_pct' => $net, 'trades' => $trades, 'raw_signals' => $trades * 7, 'net_win_rate_pct' => 55],
                    'baselines' => ['never_trade' => ['total_net_pct' => 0.0], 'always_buy' => ['total_net_pct' => $alwaysBuy], 'always_sell' => ['total_net_pct' => $alwaysSell]],
                    'effective_samples' => 1300,
                ],
                'walk_forward' => ['fold_count' => count($folds), 'folds' => $folds],
                'champion_holdout' => null, 'champion_comparable' => false,
            ],
        ], $overrides);
    }

    public function test_trend_riding_candidate_is_rejected_by_the_always_buy_baseline(): void
    {
        // Profitable on paper, but simply buying every time did better: it only rode the trend.
        $result = (new ClassifierPromotionPolicy)->decide($this->classifierMetadata(net: 12.0, trades: 40, alwaysBuy: 15.0), false, 60);
        $this->assertFalse($result['promote']);
        $this->assertSame('does_not_beat_always_buy', $result['reason']);
    }

    public function test_equal_to_baseline_is_rejected(): void
    {
        $policy = new ClassifierPromotionPolicy;
        $this->assertSame('does_not_beat_always_buy', $policy->decide($this->classifierMetadata(10.0, 40, alwaysBuy: 10.0), false, 60)['reason']);
        $this->assertSame('does_not_beat_always_sell', $policy->decide($this->classifierMetadata(10.0, 40, alwaysBuy: 1.0, alwaysSell: 10.0), false, 60)['reason']);
        // never-trade baseline (0): a zero-net model is rejected too.
        $this->assertSame('holdout_net_not_positive_after_costs', $policy->decide($this->classifierMetadata(0.0, 40, alwaysBuy: -5.0), false, 60)['reason']);
    }

    public function test_classifier_gate_checks_trades_folds_and_champion(): void
    {
        $policy = new ClassifierPromotionPolicy;
        $this->assertSame('too_few_non_overlapping_trades', $policy->decide($this->classifierMetadata(10.0, 5, alwaysBuy: 1.0), false, 60)['reason']);
        $this->assertTrue($policy->decide($this->classifierMetadata(10.0, 8, alwaysBuy: 1.0), false, 1440)['promote']); // 1d needs only 8
        $this->assertSame('net_not_positive_across_folds', $policy->decide($this->classifierMetadata(10.0, 40, alwaysBuy: 1.0, foldNets: [3, -2, -1]), false, 60)['reason']);
        $this->assertSame('classifier_passed_fee_aware_gate', $policy->decide($this->classifierMetadata(10.0, 40, alwaysBuy: 1.0), false, 60)['reason']);

        $vsChampion = fn (float $championNet) => $policy->decide($this->classifierMetadata(10.0, 40, alwaysBuy: 1.0, overrides: [
            'metrics' => ['champion_holdout' => ['total_net_pct' => $championNet], 'champion_comparable' => true],
        ]), true, 60);
        $this->assertSame('not_better_than_champion', $vsChampion(9.8)['reason']); // margin 0.5
        $this->assertTrue($vsChampion(9.0)['promote']);
        $this->assertSame('champion_not_comparable_on_same_holdout', $policy->decide($this->classifierMetadata(10.0, 40, alwaysBuy: 1.0), true, 60)['reason']);
    }

    // ---- classifier decision + prediction --------------------------------

    private function params(float $win, float $other): array
    {
        return ['probability_floor' => 0.55, 'buy' => ['avg_win_pct' => $win, 'avg_other_pct' => $other], 'sell' => ['avg_win_pct' => $win, 'avg_other_pct' => $other]];
    }

    public function test_classifier_decision_needs_floor_and_positive_expected_net(): void
    {
        $d = new ClassifierDecision;
        $costs = ['cost_pct' => 0.12];
        $this->assertSame('BUY', $d->decide(0.70, 0.10, $this->params(0.8, -0.3), $costs, 0.0)['decision']);
        $this->assertSame('SELL', $d->decide(0.10, 0.70, $this->params(0.8, -0.3), $costs, 0.0)['decision']);
        $this->assertSame('no_side_probability_above_floor', $d->decide(0.50, 0.50, $this->params(0.8, -0.3), $costs, 0.0)['decision_reason']);
        // Above the floor but costs eat the edge -> WAIT.
        $this->assertSame('expected_net_not_positive_after_costs', $d->decide(0.60, 0.10, $this->params(0.3, -0.3), $costs, 0.0)['decision_reason']);
        // Both qualify -> the higher expected net wins.
        $this->assertSame('SELL', $d->decide(0.60, 0.80, $this->params(0.8, -0.3), $costs, 0.0)['decision']);
    }

    private function classifierChampion(int $horizon = 60): MlModel
    {
        $model = MlModel::query()->create([
            'id' => (string) Str::uuid(), 'model_version' => 'qtml-cls-'.$horizon, 'symbol' => 'BTCUSDT', 'horizon_minutes' => $horizon,
            'timeframe' => '5m', 'feature_schema_version' => ShadowFeatureBuilder::SCHEMA_VERSION, 'model_type' => 'side_classifier',
            'status' => 'CHAMPION', 'train_samples' => 1000,
            'metrics' => ['decision_params' => $this->params(0.8, -0.3)],
        ]);
        MlChampion::query()->create(['symbol' => 'BTCUSDT', 'horizon_minutes' => $horizon, 'model_id' => $model->id]);

        return $model;
    }

    public function test_classifier_prediction_stores_probabilities_expected_nets_and_funding(): void
    {
        $count = ShadowFeatureBuilder::LONG_LOOKBACK + 20;
        $first = $this->alignedOpen($count);
        $this->seedCandles('BTCUSDT', $first, $count);
        app(FeatureLabelService::class)->build('BTCUSDT');
        $this->funding('BTCUSDT', $first, 0.0001);
        $this->classifierChampion();
        Http::fake(['*/v2/shadow/predict' => Http::response(['prediction' => [
            'status' => 'ready', 'model_type' => 'side_classifier', 'model_version' => 'qtml-cls-60',
            'feature_schema_version' => ShadowFeatureBuilder::SCHEMA_VERSION, 'p_buy' => 0.72, 'p_sell' => 0.08,
        ]])]);

        $result = app(ShadowPredictionService::class)->predictLatest('BTCUSDT', 60);

        $this->assertSame('CREATED', $result['status']);
        $p = $result['prediction'];
        $this->assertSame('BUY', $p->decision);
        $this->assertEqualsWithDelta(0.72, $p->p_buy, 1e-9);
        $this->assertEqualsWithDelta(0.08, $p->p_sell, 1e-9);
        $this->assertNull($p->predicted_return);
        $this->assertSame('side_classifier', $p->model_type);
        $expectedFunding = \App\Domain\Ml\Shadow\FundingRateService::settlementsInWindow((int) $p->anchor_time_ms, 60 * 60_000) * 0.01;
        $this->assertEqualsWithDelta($expectedFunding, $p->funding_estimate_buy_pct, 1e-9);
        $cost = (float) $p->cost_snapshot['cost_pct'];
        $this->assertEqualsWithDelta(0.72 * 0.8 + 0.28 * -0.3 - $cost - $expectedFunding, $p->expected_net_buy_pct, 1e-6);

        $payload = app(ShadowPredictionService::class)->payload('BTCUSDT', 3600, app(ShadowEvaluationService::class));
        $this->assertSame(0.72, $payload['prediction']['p_buy']);
        $this->assertSame('side_classifier', $payload['model']['model_type']);
    }

    public function test_scoring_charges_actual_funding_per_side(): void
    {
        $count = ShadowFeatureBuilder::LONG_LOOKBACK + 5;
        $first = $this->alignedOpen($count + 300);
        $this->seedCandles('BTCUSDT', $first, $count);
        $anchor = $first + $count * self::FIVE_MIN;
        $grid = 8 * self::HOUR;
        $settlement = (intdiv($anchor, $grid) + 1) * $grid;          // first settlement after T
        $this->funding('BTCUSDT', $settlement, 0.0002);               // +0.02% paid by longs
        $prediction = MlShadowPrediction::query()->create([
            'symbol' => 'BTCUSDT', 'horizon_minutes' => 1440, 'anchor_time_ms' => $anchor, 'target_time_ms' => $anchor + 1440 * 60_000,
            'feature_schema_version' => ShadowFeatureBuilder::SCHEMA_VERSION, 'model_type' => 'side_classifier',
            'current_price' => 100.0, 'decision' => 'SELL', 'cost_snapshot' => ['cost_pct' => 0.12], 'status' => 'PENDING',
        ]);
        $this->travelTo(now()->addMilliseconds($anchor + 1440 * 60_000 - now()->getTimestampMs() + 60_000));
        $this->seedCandles('BTCUSDT', $anchor + 1440 * 60_000 - self::FIVE_MIN, 1, fn () => 99.0); // −1%

        app(ShadowEvaluationService::class)->score();

        $prediction->refresh();
        $this->assertSame('SCORED', $prediction->status);
        // SELL: +1% move − 0.12% cost + 0.02% funding received.
        $this->assertEqualsWithDelta(1.0 - 0.12 + 0.02, $prediction->decision_net_pct, 1e-6);
        $this->assertTrue($prediction->direction_hit);
        $this->assertNull($prediction->abs_error_pct);
    }

    // ---- export / leakage ------------------------------------------------

    public function test_export_keeps_funding_and_returns_out_of_the_feature_vector(): void
    {
        $count = ShadowFeatureBuilder::LONG_LOOKBACK + 200;
        $first = $this->alignedOpen($count + 10);
        $this->seedCandles('BTCUSDT', $first, $count);
        app(FeatureLabelService::class)->build('BTCUSDT');
        $anchor = (int) MlShadowTrainer::matureTrainingRows('BTCUSDT', 60, '5m', ShadowFeatureBuilder::SCHEMA_VERSION)->min('l.anchor_time_ms');
        $this->funding('BTCUSDT', $anchor + 30 * 60_000, 0.0003);

        $export = app(MlShadowTrainer::class)->export('BTCUSDT', 60, '5m', 'phase3-export');
        $rows = array_map(fn ($l) => json_decode($l, true), array_filter(explode("\n", (string) file_get_contents($export['data']))));
        $manifest = json_decode((string) file_get_contents($export['manifest']), true);

        $this->assertSame(ShadowFeatureBuilder::FEATURES, $manifest['features']);
        foreach ($rows as $row) {
            $this->assertCount(count(ShadowFeatureBuilder::FEATURES), $row['x']); // inputs only — no y / fb inside
        }
        $this->assertEqualsWithDelta(0.03, $rows[0]['fb'], 1e-12);
        $this->assertEqualsWithDelta(0.0, $rows[count($rows) - 1]['fb'], 1e-12);
        $this->assertSame(12, $manifest['horizon_steps']);
        $this->assertSame(300_000, $manifest['step_ms']);
    }

    public function test_classifier_champion_publishes_its_side_models_and_calibration(): void
    {
        $dir = storage_path('framework/testing/ml-shadow/models/cls');
        File::ensureDirectoryExists($dir);
        foreach (['buy.txt', 'sell.txt'] as $file) {
            file_put_contents("{$dir}/{$file}", $file.uniqid());
        }
        $sides = ['buy' => ['isotonic_x' => [0.1, 0.9], 'isotonic_y' => [0.2, 0.8], 'avg_win_pct' => 0.8, 'avg_other_pct' => -0.3, 'base_rate' => 0.3],
            'sell' => ['isotonic_x' => [0.1, 0.9], 'isotonic_y' => [0.1, 0.7], 'avg_win_pct' => 0.9, 'avg_other_pct' => -0.2, 'base_rate' => 0.25]];
        $metadata = ['features' => ShadowFeatureBuilder::FEATURES, 'probability_floor' => 0.55, 'sides' => $sides, 'metrics' => ['holdout' => []]];
        file_put_contents("{$dir}/metadata.json", json_encode($metadata));
        $model = MlModel::query()->create(['id' => (string) Str::uuid(), 'symbol' => 'BTCUSDT', 'horizon_minutes' => 240, 'timeframe' => '5m',
            'feature_schema_version' => ShadowFeatureBuilder::SCHEMA_VERSION, 'model_type' => 'side_classifier', 'status' => 'TRAINING', 'artifact_dir' => $dir]);
        $registry = app(MlModelRegistry::class);

        $model = $registry->promote($registry->registerCandidate($model, $metadata), 'test');

        $manifest = json_decode((string) file_get_contents(config('ml.training.champion_dir').'/BTCUSDT-h240.json'), true);
        $this->assertSame('side_classifier', $manifest['model_type']);
        $this->assertSame(['buy', 'sell'], array_keys($manifest['files']));
        $this->assertSame($sides['buy']['isotonic_y'], $manifest['sides']['buy']['isotonic_y']);
        $this->assertSame(0.8, $model->metrics['decision_params']['buy']['avg_win_pct']);
        foreach ($manifest['files'] as $file) {
            $this->assertSame($file['sha256'], hash_file('sha256', config('ml.training.champion_dir').'/'.$file['file']));
        }
    }
}
