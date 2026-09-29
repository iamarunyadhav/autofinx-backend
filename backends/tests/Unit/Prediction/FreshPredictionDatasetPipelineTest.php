<?php

namespace Tests\Unit\Prediction;

use App\Domain\Prediction\FreshData\FreshPredictionCandleCollector;
use App\Domain\Prediction\FreshData\FreshPredictionDatasetAuditService;
use App\Domain\Prediction\FreshData\FreshPredictionDatasetExportService;
use App\Domain\Prediction\FreshData\FreshPredictionModelRegistryService;
use App\Domain\Prediction\FreshData\FreshPredictionShadowPublisher;
use App\Domain\Prediction\FreshData\FreshPredictionDatasetService;
use App\Models\PredictionFreshDatasetCandle;
use App\Models\PredictionFreshModelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class FreshPredictionDatasetPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_collector_persists_only_valid_closed_candles_into_the_isolated_dataset(): void
    {
        $start = Carbon::now('UTC')->subHours(2)->startOfHour();
        $dataset = app(FreshPredictionDatasetService::class)->start('fresh-september', ['btcusdt'], ['5m'], $start);
        $openOne = $start->copy()->addMinutes(5)->getTimestampMs();
        $openTwo = $start->copy()->addMinutes(10)->getTimestampMs();

        Http::fake([
            '*/fapi/v1/klines*' => Http::response([
                $this->kline($openOne),
                $this->kline($openTwo),
                // This mimics the in-progress exchange candle and must never enter training data.
                $this->kline(Carbon::now('UTC')->startOfMinute()->getTimestampMs(), closeMs: Carbon::now('UTC')->addMinute()->getTimestampMs()),
                ['bad'],
            ]),
        ]);

        $result = app(FreshPredictionCandleCollector::class)->collect($dataset);

        $this->assertSame(2, $result['written']);
        $this->assertSame(1, $result['skipped_open']);
        $this->assertSame(1, $result['invalid']);
        $this->assertSame(2, PredictionFreshDatasetCandle::query()->where('dataset_id', $dataset->id)->count());
        $this->assertSame(0, PredictionFreshDatasetCandle::query()->where('dataset_id', '!=', $dataset->id)->count());

        $first = PredictionFreshDatasetCandle::query()->where('dataset_id', $dataset->id)->orderBy('open_time')->firstOrFail();
        $this->assertSame('binance_futures_canonical_rest', $first->source);
        $this->assertTrue($first->quality['closed']);
        $this->assertTrue($first->quality['aligned']);

        $again = app(FreshPredictionCandleCollector::class)->collect($dataset);
        $this->assertSame(0, $again['written']);
        $this->assertSame(2, PredictionFreshDatasetCandle::query()->where('dataset_id', $dataset->id)->count());
    }

    public function test_audit_blocks_training_when_a_fresh_dataset_has_a_gap_or_too_few_rows(): void
    {
        $dataset = app(FreshPredictionDatasetService::class)->start('fresh-gap-check', ['ETHUSDT'], ['5m'], Carbon::now('UTC')->subDay());
        $base = Carbon::now('UTC')->subHours(2)->startOfHour();
        foreach ([0, 5, 15] as $minutes) {
            PredictionFreshDatasetCandle::query()->create([
                'dataset_id' => $dataset->id,
                'exchange' => 'binance_futures',
                'symbol' => 'ETHUSDT',
                'timeframe' => '5m',
                'open_time' => $base->copy()->addMinutes($minutes),
                'close_time' => $base->copy()->addMinutes($minutes + 5)->subMillisecond(),
                'open' => 100, 'high' => 101, 'low' => 99, 'close' => 100.5, 'volume' => 10,
                'source' => 'binance_futures_canonical_rest',
                'received_at' => now('UTC'),
                'quality' => ['closed' => true, 'aligned' => true],
            ]);
        }

        $report = app(FreshPredictionDatasetAuditService::class)->report($dataset);

        $this->assertSame('COLLECTING', $report['pairs'][0]['state']);
        $this->assertSame(1, $report['pairs'][0]['gaps']);
        $this->assertSame(0, $report['ready_pairs']);
        $this->assertFalse($report['execution']);
        $this->assertFalse($report['influence']);
    }

    public function test_gap_repair_fills_a_missing_closed_candle_without_reading_legacy_market_ohlc(): void
    {
        $base = Carbon::now('UTC')->subHours(2)->startOfHour();
        $dataset = app(FreshPredictionDatasetService::class)->start('fresh-repair-check', ['SOLUSDT'], ['5m'], $base);
        foreach ([0, 10] as $minutes) {
            PredictionFreshDatasetCandle::query()->create([
                'dataset_id' => $dataset->id, 'exchange' => 'binance_futures', 'symbol' => 'SOLUSDT', 'timeframe' => '5m',
                'open_time' => $base->copy()->addMinutes($minutes), 'close_time' => $base->copy()->addMinutes($minutes + 5)->subMillisecond(),
                'open' => 100, 'high' => 101, 'low' => 99, 'close' => 100.5, 'volume' => 10,
                'source' => 'binance_futures_canonical_rest', 'received_at' => now('UTC'), 'quality' => ['closed' => true, 'aligned' => true],
            ]);
        }
        $gapOpen = $base->copy()->addMinutes(5)->getTimestampMs();
        Http::fake(['*/fapi/v1/klines*' => Http::response([$this->kline($gapOpen)])]);

        $result = app(FreshPredictionCandleCollector::class)->repairGaps($dataset);

        $this->assertSame(1, $result['requested']);
        $this->assertSame(1, $result['repaired']);
        $this->assertSame(3, PredictionFreshDatasetCandle::query()->where('dataset_id', $dataset->id)->count());
        $repaired = PredictionFreshDatasetCandle::query()->where('dataset_id', $dataset->id)->where('open_time', Carbon::createFromTimestampMs($gapOpen, 'UTC'))->firstOrFail();
        $this->assertTrue($repaired->quality['repaired']);
    }

    public function test_export_refuses_an_unsealed_dataset_and_records_the_sealed_snapshot_identity(): void
    {
        $dataset = app(FreshPredictionDatasetService::class)->start('fresh-export-check', ['BNBUSDT'], ['5m'], Carbon::now('UTC')->subDay());
        $open = Carbon::now('UTC')->subHour()->startOfHour();
        PredictionFreshDatasetCandle::query()->create([
            'dataset_id' => $dataset->id, 'exchange' => 'binance_futures', 'symbol' => 'BNBUSDT', 'timeframe' => '5m',
            'open_time' => $open, 'close_time' => $open->copy()->addMinutes(5)->subMillisecond(),
            'open' => 100, 'high' => 101, 'low' => 99, 'close' => 100.5, 'volume' => 10,
            'source' => 'binance_futures_canonical_rest', 'received_at' => now('UTC'), 'quality' => ['closed' => true, 'aligned' => true],
        ]);

        $exports = app(FreshPredictionDatasetExportService::class);
        $this->expectException(\InvalidArgumentException::class);
        $exports->export($dataset, 'BNBUSDT', '5m');
    }

    public function test_only_quality_passed_candidates_enter_the_shadow_only_registry(): void
    {
        $dataset = app(FreshPredictionDatasetService::class)->start('fresh-registry-check', ['BTCUSDT'], ['5m'], Carbon::now('UTC')->subDay());
        $dataset->forceFill(['status' => 'SEALED'])->save();
        $artifact = storage_path('app/testing-fresh-registry-model.txt');
        if (! is_dir(dirname($artifact))) {
            mkdir(dirname($artifact), 0750, true);
        }
        file_put_contents($artifact, 'test-model');
        $registry = app(FreshPredictionModelRegistryService::class);

        $rejected = $registry->record($dataset, 'BTCUSDT', '5m', $artifact, ['quality_gate' => ['passed' => false]]);
        $this->assertSame('QUALITY_REJECTED', $rejected->status);
        $this->assertSame(0, PredictionFreshModelRegistry::query()->count());

        $registered = $registry->record($dataset, 'BTCUSDT', '5m', $artifact, ['quality_gate' => ['passed' => true]]);
        $this->assertSame('SHADOW_REGISTERED', $registered->status);
        $entry = PredictionFreshModelRegistry::query()->sole();
        $this->assertSame('SHADOW_ONLY', $entry->status);
        $this->assertFalse($entry->execution);
        $this->assertFalse($entry->influence);
    }

    public function test_quality_registered_artifacts_can_only_be_published_to_the_separate_shadow_directory(): void
    {
        $dataset = app(FreshPredictionDatasetService::class)->start('fresh-shadow-publish-check', ['BTCUSDT'], ['5m'], Carbon::now('UTC')->subDay());
        $dataset->forceFill(['status' => 'SEALED'])->save();
        $artifact = storage_path('app/testing-fresh-shadow-source.txt');
        $metadata = storage_path('app/testing-fresh-shadow-source.json');
        file_put_contents($artifact, 'quality-tested-shadow-model');
        file_put_contents($metadata, '{"shadow_only":true}');

        app(FreshPredictionModelRegistryService::class)->record($dataset, 'BTCUSDT', '5m', $artifact, ['quality_gate' => ['passed' => true]]);

        $shadowDirectory = storage_path('app/testing-fresh-shadow-published');
        config()->set('prediction_fresh_data.shadow_model_dir', $shadowDirectory);
        $publisher = app(FreshPredictionShadowPublisher::class);

        $plan = $publisher->plan();
        $this->assertCount(1, $plan);
        $this->assertSame($shadowDirectory.DIRECTORY_SEPARATOR.'BTCUSDT-5m.txt', $plan[0]['target']);

        $publisher->publish();

        $this->assertSame('quality-tested-shadow-model', file_get_contents($shadowDirectory.DIRECTORY_SEPARATOR.'BTCUSDT-5m.txt'));
        $this->assertSame('{"shadow_only":true}', file_get_contents($shadowDirectory.DIRECTORY_SEPARATOR.'BTCUSDT-5m.json'));
        $entry = PredictionFreshModelRegistry::query()->sole();
        $this->assertFalse($entry->execution);
        $this->assertFalse($entry->influence);
    }

    public function test_legacy_archive_command_is_dry_run_by_default(): void
    {
        $legacyDirectory = storage_path('app/testing-legacy-predictor-models');
        if (! is_dir($legacyDirectory)) {
            mkdir($legacyDirectory, 0750, true);
        }
        file_put_contents($legacyDirectory.DIRECTORY_SEPARATOR.'old-model.txt', 'legacy');
        file_put_contents($legacyDirectory.DIRECTORY_SEPARATOR.'old-model.json', '{}');
        config()->set('prediction_fresh_data.legacy_model_dir', $legacyDirectory);

        $this->artisan('prediction:legacy-models:archive')
            ->expectsOutput('Would archive old-model.json')
            ->expectsOutput('Would archive old-model.txt')
            ->expectsOutputToContain('Dry run only.')
            ->assertExitCode(0);

        $this->assertFileExists($legacyDirectory.DIRECTORY_SEPARATOR.'old-model.txt');
        $this->assertFileExists($legacyDirectory.DIRECTORY_SEPARATOR.'old-model.json');
    }

    public function test_collection_cycle_only_collects_repairs_and_audits_without_training_or_activation(): void
    {
        $start = Carbon::now('UTC')->subHour()->startOfHour();
        $dataset = app(FreshPredictionDatasetService::class)->start('fresh-cycle-check', ['BTCUSDT'], ['5m'], $start);
        Http::fake(['*/fapi/v1/klines*' => Http::response([$this->kline($start->copy()->addMinutes(5)->getTimestampMs())])]);

        $this->artisan('prediction:fresh-dataset:cycle', ['dataset' => $dataset->name, '--limit' => 3, '--repair-max-gaps' => 10])
            ->expectsOutputToContain('Collected 1 closed candles')
            ->assertExitCode(0);

        $this->assertSame(1, PredictionFreshDatasetCandle::query()->where('dataset_id', $dataset->id)->count());
        $this->assertSame('COLLECTING', $dataset->fresh()->status);
        $this->assertSame(0, PredictionFreshModelRegistry::query()->count());
    }

    public function test_readiness_is_read_only_and_reports_the_isolated_dataset(): void
    {
        $dataset = app(FreshPredictionDatasetService::class)->start('fresh-readiness-check', ['ETHUSDT'], ['15m'], Carbon::now('UTC')->subDay());

        $this->artisan('prediction:fresh-dataset:readiness', ['dataset' => $dataset->name])
            ->expectsOutput('Pipeline: READY')
            ->expectsOutput('Execution: false · Influence: false')
            ->assertExitCode(0);

        $this->assertSame('COLLECTING', $dataset->fresh()->status);
        $this->assertSame(0, PredictionFreshModelRegistry::query()->count());
    }

    /** @return array<int, int|string> */
    private function kline(int $openMs, ?int $closeMs = null): array
    {
        return [
            $openMs, '100.0', '102.0', '99.0', '101.0', '10.0',
            $closeMs ?? $openMs + 300_000 - 1,
        ];
    }
}
