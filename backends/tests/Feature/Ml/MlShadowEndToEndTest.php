<?php

namespace Tests\Feature\Ml;

use App\Models\MlCandle;
use App\Models\MlLabel;
use App\Models\MlModel;
use App\Models\MlShadowPrediction;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Tests\Unit\Ml\MlShadowTestHelpers;

/**
 * Opt-in, real-python end-to-end proof of the Phase 1 loop:
 * backfill → features/labels → LightGBM training → candidate decision →
 * champion publish → python service → stored shadow prediction → Quick
 * Trade API payload.
 *
 * Skipped unless AUTOFINX_ML_TEST_PYTHON points at an interpreter with the
 * external-predictive-engine requirements installed. Binance is faked with
 * a synthetic momentum series so the model has something real to learn.
 */
final class MlShadowEndToEndTest extends TestCase
{
    use MlShadowTestHelpers;

    private ?Process $service = null;

    protected function setUp(): void
    {
        parent::setUp();
        $python = (string) getenv('AUTOFINX_ML_TEST_PYTHON');
        if ($python === '' || ! is_file($python)) {
            $this->markTestSkipped('Set AUTOFINX_ML_TEST_PYTHON to a python with lightgbm to run the real end-to-end loop.');
        }
        $this->configureMlForTests();
        File::deleteDirectory(storage_path('framework/testing/ml-shadow'));
        config([
            'ml.training.python_bin' => $python,
            'ml.training.min_total_mature_labels' => 1000,
            'ml.promotion.min_holdout_rows' => 300,
            'ml.binance.page_limit' => 1000,
        ]);
    }

    protected function tearDown(): void
    {
        $this->service?->stop(1);
        File::deleteDirectory(storage_path('framework/testing/ml-shadow'));
        parent::tearDown();
    }

    public function test_backfill_to_labels_to_training_to_prediction_reaching_the_ui(): void
    {
        // Synthetic AR(1) momentum in 5m returns => future 60m return is
        // learnable from the causal return features.
        $count = 4000;
        $first = $this->alignedOpen($count);
        mt_srand(11);
        $price = 30000.0;
        $return = 0.0;
        $series = [];
        for ($i = 0; $i < $count; $i++) {
            $return = 0.92 * $return + (mt_rand() / mt_getrandmax() - 0.5) * 0.002;
            $open = $price;
            $price *= 1 + $return;
            $series[$first + $i * self::FIVE_MIN] = [$open, $price];
        }
        Http::fake(['*/fapi/v1/klines*' => function (Request $request) use ($series) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
            $rows = [];
            foreach ($series as $openMs => [$open, $close]) {
                if ($openMs >= (int) $q['startTime'] && $openMs <= (int) $q['endTime'] && count($rows) < (int) $q['limit']) {
                    $rows[] = $this->kline($openMs, $close, open: $open);
                }
            }

            return Http::response($rows);
        }]);

        // 1. Backfill (+ automatic feature/label build).
        $this->artisan('ml:candles:backfill', ['--symbol' => ['BTCUSDT'], '--from' => gmdate('c', intdiv($first, 1000))])->assertSuccessful();
        $this->assertSame($count, MlCandle::query()->where('symbol', 'BTCUSDT')->count());
        $this->assertGreaterThan(3000, MlLabel::query()->where('horizon_minutes', 60)->where('status', 'MATURE')->count());

        // 2. Real training: candidate → decision.
        $this->artisan('ml:train', ['symbol' => 'BTCUSDT', 'horizon' => 60])->assertSuccessful();
        $model = MlModel::query()->where('horizon_minutes', 60)->latest('created_at')->firstOrFail();
        $this->assertContains($model->status, ['CHAMPION', 'REJECTED']);
        $this->assertNotNull($model->decision_reason);
        $this->assertSame('CHAMPION', $model->status, 'synthetic momentum should be learnable: '.$model->decision_reason);

        // 3. Python service serves the published champion.
        $port = random_int(18100, 18900);
        $this->service = new Process([config('ml.training.python_bin'), '-m', 'external_predictive_engine'], base_path('external-predictive-engine'), [
            'AUTOFINX_PREDICTOR_PORT' => (string) $port,
            'AUTOFINX_PREDICTOR_SHADOW_DIR' => (string) config('ml.training.champion_dir'),
        ]);
        $this->service->start();
        config(['ml.predictor.url' => "http://127.0.0.1:{$port}", 'ml.predictor.timeout_seconds' => 10]);
        $ready = false;
        for ($i = 0; $i < 50 && ! $ready; $i++) {
            usleep(200_000);
            try {
                $ready = Http::timeout(1)->get("http://127.0.0.1:{$port}/health")->successful();
            } catch (\Throwable) {
            }
        }
        $this->assertTrue($ready, 'python service did not start: '.$this->service->getErrorOutput());

        // 4. Shadow prediction stored for the newest closed anchor.
        $this->artisan('ml:shadow:predict', ['--symbol' => ['BTCUSDT']])->assertSuccessful();
        $prediction = MlShadowPrediction::query()->where('symbol', 'BTCUSDT')->where('horizon_minutes', 60)->firstOrFail();
        $this->assertSame($model->model_version, $prediction->model_version);

        // 5. Reaches the Quick Trade UI endpoint for the matching duration only.
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        WorkspaceMember::create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => 'owner']);
        $this->actingAs($user)
            ->getJson('/api/v1/quick-trade/ml-shadow?'.http_build_query(['workspace_id' => $workspace->id, 'symbol' => 'BTCUSDT', 'duration_seconds' => 3600]))
            ->assertOk()
            ->assertJsonPath('status', 'READY')
            ->assertJsonPath('source', 'python_ml_shadow')
            ->assertJsonPath('prediction.model_version', $model->model_version)
            ->assertJsonPath('execution', false);
        $this->actingAs($user)
            ->getJson('/api/v1/quick-trade/ml-shadow?'.http_build_query(['workspace_id' => $workspace->id, 'symbol' => 'BTCUSDT', 'duration_seconds' => 900]))
            ->assertOk()->assertJsonPath('status', 'NO_CHAMPION');
    }
}
