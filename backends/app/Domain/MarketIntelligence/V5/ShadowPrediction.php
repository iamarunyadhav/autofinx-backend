<?php

namespace App\Domain\MarketIntelligence\V5;

use InvalidArgumentException;

/** Research-only prediction record. It can never authorize execution. */
final readonly class ShadowPrediction
{
    public function __construct(
        public string $symbol,
        public string $horizon,
        public int $horizonMinutes,
        public string $forecastAt,
        public string $outcomeAvailableAt,
        public string $direction,
        public ?float $expectedMove,
        public ?array $probabilities,
        public bool $probabilityCalibrated,
        public array $evidence,
        public string $modelVersion,
        public string $dataStatus = 'valid',
        public string $modelStatus = 'available',
    ) {
        if (! preg_match('/^[A-Z0-9]+USDT$/', $symbol)) {
            throw new InvalidArgumentException('Only Binance USDT-M symbols are accepted.');
        }
        if (! in_array($horizon, ['15m', '1h', '4h', '24h'], true) || $horizonMinutes <= 0) {
            throw new InvalidArgumentException('Unsupported V5 forecast horizon.');
        }
        if (! in_array($direction, ['UP', 'DOWN', 'SIDEWAYS'], true)) {
            throw new InvalidArgumentException('Direction must be UP, DOWN, or SIDEWAYS.');
        }
        if ($probabilityCalibrated && $probabilities === null) {
            throw new InvalidArgumentException('Calibrated predictions require probabilities.');
        }
        if ($probabilities !== null) {
            $sum = 0.0;
            foreach (['up', 'down', 'sideways'] as $key) {
                $value = $probabilities[$key] ?? null;
                if ($value !== null && ($value < 0 || $value > 1)) {
                    throw new InvalidArgumentException('Probabilities must be within [0,1].');
                }
                $sum += $value ?? 0.0;
            }
            if ($sum > 1.0000001) {
                throw new InvalidArgumentException('Probabilities must not sum above 1.');
            }
        }
    }

    public function toArray(): array
    {
        return [
            'symbol' => $this->symbol, 'horizon' => $this->horizon,
            'horizon_minutes' => $this->horizonMinutes, 'forecast_at' => $this->forecastAt,
            'outcome_available_at' => $this->outcomeAvailableAt, 'direction' => $this->direction,
            'expected_move' => $this->expectedMove, 'probabilities' => $this->probabilities,
            'probability_calibrated' => $this->probabilityCalibrated, 'evidence' => $this->evidence,
            'model_version' => $this->modelVersion, 'data_status' => $this->dataStatus,
            'model_status' => $this->modelStatus, 'execution_authorized' => false,
        ];
    }
}
