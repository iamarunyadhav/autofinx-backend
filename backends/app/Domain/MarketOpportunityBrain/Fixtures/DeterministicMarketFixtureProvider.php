<?php

namespace App\Domain\MarketOpportunityBrain\Fixtures;

use App\Domain\MarketOpportunityBrain\Contracts\MarketSnapshotProvider;
use App\Domain\MarketOpportunityBrain\Contracts\MarketUniverseProvider;
use App\Domain\MarketOpportunityBrain\Data\Candle;
use App\Domain\MarketOpportunityBrain\Data\DiscoveryRecord;

/**
 * Fully offline, deterministic market provider for Phase 1 unit tests and local fixtures
 * (MASTER_SPEC.md Phase 1: "deterministic simulated market provider... No external live data yet").
 *
 * No network calls, no RNG (prices come from a pure sin() wave keyed by a crc32 phase per symbol),
 * no wall-clock dependency -- every method is a pure function of its arguments, so the same
 * `asOfUtc` always yields byte-identical output. Symbol names are deliberately fictitious
 * (`FIXTURE...USDT`) so nobody mistakes this for real market data or a real listing.
 */
final class DeterministicMarketFixtureProvider implements MarketUniverseProvider, MarketSnapshotProvider
{
    private const EXCHANGE = 'BINANCE';

    private const MARKET = 'USDM_PERPETUAL';

    /**
     * Two synthetic symbols: one with no delisting (always eligible once enough history exists)
     * and one that delists mid-way through the fixture window, to exercise point-in-time
     * historical-membership exclusion (Section M test #1 / PHASES_AND_TEST_GATES.md invariant
     * `test_unlisted_or_delisted_historical_symbol_excluded_at_asof`).
     */
    private const UNIVERSE = [
        'FIXTUREAUSDT' => ['listed' => '2026-01-01T00:00:00Z', 'delisted' => null, 'base_price' => 100.0, 'base_volume' => 5_000_000.0],
        'FIXTUREBUSDT' => ['listed' => '2026-01-01T00:00:00Z', 'delisted' => '2026-06-01T00:00:00Z', 'base_price' => 10.0, 'base_volume' => 50_000.0],
    ];

    private const MIN_FRESH_CANDLES = 30;

    private const MIN_ELIGIBLE_VOLUME = 1_000_000.0;

    public function __construct(private readonly string $workspaceId = 'local-research-workspace')
    {
    }

    /**
     * @return list<DiscoveryRecord>
     */
    public function universeAsOf(\DateTimeImmutable $asOfUtc): iterable
    {
        $candidates = [];

        foreach (self::UNIVERSE as $symbol => $meta) {
            $listedAt = new \DateTimeImmutable($meta['listed']);
            $delistedAt = $meta['delisted'] !== null ? new \DateTimeImmutable($meta['delisted']) : null;

            // Historical point-in-time membership: a symbol not yet listed, or already delisted,
            // as of this timestamp is excluded from the universe outright -- never fabricated or
            // projected from "currently listed" status.
            if ($asOfUtc < $listedAt || ($delistedAt !== null && $asOfUtc >= $delistedAt)) {
                continue;
            }

            $candles = $this->closedCandles($symbol, '15m', $asOfUtc, 96);
            $lastCandle = $candles === [] ? null : $candles[count($candles) - 1];

            $reasonCodes = [];
            $hasFreshHistory = count($candles) >= self::MIN_FRESH_CANDLES;
            $hasLiquidity = $meta['base_volume'] >= self::MIN_ELIGIBLE_VOLUME;
            if (! $hasFreshHistory) {
                $reasonCodes[] = 'INSUFFICIENT_FRESH_CANDLES';
            }
            if (! $hasLiquidity) {
                $reasonCodes[] = 'INSUFFICIENT_LIQUIDITY_EVIDENCE';
            }
            $eligible = $hasFreshHistory && $hasLiquidity;
            if ($eligible) {
                $reasonCodes[] = 'ELIGIBLE';
            }

            $candidates[] = [
                'symbol' => $symbol,
                'eligible' => $eligible,
                'volume' => $meta['base_volume'],
                'reasonCodes' => $reasonCodes,
                'lastCandle' => $lastCandle,
                'candleCount' => count($candles),
            ];
        }

        // Deterministic versioned baseline ranking: eligible symbols ranked by trailing volume
        // (descending), matching MASTER_SPEC.md Layer E §3 ("weighted normalized ranks" -- volume
        // is the only available fixture signal, so it is the whole weight here).
        $eligibleSorted = array_values(array_filter($candidates, static fn (array $c) => $c['eligible']));
        usort($eligibleSorted, static fn (array $a, array $b) => $b['volume'] <=> $a['volume']);
        $ranks = [];
        foreach ($eligibleSorted as $i => $c) {
            $ranks[$c['symbol']] = $i + 1;
        }
        $maxVolume = $eligibleSorted === [] ? 0.0 : max(array_column($eligibleSorted, 'volume'));

        $records = [];
        foreach ($candidates as $c) {
            /** @var Candle|null $lastCandle */
            $lastCandle = $c['lastCandle'];
            $symbol = $c['symbol'];
            $rank = $ranks[$symbol] ?? null;
            $opportunityScore = ($c['eligible'] && $maxVolume > 0.0) ? round($c['volume'] / $maxVolume, 6) : null;
            $sourceFreshnessSeconds = $lastCandle !== null
                ? max(0, $asOfUtc->getTimestamp() - (new \DateTimeImmutable($lastCandle->closeTimeUtc))->getTimestamp())
                : null;

            $records[] = new DiscoveryRecord(
                snapshotId: sprintf('fixture-%s-%d', $symbol, $asOfUtc->getTimestamp()),
                exchange: self::EXCHANGE,
                market: self::MARKET,
                symbol: $symbol,
                workspaceId: $this->workspaceId,
                asOfUtc: $asOfUtc->format('Y-m-d\TH:i:s\Z'),
                lastClosedCandleUtc: $lastCandle?->closeTimeUtc,
                listedAsOf: true,
                eligibleForAnalysis: $c['eligible'],
                rank: $rank,
                opportunityScore: $opportunityScore,
                direction: null, // discovery rank is deliberately direction-free (MASTER_SPEC.md Layer E)
                sourceFreshnessSeconds: $sourceFreshnessSeconds,
                reasonCodes: $c['reasonCodes'],
                featureSchemaVersion: 'mob-discovery-v1',
                featureWindowHashSha256: $c['candleCount'] > 0 ? $this->windowHash($symbol, $asOfUtc) : null,
            );
        }

        return $records;
    }

