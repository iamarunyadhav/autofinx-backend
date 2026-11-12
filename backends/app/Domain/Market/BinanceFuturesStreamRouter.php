<?php

namespace App\Domain\Market;

use InvalidArgumentException;

/**
 * Classifies a configured Binance USD-M Futures public-market-data stream
 * list into transport lanes.
 *
 * Root cause (2026-09-22 production incident, verified via raw
 * SimpleWebSocketClient diagnostics against real Binance frames): Binance's
 * futures ROOT combined-stream endpoint (wss://fstream.binance.com/stream)
 * only reliably delivers depthUpdate frames in production — kline frames do
 * not arrive on it. Splitting the same streams onto two dedicated combined
 * endpoints delivered both correctly:
 *   - wss://fstream.binance.com/public/stream?streams=...  (depth)
 *   - wss://fstream.binance.com/market/stream?streams=...  (kline)
 *
 * The original verified mapping is depth* -> public and kline_* -> market.
 * Aggregate trades use a separate root lane matching Binance's documented
 * `/ws/<symbol>@aggTrade` / root combined-stream contract, so additive trade
 * events never share the quote/candle coalescing lanes. Other stream families
 * remain rejected rather than guessed.
 */
class BinanceFuturesStreamRouter
{
    public const LANE_PUBLIC = 'public';
    public const LANE_MARKET = 'market';
    public const LANE_TRADE = 'trade';

    /** @return string[] */
    public static function lanes(): array
    {
        return [self::LANE_PUBLIC, self::LANE_MARKET, self::LANE_TRADE];
    }

    /**
     * @param  string[]  $streams
     * @return array{public: string[], market: string[]}
     *
     * @throws InvalidArgumentException when a stream's family is not one of
     *   the verified Phase-1 families (depth*, kline_*).
     */
    public function classify(array $streams): array
    {
        $result = [self::LANE_PUBLIC => [], self::LANE_MARKET => [], self::LANE_TRADE => []];

        foreach ($streams as $stream) {
            $trimmed = trim((string) $stream);
            if ($trimmed === '') {
                continue;
            }

            $lane = $this->laneFor($trimmed);
            if ($lane === null) {
                throw new InvalidArgumentException(sprintf(
                    'BinanceFuturesStreamRouter: unrecognized futures stream family "%s" cannot be routed. '
                    .'Supported routing is depth* -> public, kline_* -> market, aggTrade -> trade; '
                    .'verify the real delivery behavior of this stream family before adding it.',
                    $trimmed
                ));
            }

            $result[$lane][] = $trimmed;
        }

        foreach ($result as $lane => $laneStreams) {
            $result[$lane] = array_values(array_unique($laneStreams));
        }

        return $result;
    }

    /**
     * @param  string[]  $streams
     * @return string[]
     */
    public function streamsForLane(array $streams, string $lane): array
    {
        return $this->classify($streams)[$lane] ?? [];
    }

    /** Returns null when the stream's family is not a verified Phase-1 family. */
    public function laneFor(string $stream): ?string
    {
        $normalized = strtolower(trim($stream));
        if ($normalized === '') {
            return null;
        }

        if (str_starts_with($normalized, 'depth')) {
            return self::LANE_PUBLIC;
        }

        if (str_starts_with($normalized, 'kline_')) {
            return self::LANE_MARKET;
        }

        if ($normalized === 'aggtrade') {
            return self::LANE_TRADE;
        }

        return null;
    }
}
