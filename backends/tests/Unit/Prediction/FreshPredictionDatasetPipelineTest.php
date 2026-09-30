<?php

namespace Tests\Unit\Prediction;

use App\Domain\Prediction\FreshData\FreshPredictionCandleCollector;
use App\Domain\Prediction\FreshData\FreshPredictionDatasetAuditService;
use App\Domain\Prediction\FreshData\FreshPredictionDatasetExportService;
use App\Domain\Prediction\FreshData\FreshPredictionModelRegistryService;
use App\Domain\Prediction\FreshData\FreshPredictionShadowPublisher;
use App\Domain\Prediction\FreshData\FreshPredictionShadowLifecycleService;
use App\Domain\Prediction\FreshData\FreshPredictionDatasetService;
use App\Jobs\TrainFreshPredictionModelJob;
use App\Models\PredictionFreshDatasetCandle;
use App\Models\PredictionFreshModelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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

    public function test_collector_builds_45m_only_from_three_consecutive_closed_15m_candles(): void
    {
        $start = Carbon::createFromTimestamp((int) floor(Carbon::now('UTC')->subHours(3)->getTimestamp() / 2700) * 2700, 'UTC');
        $dataset = app(FreshPredictionDatasetService::class)->start('fresh-derived-45m', ['BTCUSDT'], ['45m'], $start);
        Http::fake([
            '*/fapi/v1/klines*' => Http::response([
                [$start->getTimestampMs(), '100', '102', '99', '101', '10', $start->copy()->addMinutes(15)->subMillisecond()->getTimestampMs()],
                [$start->copy()->addMinutes(15)->getTimestampMs(), '101', '104', '100', '103', '11', $start->copy()->addMinutes(30)->subMillisecond()->getTimestampMs()],
                [$start->copy()->addMinutes(30)->getTimestampMs(), '103', '105', '102', '104', '12', $start->copy()->addMinutes(45)->subMillisecond()->getTimestampMs()],
            ]),
        ]);

        $result = app(FreshPredictionCandleCollector::class)->collect($dataset, 3);

        $this->assertSame(1, $result['written']);
        $candle = PredictionFreshDatasetCandle::query()->where('dataset_id', $dataset->id)->sole();
        $this->assertSame('45m', $candle->timeframe);
        $this->assertSame('binance_futures_derived_15m_closed', $candle->source);
        $this->assertSame(100.0, (float) $candle->open);
        $this->assertSame(105.0, (float) $candle->high);
        $this->assertSame(99.0, (float) $candle->low);
        $this->assertSame(104.0, (float) $candle->close);
        $this->assertSame(33.0, (float) $candle->volume);
        $this->assertSame('15m', $candle->quality['source_interval']);
        $this->assertSame(3, $candle->quality['source_candle_count']);
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

    public function test_audit_keeps_utc_hour_boundaries_aligned_when_the_application_timezone_is_not_utc(): void
    {
        $originalTimezone = date_default_timezone_get();
        date_default_timezone_set('Asia/Colombo');
        try {
            $dataset = app(FreshPredictionDatasetService::class)->start('fresh-utc-boundary-check', ['BTCUSDT'], ['1h'], Carbon::now('UTC')->subDay());
            $open = Carbon::create(2026, 9, 29, 0, 0, 0, 'UTC');
            PredictionFreshDatasetCandle::query()->create([
                'dataset_id' => $dataset->id, 'exchange' => 'binance_futures', 'symbol' => 'BTCUSDT', 'timeframe' => '1h',
                'open_time' => $open, 'close_time' => $open->copy()->addHour()->subMillisecond(),
                'open' => 100, 'high' => 101, 'low' => 99, 'close' => 100.5, 'volume' => 10,
                'source' => 'binance_futures_canonical_rest', 'received_at' => now('UTC'), 'quality' => ['closed' => true, 'aligned' => true],
            ]);

            $report = app(FreshPredictionDatasetAuditService::class)->report($dataset);
            $this->assertSame(0, $report['pairs'][0]['misaligned']);
        } finally {
            date_default_timezone_set($originalTimezone);
        }
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
        $this->assertSame('QUALITY_PASSED', $registered->status);
        $this->assertSame(0, PredictionFreshModelRegistry::query()->count());

        app(FreshPredictionShadowLifecycleService::class)->activate($registered);
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

        $candidate = app(FreshPredictionModelRegistryService::class)->record($dataset, 'BTCUSDT', '5m', $artifact, ['quality_gate' => ['passed' => true]]);
        app(FreshPredictionShadowLifecycleService::class)->activate($candidate);

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

    public function test_shadow_lifecycle_can_roll_back_to_a_previous_integrity_checked_candidate(): void
    {
        $registry = app(FreshPredictionModelRegistryService::class);
        $lifecycle = app(FreshPredictionShadowLifecycleService::class);
        $publisher = app(FreshPredictionShadowPublisher::class);
        $shadowDirectory = storage_path('app/testing-fresh-shadow-rollback');
        config()->set('prediction_fresh_data.shadow_model_dir', $shadowDirectory);

        $firstDataset = app(FreshPredictionDatasetService::class)->start('fresh-shadow-rollback-one', ['BTCUSDT'], ['5m'], Carbon::now('UTC')->subDays(2));
        $firstDataset->forceFill(['status' => 'SEALED'])->save();
        $firstArtifact = storage_path('app/testing-fresh-shadow-rollback-one.txt');
        file_put_contents($firstArtifact, 'first-approved-model');
        file_put_contents(storage_path('app/testing-fresh-shadow-rollback-one.json'), '{"quality_gate":{"passed":true}}');
        $first = $registry->record($firstDataset, 'BTCUSDT', '5m', $firstArtifact, ['quality_gate' => ['passed' => true]]);
        $lifecycle->activate($first);
        $publisher->publishCandidate($first->id);

        $secondDataset = app(FreshPredictionDatasetService::class)->start('fresh-shadow-rollback-two', ['BTCUSDT'], ['5m'], Carbon::now('UTC')->subDay());
        $secondDataset->forceFill(['status' => 'SEALED'])->save();
        $secondArtifact = storage_path('app/testing-fresh-shadow-rollback-two.txt');
        file_put_contents($secondArtifact, 'second-approved-model');
        file_put_contents(storage_path('app/testing-fresh-shadow-rollback-two.json'), '{"quality_gate":{"passed":true}}');
        $second = $registry->record($secondDataset, 'BTCUSDT', '5m', $secondArtifact, ['quality_gate' => ['passed' => true]]);
        $lifecycle->activate($second);
        $publisher->publishCandidate($second->id);
        $this->assertSame('second-approved-model', file_get_contents($shadowDirectory.DIRECTORY_SEPARATOR.'BTCUSDT-5m.txt'));

        $lifecycle->activate($first->fresh());
        $publisher->publishCandidate($first->id);

        $this->assertSame('first-approved-model', file_get_contents($shadowDirectory.DIRECTORY_SEPARATOR.'BTCUSDT-5m.txt'));
        $this->assertSame('SHADOW_REGISTERED', $first->fresh()->status);
        $this->assertSame('SHADOW_RETIRED', $second->fresh()->status);
        $entry = PredictionFreshModelRegistry::query()->sole();
        $this->assertSame($first->id, $entry->candidate_id);
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

    public function test_automatic_dispatch_refuses_collecting_datasets_and_never_changes_execution(): void
    {
        Queue::fake();
        $dataset = app(FreshPredictionDatasetService::class)->start('fresh-automatic-dispatch-safety', ['BTCUSDT'], ['5m'], Carbon::now('UTC')->subDay());

        $this->artisan('prediction:fresh-model:dispatch-ready', ['--dataset' => $dataset->name])
            ->expectsOutput('Queued 0 sealed fresh-model evaluation job(s). Shadow-only; no publication or execution change.')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
        $this->assertSame('COLLECTING', $dataset->fresh()->status);
        $this->assertSame(0, PredictionFreshModelRegistry::query()->count());
    }

    public function test_training_job_identity_is_scoped_to_the_immutable_dataset_pair(): void
    {
        $job = new TrainFreshPredictionModelJob('dataset-1', 'btcusdt', '5M', 12);

        $this->assertSame('fresh-prediction-train:dataset-1:BTCUSDT:5m', $job->uniqueId());
        $this->assertSame('analytics', $job->queue);
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