    /**
     * @return list<Candle>
     */
    public function closedCandles(string $symbol, string $timeframe, \DateTimeImmutable $asOfUtc, int $limit): array
    {
        if (! isset(self::UNIVERSE[$symbol])) {
            return [];
        }
        $meta = self::UNIVERSE[$symbol];
        $stepSeconds = $this->timeframeSeconds($timeframe);
        $listedAt = new \DateTimeImmutable($meta['listed']);
        $delistedAt = $meta['delisted'] !== null ? new \DateTimeImmutable($meta['delisted']) : null;

        $elapsed = $asOfUtc->getTimestamp() - $listedAt->getTimestamp();
        if ($elapsed < $stepSeconds) {
            return [];
        }
        // Number of bars fully closed by asOfUtc -- an in-progress bar is never counted as closed.
        $lastClosedIndex = intdiv($elapsed, $stepSeconds);

        if ($delistedAt !== null) {
            $delistedElapsed = $delistedAt->getTimestamp() - $listedAt->getTimestamp();
            $lastClosedIndex = min($lastClosedIndex, intdiv($delistedElapsed, $stepSeconds));
        }

        $firstIndex = max(0, $lastClosedIndex - $limit);
        $candles = [];
        for ($i = $firstIndex; $i < $lastClosedIndex; $i++) {
            $openTs = $listedAt->getTimestamp() + $i * $stepSeconds;
            $closeTs = $openTs + $stepSeconds;
            $priceOpen = $this->priceAt($symbol, (float) $meta['base_price'], $i);
            $priceClose = $this->priceAt($symbol, (float) $meta['base_price'], $i + 1);

            $candles[] = new Candle(
                symbol: $symbol,
                timeframe: $timeframe,
                openTimeUtc: gmdate('Y-m-d\TH:i:s\Z', $openTs),
                closeTimeUtc: gmdate('Y-m-d\TH:i:s\Z', $closeTs),
                observedAtUtc: gmdate('Y-m-d\TH:i:s\Z', $closeTs),
                open: $priceOpen,
                high: round(max($priceOpen, $priceClose) * 1.001, 8),
                low: round(min($priceOpen, $priceClose) * 0.999, 8),
                close: $priceClose,
                volume: round(((float) $meta['base_volume']) / 96.0, 4),
                isClosed: true,
                source: 'deterministic_fixture',
            );
        }

        return $candles;
    }

    private function priceAt(string $symbol, float $basePrice, int $index): float
    {
        $phase = (crc32($symbol) % 1000) / 1000 * M_PI * 2;
        $wave = sin(($index * 0.1) + $phase) * ($basePrice * 0.02);

        return round($basePrice + $wave, 8);
    }

    private function windowHash(string $symbol, \DateTimeImmutable $asOfUtc): string
    {
        return hash('sha256', $symbol.'|'.$asOfUtc->format('Y-m-d\TH:i:s\Z'));
    }

    private function timeframeSeconds(string $timeframe): int
    {
        return match ($timeframe) {
            '1m' => 60,
            '5m' => 300,
            '15m' => 900,
            '1h' => 3600,
            '4h' => 14400,
            '1d' => 86400,
            default => throw new \InvalidArgumentException("Unsupported fixture timeframe: {$timeframe}"),
        };
    }
}
