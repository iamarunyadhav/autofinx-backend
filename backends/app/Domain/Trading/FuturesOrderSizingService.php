<?php

namespace App\Domain\Trading;

class FuturesOrderSizingService
{
    public const BINANCE_MIN_OPEN_NOTIONAL = 20.0;

    public function extractFilters(array $exchangeInfo, string $symbol): array
    {
        $symbolInfo = collect((array) ($exchangeInfo['symbols'] ?? []))
            ->first(fn ($row) => strtoupper((string) ($row['symbol'] ?? '')) === strtoupper($symbol));
        $filters = collect((array) ($symbolInfo['filters'] ?? []));
        $lot = (array) ($filters->first(fn ($row) => ($row['filterType'] ?? null) === 'MARKET_LOT_SIZE')
            ?: $filters->first(fn ($row) => ($row['filterType'] ?? null) === 'LOT_SIZE')
            ?: []);
        $price = (array) ($filters->first(fn ($row) => ($row['filterType'] ?? null) === 'PRICE_FILTER') ?? []);

        return [
            'stepSize' => (float) ($lot['stepSize'] ?? 0.001),
            'tickSize' => (float) ($price['tickSize'] ?? 0.01),
            'minNotional' => (float) (($filters->first(fn ($row) => ($row['filterType'] ?? null) === 'MIN_NOTIONAL')['notional'] ?? $filters->first(fn ($row) => ($row['filterType'] ?? null) === 'MIN_NOTIONAL')['minNotional'] ?? $filters->first(fn ($row) => ($row['filterType'] ?? null) === 'NOTIONAL')['minNotional'] ?? $filters->first(fn ($row) => ($row['filterType'] ?? null) === 'NOTIONAL')['notional'] ?? 0)),
        ];
    }

    public function evaluate(float $quantity, float $referencePrice, array $filters, bool $reduceOnly = false): array
    {
        $normalizedQty = $this->normalizeQuantity($quantity, (float) ($filters['stepSize'] ?? 0));
        $effectiveMinNotional = $reduceOnly
            ? 0.0
            : max(self::BINANCE_MIN_OPEN_NOTIONAL, (float) ($filters['minNotional'] ?? 0));

        return [
            'quantity_float' => $normalizedQty,
            'quantity' => $this->formatDecimal($normalizedQty),
            'notional' => round($normalizedQty * max($referencePrice, 0), 8),
            'stepSize' => (float) ($filters['stepSize'] ?? 0.001),
            'exchangeMinNotional' => (float) ($filters['minNotional'] ?? 0),
            'minOpenNotional' => $effectiveMinNotional,
        ];
    }

    public function normalizeQuantity(float $quantity, float $stepSize): float
    {
        $stepSize = $stepSize > 0 ? $stepSize : 0.000001;
        if ($quantity <= 0 || $quantity < $stepSize) {
            return 0.0;
        }

        $steps = floor($quantity / $stepSize);
        if ($steps <= 0) {
            return 0.0;
        }

        return round($steps * $stepSize, 8);
    }

    public function formatDecimal(float $value): string
    {
        $raw = number_format($value, 8, '.', '');
        $trimmed = rtrim(rtrim($raw, '0'), '.');

        return $trimmed === '' ? '0' : $trimmed;
    }
}
