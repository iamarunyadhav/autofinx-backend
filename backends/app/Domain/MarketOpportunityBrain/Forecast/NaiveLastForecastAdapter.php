<?php

namespace App\Domain\MarketOpportunityBrain\Forecast;

use App\Domain\MarketOpportunityBrain\Contracts\ForecastProvider;
use App\Domain\MarketOpportunityBrain\Data\ForecastRecord;
use App\Domain\MarketOpportunityBrain\Enums\ForecastStatus;

/**
 * `naive_last` baseline (MASTER_SPEC.md Layer F §62: "always available"). Deterministic,
 * dependency-free, no model weights, no network. The point forecast for every quantile is the
 * last closed candle's close price, i.e. "no change" -- any model that cannot beat this is not
 * adding value. Deliberately returns null probabilities rather than inventing a direction from a
 * flat point estimate (Layer F §70: "If model returns only quantiles, do not invent direction
 * percentages from them").
 */
final class NaiveLastForecastAdapter implements ForecastProvider
{
    public const MODEL_NAME = 'naive_last';

    public const MODEL_REVISION = 'v1';

    public function predict(
        string $workspaceId,
        string $symbol,
        string $timeframe,
        int $horizonBars,
        array $closedCandles,
        \DateTimeImmutable $asOfUtc,
    ): ForecastRecord {
        $forecastId = sprintf('naive-last-%s-%d', $symbol, $asOfUtc->getTimestamp());
        $knownByUtc = $asOfUtc->format('Y-m-d\TH:i:s\Z');

        if ($closedCandles === []) {
            return new ForecastRecord(
                forecastId: $forecastId,
                workspaceId: $workspaceId,
                exchange: 'BINANCE',
                market: 'USDM_PERPETUAL',
                symbol: $symbol,
                timeframe: $timeframe,
                horizonBars: $horizonBars,
                asOfUtc: $knownByUtc,
                knownByUtc: $knownByUtc,
                labelAvailableAtUtc: null,
                modelName: self::MODEL_NAME,
                modelRevision: self::MODEL_REVISION,
                featureSchemaVersion: 'mob-features-v1',
                datasetHashSha256: null,
                predictionQuantiles: ['p10' => null, 'p50' => null, 'p90' => null],
                probabilities: ['up' => null, 'neutral' => null, 'down' => null],
                probabilityCalibrated: false,
                status: ForecastStatus::UNAVAILABLE,
                reason: 'NO_CLOSED_CANDLES_AVAILABLE',
            );
        }

        $last = $closedCandles[count($closedCandles) - 1];
        $lastClose = $last->close;
        $labelAvailableAtUtc = $this->estimateLabelTime($last->closeTimeUtc, $timeframe, $horizonBars);

        return new ForecastRecord(
            forecastId: $forecastId,
            workspaceId: $workspaceId,
            exchange: 'BINANCE',
            market: 'USDM_PERPETUAL',
            symbol: $symbol,
            timeframe: $timeframe,
            horizonBars: $horizonBars,
            asOfUtc: $knownByUtc,
            knownByUtc: $knownByUtc,
            labelAvailableAtUtc: $labelAvailableAtUtc,
            modelName: self::MODEL_NAME,
            modelRevision: self::MODEL_REVISION,
            featureSchemaVersion: 'mob-features-v1',
            datasetHashSha256: hash('sha256', $symbol.'|'.$last->closeTimeUtc.'|'.$lastClose),
            predictionQuantiles: ['p10' => $lastClose, 'p50' => $lastClose, 'p90' => $lastClose],
            probabilities: ['up' => null, 'neutral' => null, 'down' => null],
            probabilityCalibrated: false,
            status: ForecastStatus::OK,
            reason: null,
        );
    }

    private function estimateLabelTime(string $lastCloseUtc, string $timeframe, int $horizonBars): string
    {
        $stepSeconds = match ($timeframe) {
            '1m' => 60, '5m' => 300, '15m' => 900, '1h' => 3600, '4h' => 14400, '1d' => 86400,
            default => 900,
        };
        $closeTs = (new \DateTimeImmutable($lastCloseUtc))->getTimestamp();

        return gmdate('Y-m-d\TH:i:s\Z', $closeTs + ($stepSeconds * max(1, $horizonBars)));
    }
}
