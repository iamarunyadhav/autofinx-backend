<?php

namespace App\Domain\MarketOpportunityBrain\Forecast;

use App\Domain\MarketOpportunityBrain\Contracts\ForecastProvider;
use App\Domain\MarketOpportunityBrain\Data\Candle;
use App\Domain\MarketOpportunityBrain\Data\ForecastRecord;
use App\Domain\MarketOpportunityBrain\Enums\ForecastStatus;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Bridges the PHP `ForecastProvider` contract to a real Python model (Chronos-2, Granite TTM-R3)
 * via a FIXED CLI subprocess -- the same pattern `StrategyLabRunner.php` already uses for
 * `v4.lab_runner`: a fixed argument list built entirely from already-validated/typed values, no
 * shell string, no arbitrary code execution of any downloaded/market/news text
 * (MASTER_SPEC.md Layer K). Disabled unless `market_opportunity_brain.forecast_enabled` is true
 * AND a python binary is configured for the requested model; otherwise returns a well-formed
 * `MODEL_UNAVAILABLE` record rather than throwing into the caller (Layer F: "the rest of the app
 * must function").
 */
final class ForecastGateway implements ForecastProvider
{
    public function __construct(private readonly string $modelName)
    {
    }

    public function predict(
        string $workspaceId,
        string $symbol,
        string $timeframe,
        int $horizonBars,
        array $closedCandles,
        \DateTimeImmutable $asOfUtc,
    ): ForecastRecord {
        $asOfIso = $asOfUtc->format('Y-m-d\TH:i:s\Z');
        $common = [
            'forecastId' => sprintf('%s-%s-%d', $this->modelName, $symbol, $asOfUtc->getTimestamp()),
            'workspaceId' => $workspaceId, 'exchange' => 'BINANCE', 'market' => 'USDM_PERPETUAL',
            'symbol' => $symbol, 'timeframe' => $timeframe, 'horizonBars' => $horizonBars,
            'asOfUtc' => $asOfIso, 'knownByUtc' => $asOfIso, 'labelAvailableAtUtc' => null,
            'modelName' => $this->modelName, 'modelRevision' => 'unknown', 'featureSchemaVersion' => 'mob-features-v1',
            'datasetHashSha256' => null,
            'predictionQuantiles' => ['p10' => null, 'p50' => null, 'p90' => null],
            'probabilities' => ['up' => null, 'neutral' => null, 'down' => null],
            'probabilityCalibrated' => false,
        ];

        if (! (bool) config('market_opportunity_brain.forecast_enabled', false)) {
            return $this->unavailable($common, 'FORECAST_DISABLED_BY_CONFIG');
        }

        $pythonBin = $this->pythonBinFor($this->modelName);
        if ($pythonBin === null || $pythonBin === '' || ! is_string($pythonBin)) {
            return $this->unavailable($common, 'PYTHON_BIN_NOT_CONFIGURED');
        }

        if ($closedCandles === []) {
            return $this->unavailable($common, 'NO_CLOSED_CANDLES_AVAILABLE', ForecastStatus::UNAVAILABLE);
        }

        $closes = array_map(static fn (Candle $c) => $c->close, $closedCandles);
        $lastCloseTime = $closedCandles[count($closedCandles) - 1]->closeTimeUtc;

        $command = [
            $pythonBin, '-m', 'market_opportunity_brain.cli', 'predict',
            '--model', $this->modelName,
            '--symbol', $symbol,
            '--timeframe', $timeframe,
            '--horizon-bars', (string) $horizonBars,
            '--as-of-utc', $asOfIso,
            '--workspace-id', $workspaceId,
            '--closes-json', json_encode(array_values($closes), JSON_THROW_ON_ERROR),
            '--last-close-time-utc', $lastCloseTime,
        ];

        $timeout = max(5, (int) config('market_opportunity_brain.forecast_timeout_seconds', 120));
        $envOverrides = (array) config('market_opportunity_brain.python_env_overrides', []);

        try {
            $process = new Process($command, (string) config('market_opportunity_brain.engine_dir'), $envOverrides !== [] ? $envOverrides : null, null, $timeout);
            $process->run();
        } catch (ProcessTimedOutException) {
            return $this->unavailable($common, 'INFERENCE_TIMEOUT');
        }

        if (! $process->isSuccessful()) {
            Log::warning('market_opportunity_brain.forecast_gateway.process_failed', [
                'model' => $this->modelName,
                'exit_code' => $process->getExitCode(),
                'stderr' => mb_substr($process->getErrorOutput(), 0, 1000),
            ]);

            return $this->unavailable($common, 'PROCESS_FAILED');
        }

        try {
            $decoded = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded) || ! isset($decoded['schema']) || $decoded['schema'] !== ForecastRecord::SCHEMA) {
                return $this->unavailable($common, 'MALFORMED_CLI_OUTPUT');
            }

            return ForecastRecord::fromArray($decoded);
        } catch (\Throwable $e) {
            Log::warning('market_opportunity_brain.forecast_gateway.parse_failed', ['model' => $this->modelName, 'error' => $e->getMessage()]);

            return $this->unavailable($common, 'MALFORMED_CLI_OUTPUT');
        }
    }

    private function pythonBinFor(string $modelName): ?string
    {
        return match ($modelName) {
            'granite_ttm_r3' => config('market_opportunity_brain.granite_python_bin'),
            default => config('market_opportunity_brain.python_bin'),
        };
    }

    private function unavailable(array $common, string $reason, ForecastStatus $status = ForecastStatus::MODEL_UNAVAILABLE): ForecastRecord
    {
        return new ForecastRecord(
            forecastId: $common['forecastId'], workspaceId: $common['workspaceId'], exchange: $common['exchange'],
            market: $common['market'], symbol: $common['symbol'], timeframe: $common['timeframe'],
            horizonBars: $common['horizonBars'], asOfUtc: $common['asOfUtc'], knownByUtc: $common['knownByUtc'],
            labelAvailableAtUtc: $common['labelAvailableAtUtc'], modelName: $common['modelName'],
            modelRevision: $common['modelRevision'], featureSchemaVersion: $common['featureSchemaVersion'],
            datasetHashSha256: $common['datasetHashSha256'], predictionQuantiles: $common['predictionQuantiles'],
            probabilities: $common['probabilities'], probabilityCalibrated: $common['probabilityCalibrated'],
            status: $status, reason: $reason,
        );
    }
}
