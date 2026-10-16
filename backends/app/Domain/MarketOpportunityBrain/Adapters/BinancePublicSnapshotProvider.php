<?php

namespace App\Domain\MarketOpportunityBrain\Adapters;

use App\Domain\MarketOpportunityBrain\Contracts\MarketSnapshotProvider;
use App\Domain\MarketOpportunityBrain\Data\Candle;

/**
 * Real, read-only Binance USDⓈ-M futures candle snapshot (MASTER_SPEC.md Layer D §44). Only
 * CLOSED candles are ever returned: both `close_time <= asOfUtc` AND `close_time < real wall
 * clock now` are enforced, so a caller can never be handed an in-progress bar labelled as
 * confirmed, even if `asOfUtc` is accidentally passed as "now."
 */
final class BinancePublicSnapshotProvider implements MarketSnapshotProvider
{
    private const SUPPORTED_TIMEFRAMES = ['1m', '5m', '15m', '1h', '4h', '1d'];

    public function __construct(private readonly BinancePublicRestClient $client = new BinancePublicRestClient())
    {
    }

    public function closedCandles(string $symbol, string $timeframe, \DateTimeImmutable $asOfUtc, int $limit): array
    {
        if (! in_array($timeframe, self::SUPPORTED_TIMEFRAMES, true)) {
            throw new \InvalidArgumentException("Unsupported timeframe: {$timeframe}");
        }

        // Fetch a few extra bars so that filtering out any still-open trailing candle still
        // leaves $limit closed candles where history allows.
        $raw = $this->client->klines($symbol, $timeframe, $limit + 2);

        $nowMs = (int) round(microtime(true) * 1000);
        $asOfMs = $asOfUtc->getTimestamp() * 1000 + (int) $asOfUtc->format('v');

        $candles = [];
        $seenOpenTimes = [];
        foreach ($raw as $row) {
            if (! is_array($row) || count($row) < 7) {
                continue; // malformed row -- skip rather than guess
            }
            [$openTimeMs, $open, $high, $low, $close, $volume, $closeTimeMs] = $row;
            $quoteVolume = isset($row[7]) ? (float) $row[7] : null;
            // Binance kline schema: index 9 is taker-buy base volume and index 10 is
            // taker-buy quote volume.  Candle volume is base volume, so use index 9
            // for a dimensionally valid buy ratio.
            $takerBuyBaseVolume = isset($row[9]) ? (float) $row[9] : null;

            if ($closeTimeMs > $asOfMs || $closeTimeMs >= $nowMs) {
                continue; // not actually closed as of the requested timestamp -- never include it
            }
            if (isset($seenOpenTimes[$openTimeMs])) {
                continue; // duplicate open_time -- Candle contract requires uniqueness
            }
            $seenOpenTimes[$openTimeMs] = true;

            $candles[] = new Candle(
                symbol: $symbol,
                timeframe: $timeframe,
                openTimeUtc: gmdate('Y-m-d\TH:i:s\Z', intdiv((int) $openTimeMs, 1000)),
                closeTimeUtc: gmdate('Y-m-d\TH:i:s\Z', intdiv((int) $closeTimeMs, 1000)),
                observedAtUtc: gmdate('Y-m-d\TH:i:s\Z', intdiv($nowMs, 1000)),
                open: (float) $open,
                high: (float) $high,
                low: (float) $low,
                close: (float) $close,
                volume: (float) $volume,
                isClosed: true,
                source: 'binance_public_rest',
                quoteVolume: $quoteVolume,
                takerVolume: $takerBuyBaseVolume,
            );
        }

        // Binance returns ascending by open time already; sort defensively and trim to $limit.
        usort($candles, static fn (Candle $a, Candle $b) => $a->openTimeUtc <=> $b->openTimeUtc);

        return array_slice($candles, -$limit);
    }
}
