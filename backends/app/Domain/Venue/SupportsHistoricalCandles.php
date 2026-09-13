<?php

namespace App\Domain\Venue;

/**
 * Optional capability interface for historical OHLC candle retrieval.
 * Deliberately NOT added to TradingVenueClientInterface itself, so
 * OandaClient/BinanceTradingVenueClient/MetalBrokerClient need zero
 * changes -- only PepperstoneBrokerAdapter implements this today.
 */
interface SupportsHistoricalCandles
{
    /**
     * @return array<int, array{ts: string, open: float, high: float, low: float, close: float, volume: float}>
     *                Ascending time order, most recent candle last.
     */
    public function fetchCandles(string $assetCode, string $timeframe, int $limit): array;
}
