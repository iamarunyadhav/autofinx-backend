<?php

namespace Tests\Unit\Ml;

use App\Domain\Ml\Shadow\FeatureLabelService;
use App\Domain\Ml\Shadow\MlModelRegistry;
use App\Domain\Ml\Shadow\ShadowDecision;
use App\Domain\Ml\Shadow\ShadowEvaluationService;
use App\Domain\Ml\Shadow\ShadowFeatureBuilder;
use App\Domain\Ml\Shadow\ShadowPredictionService;
use App\Domain\Trading\QuickTradeCostModel;
use App\Models\MlModel;
use App\Models\MlShadowPrediction;
use App\Models\QuickTrade;
use App\Models\Workspace;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ShadowPredictionEvaluationTest extends TestCase
{
    use MlShadowTestHelpers;

    private int $first;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMlForTests();
        Http::preventStrayRequests();
        // The newest closed candle closes "now", so the newest anchor is fresh.
        // Enough history for the training schema (v3 reads 3 days of 5m bars).
        $count = ShadowFeatureBuilder::LONG_LOOKBACK + 35;
        $this->first = $this->alignedOpen($count);
        $this->seedCandles('BTCUSDT', $this->first, $count);
        app(FeatureLabelService::class)->build('BTCUSDT');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('framework/testing/ml-shadow'));
        parent::tearDown();
    }

    private function champion(int $horizon = 60, string $schema = ShadowFeatureBuilder::SCHEMA_VERSION): MlModel
    {
        $model = MlModel::query()->create([
            'id' => (string) Str::uuid(), 'model_version' => 'qtml-test-'.$horizon, 'symbol' => 'BTCUSDT', 'horizon_minutes' => $horizon,
            'timeframe' => '5m', 'feature_schema_version' => $schema, 'status' => 'CHAMPION', 'train_samples' => 1234,
            'metrics' => ['holdout' => ['interval_coverage_pct' => 81.0]],
        ]);
        \App\Models\MlChampion::query()->create(['symbol' => 'BTCUSDT', 'horizon_minutes' => $horizon, 'model_id' => $model->id]);

        return $model;
    }

    private function fakePython(array $prediction): void
    {
        Http::fake(['*/v2/shadow/predict' => Http::response(['prediction' => $prediction + [
            'status' => 'ready', 'model_version' => 'qtml-test-60', 'feature_schema_version' => ShadowFeatureBuilder::SCHEMA_VERSION,
        ]])]);
    }

    public function test_decision_uses_quick_trade_costs_exactly_once(): void
    {
        $costs = app(QuickTradeCostModel::class)->costs('taker');
        $decision = (new ShadowDecision)->decide(100.0, 0.005, 0.001, 0.009, $costs); // +0.5%

        $this->assertSame('BUY', $decision['decision']);
        $this->assertEqualsWithDelta(0.5 - $costs['cost_pct'], $decision['buy_net_pct'], 1e-9);
        $this->assertEqualsWithDelta(-0.5 - $costs['cost_pct'], $decision['sell_net_pct'], 1e-9);
        $this->assertEqualsWithDelta(100.5, $decision['predicted_price'], 1e-9);
        $this->assertEqualsWithDelta(100.1, $decision['predicted_low'], 1e-9);
        $this->assertEqualsWithDelta(100.9, $decision['predicted_high'], 1e-9);

        // Clears cost once but not the required (safety-multiplied) move => WAIT.
        $small = ($costs['cost_pct'] + ($costs['required_move_pct'] - $costs['cost_pct']) / 2) / 100;
        $this->assertSame('WAIT', (new ShadowDecision)->decide(100.0, -$small, null, null, $costs)['decision']);
    }

    public function test_prediction_is_stored_once_per_anchor_with_model_version(): void
    {
        $this->champion();
        $this->fakePython(['predicted_return' => -0.006, 'predicted_return_q10' => -0.01, 'predicted_return_q90' => 0.001]);
        $service = app(ShadowPredictionService::class);

        $first = $service->predictLatest('BTCUSDT', 60);
        $again = $service->predictLatest('BTCUSDT', 60);

        $this->assertSame('CREATED', $first['status']);
        $this->assertSame('EXISTS', $again['status']);
        $this->assertSame(1, MlShadowPrediction::query()->count());
        $prediction = $first['prediction'];
        $this->assertSame('SELL', $prediction->decision);
        $this->assertSame('qtml-test-60', $prediction->model_version);
        $this->assertSame((int) $prediction->anchor_time_ms + 3_600_000, (int) $prediction->target_time_ms);
        Http::assertSent(fn ($request) => $request['horizon_minutes'] === 60
            && $request['feature_schema_version'] === ShadowFeatureBuilder::SCHEMA_VERSION
            && array_keys($request['features']) === ShadowFeatureBuilder::FEATURES);
    }

    public function test_predictor_schema_or_version_mismatch_is_never_stored(): void
    {
        $this->champion();
        Http::fakeSequence('*/v2/shadow/predict')
            ->push(['prediction' => [
                'status' => 'ready', 'model_version' => 'qtml-some-other', 'feature_schema_version' => ShadowFeatureBuilder::SCHEMA_VERSION, 'predicted_return' => 0.01,
            ]])
            ->push(['prediction' => ['status' => 'rejected', 'reason' => 'feature_schema_version_mismatch']]);
        $this->assertSame('MODEL_VERSION_MISMATCH', app(ShadowPredictionService::class)->predictLatest('BTCUSDT', 60)['status']);
        $this->assertStringStartsWith('PREDICTOR_REJECTED', app(ShadowPredictionService::class)->predictLatest('BTCUSDT', 60)['status']);
        $this->assertSame(0, MlShadowPrediction::query()->count());
    }

    public function test_v1_champion_keeps_receiving_exactly_its_v1_inputs(): void
    {
        $this->champion(schema: ShadowFeatureBuilder::SCHEMA_V1);
        Http::fake(['*/v2/shadow/predict' => Http::response(['prediction' => [
            'status' => 'ready', 'model_version' => 'qtml-test-60', 'feature_schema_version' => ShadowFeatureBuilder::SCHEMA_V1, 'predicted_return' => 0.0001,
        ]])]);

        $result = app(ShadowPredictionService::class)->predictLatest('BTCUSDT', 60);

        $this->assertSame('CREATED', $result['status']);
        $this->assertSame(ShadowFeatureBuilder::SCHEMA_V1, $result['prediction']->feature_schema_version);
        Http::assertSent(fn ($request) => $request['feature_schema_version'] === ShadowFeatureBuilder::SCHEMA_V1
            && array_keys($request['features']) === ShadowFeatureBuilder::FEATURES_V1);
    }

    public function test_champion_with_unknown_feature_schema_is_refused(): void
    {
        $this->champion(schema: 'qt-shadow-features-v0');
        $this->assertSame('FEATURE_SCHEMA_MISMATCH', app(ShadowPredictionService::class)->predictLatest('BTCUSDT', 60)['status']);
        Http::assertNothingSent();
    }

    public function test_stale_data_yields_wait_data_stale_and_no_new_prediction(): void
    {
        $this->champion();
        $this->fakePython(['predicted_return' => 0.02]);
        app(ShadowPredictionService::class)->predictLatest('BTCUSDT', 60);
        $this->travel(30)->minutes();

        $this->assertSame('DATA_STALE', app(ShadowPredictionService::class)->predictLatest('BTCUSDT', 60)['status']);
        $payload = app(ShadowPredictionService::class)->payload('BTCUSDT', 3600, app(ShadowEvaluationService::class));
        $this->assertSame('DATA_STALE', $payload['status']);
        $this->assertSame('WAIT', $payload['prediction']['decision']);
        $this->assertSame('DATA_STALE', $payload['prediction']['decision_reason']);
    }

    public function test_each_duration_reads_its_own_horizon_model_only(): void
    {
        $this->champion(60);
        $this->fakePython(['predicted_return' => 0.02]);
        app(ShadowPredictionService::class)->predictLatest('BTCUSDT', 60);
        $service = app(ShadowPredictionService::class);
        $evaluation = app(ShadowEvaluationService::class);

        $this->assertSame(60, $service->payload('BTCUSDT', 3600, $evaluation)['horizon_minutes']);
        $this->assertSame('READY', $service->payload('BTCUSDT', 3600, $evaluation)['status']);
        // 2h / 4h / 1d each map to their OWN horizon — none borrows the h60 model.
        foreach ([7200 => 120, 14400 => 240, 86400 => 1440] as $seconds => $horizon) {
            $payload = $service->payload('BTCUSDT', $seconds, $evaluation);
            $this->assertSame($horizon, $payload['horizon_minutes']);
            $this->assertSame('NO_CHAMPION', $payload['status']);
            $this->assertArrayNotHasKey('prediction', $payload);
        }
        // 15m is past the default 10m cut-off: it is a horizon of its own
        // (not configured in this test), never the cost message.
        $this->assertSame('UNSUPPORTED_DURATION', $service->payload('BTCUSDT', 900, $evaluation)['status']);
        // 10m and shorter: explicit cost message, no model at all.
        foreach ([600, 300, 60] as $seconds) {
            $payload = $service->payload('BTCUSDT', $seconds, $evaluation);
            $this->assertSame('COST_EXCEEDS_MOVE', $payload['status']);
            $this->assertSame(ShadowPredictionService::COST_EXCEEDS_MOVE_MESSAGE, $payload['message']);
            $this->assertNull($payload['horizon_minutes']);
        }
        $this->assertSame('UNSUPPORTED_DURATION', $service->payload('BTCUSDT', 1800, $evaluation)['status']);
    }

    public function test_fifteen_minute_horizon_produces_no_predictions(): void
    {
        $this->champion(15); // historical h15 champion stays in the registry...
        $this->fakePython(['predicted_return' => 0.02]);

        $this->artisan('ml:shadow:predict', ['--symbol' => ['BTCUSDT']])->assertSuccessful();

        // ...but 15 is not a configured horizon, so nothing is predicted for it.
        $this->assertSame(0, MlShadowPrediction::query()->where('horizon_minutes', 15)->count());
        Http::assertNotSent(fn ($request) => ($request['horizon_minutes'] ?? null) === 15);
    }

    public function test_scoring_uses_the_exact_target_close_and_metrics_need_minimum_samples(): void
    {
        config(['ml.evaluation.min_samples' => 2]);
        $this->champion();
        $this->fakePython(['predicted_return' => 0.01]); // BUY
        $prediction = app(ShadowPredictionService::class)->predictLatest('BTCUSDT', 60)['prediction'];
        $evaluation = app(ShadowEvaluationService::class);

        $this->assertSame(['scored' => 0, 'unscorable' => 0], $evaluation->score());
        $this->assertSame('insufficient_data', $evaluation->metrics('BTCUSDT', 60)['forecast']['status']);

        // Target candle closes exactly at T+60m.
        $this->travel(65)->minutes();
        $targetOpen = (int) $prediction->target_time_ms - self::FIVE_MIN;
        $this->seedCandles('BTCUSDT', $targetOpen, 1, fn () => (float) $prediction->current_price * 1.02);
        $this->assertSame(1, $evaluation->score()['scored']);

        $prediction->refresh();
        $this->assertTrue($prediction->direction_hit);
        $this->assertEqualsWithDelta(2.0 - $prediction->cost_snapshot['cost_pct'], $prediction->decision_net_pct, 1e-6);
        $metrics = $evaluation->metrics('BTCUSDT', 60);
        $this->assertSame(1, $metrics['forecast']['samples']);
        $this->assertSame('insufficient_data', $metrics['forecast']['status']);
        $this->assertNull($metrics['forecast']['direction_hit_rate_pct']); // never a % under the minimum
    }

    public function test_manual_quick_trades_never_feed_shadow_evaluation(): void
    {
        $workspace = Workspace::factory()->create();
        QuickTrade::create(['workspace_id' => $workspace->id, 'symbol' => 'BTCUSDT', 'side' => 'buy', 'stake_amount' => 10,
            'asset_class' => 'CRYPTO', 'mode' => 'live', 'source' => 'manual_quick_trade', 'timeframe' => '5m', 'expiry_seconds' => 3600,
            'payout_ratio' => 0, 'entry_price' => 100, 'exit_price' => 110, 'profit_loss' => 10, 'status' => 'won',
            'entry_at' => now(), 'expires_at' => now(), 'settled_at' => now(), 'fee_source' => 'real']);

        app(ShadowEvaluationService::class)->score();
        $metrics = app(ShadowEvaluationService::class)->metrics('BTCUSDT', 60);
        $this->assertSame(0, $metrics['forecast']['samples']);
        $this->assertSame(0, $metrics['decision']['samples']);
    }

    public function test_registry_champion_lookup_is_per_symbol_and_horizon(): void
    {
        $this->champion(60);
        $this->assertNotNull(app(MlModelRegistry::class)->champion('BTCUSDT', 60));
        $this->assertNull(app(MlModelRegistry::class)->champion('BTCUSDT', 15));
        $this->assertNull(app(MlModelRegistry::class)->champion('ETHUSDT', 60));
    }
}
