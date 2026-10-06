<?php

namespace Tests\Feature\Ml;

use App\Models\MlChampion;
use App\Models\MlModel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Ml\Support\MlTrainingTestSupport;
use Tests\TestCase;

final class MlOffloadTest extends TestCase
{
    use MlTrainingTestSupport;

    private string $work;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMlTraining();
        Queue::fake();
        config(['ml.symbols' => ['BTCUSDT', 'ETHUSDT']]);
        $this->work = sys_get_temp_dir().'/mloffload-'.uniqid();
        File::ensureDirectoryExists($this->work);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->work);
        $this->cleanMlTraining();
        parent::tearDown();
    }

    public function test_a_trained_champion_survives_pack_and_install_on_a_clean_server(): void
    {
        $this->succeedWith();
        $trained = $this->trainer->train('BTCUSDT', 60);
        $this->assertSame('CHAMPION', $trained->status);

        $this->assertSame(0, Artisan::call('ml:offload:pack', ['dir' => $this->work.'/bundle']));
        $this->assertFileExists($this->work.'/bundle/bundle.json');

        // A clean "server": no rows, no files.
        DB::table('ml_champions')->delete();
        DB::table('ml_models')->delete();
        File::deleteDirectory(storage_path('framework/testing/ml-shadow'));

        $this->assertSame(0, Artisan::call('ml:offload:install', ['dir' => $this->work.'/bundle']));
        $this->assertStringContainsString('will become champion', Artisan::output());
        $this->assertSame(0, MlModel::query()->count());                       // dry run installs nothing

        $this->assertSame(0, Artisan::call('ml:offload:install', ['dir' => $this->work.'/bundle', '--apply' => true]));

        $model = MlModel::query()->findOrFail($trained->id);
        $this->assertSame('CHAMPION', $model->status);
        $this->assertSame($trained->model_version, $model->model_version);
        $this->assertSame($trained->id, MlChampion::query()->where('symbol', 'BTCUSDT')->where('horizon_minutes', 60)->value('model_id'));
        $this->assertDirectoryExists((string) $model->artifact_dir);
        $manifest = config('ml.training.champion_dir').'/BTCUSDT-h60.json';
        $this->assertFileExists($manifest);
        $this->assertSame($trained->model_version, json_decode((string) file_get_contents($manifest), true)['model_version']);

        // Installing twice is a no-op.
        Artisan::call('ml:offload:install', ['dir' => $this->work.'/bundle', '--apply' => true]);
        $this->assertSame(1, MlModel::query()->count());
    }

    public function test_a_damaged_artifact_is_refused_and_an_existing_champion_is_never_replaced(): void
    {
        $this->succeedWith();
        $trained = $this->trainer->train('BTCUSDT', 60);
        Artisan::call('ml:offload:pack', ['dir' => $this->work.'/bundle']);
        file_put_contents($this->work.'/bundle/models/'.$trained->id.'/model.txt', 'tampered');
        DB::table('ml_champions')->delete();
        DB::table('ml_models')->delete();

        $this->assertSame(1, Artisan::call('ml:offload:install', ['dir' => $this->work.'/bundle', '--apply' => true]));
        $this->assertStringContainsString('checksum mismatch', Artisan::output());
        $this->assertSame(0, MlModel::query()->count());

        // The server already has its own champion for the pair: the bundle model stays a CANDIDATE.
        File::deleteDirectory($this->work.'/bundle');
        $this->succeedWith();
        $offloaded = $this->trainer->train('BTCUSDT', 60);
        $this->assertSame('CHAMPION', $offloaded->status);
        Artisan::call('ml:offload:pack', ['dir' => $this->work.'/bundle']);
        DB::table('ml_champions')->delete();
        DB::table('ml_models')->delete();
        $this->succeedWith();
        $incumbent = $this->trainer->train('BTCUSDT', 60);
        $this->assertSame('CHAMPION', $incumbent->status);

        Artisan::call('ml:offload:install', ['dir' => $this->work.'/bundle', '--apply' => true]);

        $this->assertSame($incumbent->id, MlChampion::query()->where('symbol', 'BTCUSDT')->value('model_id'));
        $this->assertSame('CANDIDATE', MlModel::query()->findOrFail($offloaded->id)->status);
        $this->assertSame('CHAMPION', $incumbent->fresh()->status);
    }

    public function test_import_loads_a_stream_idempotently_and_refuses_a_cut_one_or_production(): void
    {
        $lines = [
            ['k' => 'r', 's' => 'ETHUSDT', 't' => 1000, 'r' => '0.0001', 'p' => '2000'],
            ['k' => 'f', 's' => 'ETHUSDT', 'tf' => '5m', 'v' => 'v3', 'a' => 300000, 'c' => '2000', 'x' => '{"a":1}'],
            ['k' => 'l', 's' => 'ETHUSDT', 'tf' => '5m', 'h' => 60, 'a' => 300000, 't' => 3900000, 'c' => '2000', 'tc' => '2010', 'r' => '0.005', 'v' => 'v3', 'm' => '2026-01-01 00:00:00'],
            ['k' => 'end', 's' => 'ETHUSDT', 'funding' => 1, 'features' => 1, 'labels' => 1],
        ];
        $good = $this->work.'/eth.ndjson.gz';
        file_put_contents($good, gzencode(implode("\n", array_map('json_encode', $lines))."\n"));

        $this->assertSame(1, Artisan::call('ml:offload:import', ['file' => $good]));                       // no --yes-local
        $this->assertSame(0, DB::table('ml_labels')->count());

        $this->assertSame(0, Artisan::call('ml:offload:import', ['file' => $good, '--yes-local' => true]));
        $this->assertSame(0, Artisan::call('ml:offload:import', ['file' => $good, '--yes-local' => true]));  // repeat: no duplicates
        $this->assertSame(1, DB::table('ml_labels')->where('status', 'MATURE')->count());
        $this->assertSame(1, DB::table('ml_feature_rows')->count());
        $this->assertSame(1, DB::table('ml_funding_rates')->count());

        $cut = $this->work.'/cut.ndjson';
        file_put_contents($cut, json_encode($lines[0])."\n");
        $this->assertSame(1, Artisan::call('ml:offload:import', ['file' => $cut, '--yes-local' => true]));
        $this->assertStringContainsString('no end marker', Artisan::output());
    }

    public function test_settings_print_the_cost_and_gate_values(): void
    {
        $this->assertSame(0, Artisan::call('ml:offload:settings'));
        $output = Artisan::output();
        $this->assertStringContainsString('ROBOT_TRADE_LIVE_SLIPPAGE_BUFFER_PCT=', $output);
        $this->assertStringContainsString('AUTOFINX_ML_MIN_T_STAT=', $output);
        $this->assertStringNotContainsString('PASSWORD', $output);
    }
}
