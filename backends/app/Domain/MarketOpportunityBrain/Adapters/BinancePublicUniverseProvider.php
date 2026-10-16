<?php

namespace App\Domain\MarketOpportunityBrain\Adapters;

use App\Domain\MarketOpportunityBrain\Contracts\MarketUniverseProvider;
use App\Domain\MarketOpportunityBrain\Data\DiscoveryRecord;

/**
 * Real, read-only Binance USDⓈ-M perpetual universe scan (MASTER_SPEC.md Layer D §46, Layer E).
 *
 * Honesty boundary: `exchangeInfo`/`ticker/24hr` only ever describe the CURRENT exchange state.
 * This class does NOT and cannot reconstruct historical point-in-time listing/delisting
 * membership from those endpoints alone (that needs a bulk historical archive, e.g.
 * data.binance.vision, which is explicitly out of scope for this phase). Asking for a universe
 * "as of" any timestamp outside a small near-real-time tolerance fails loudly
 * (`\DomainException`) rather than silently returning today's universe mislabeled as historical
 * -- this is the deliberate alternative to fabricating missing coins (MASTER_SPEC.md Layer D §46).
 */
final class BinancePublicUniverseProvider implements MarketUniverseProvider
{
    private const EXCHANGE = 'BINANCE';

    private const MARKET = 'USDM_PERPETUAL';

    public function __construct(private readonly BinancePublicRestClient $client = new BinancePublicRestClient())
    {
    }

    /**
     * @return list<DiscoveryRecord>
     */
    public function universeAsOf(\DateTimeImmutable $asOfUtc): iterable
    {
        $tolerance = max(1, (int) config('market_opportunity_brain.universe_as_of_tolerance_seconds', 120));
        $driftSeconds = abs((new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->getTimestamp() - $asOfUtc->getTimestamp());
        if ($driftSeconds > $tolerance) {
            throw new \DomainException(
                "BinancePublicUniverseProvider only supports near-real-time snapshots (requested as-of is {$driftSeconds}s from now, tolerance is {$tolerance}s). ".
                'Historical point-in-time universe reconstruction requires a bulk archive adapter that is not implemented in this phase.'
            );
        }

        $exchangeInfo = $this->client->exchangeInfo();
        $symbolsInfo = (array) ($exchangeInfo['symbols'] ?? []);

        $tickers = $this->client->ticker24hr();
        $volumeBySymbol = [];
        foreach ($tickers as $t) {
            if (isset($t['symbol'])) {
                $volumeBySymbol[(string) $t['symbol']] = (float) ($t['quoteVolume'] ?? 0.0);
            }
        }

        $minVolume = (float) config('market_opportunity_brain.min_quote_volume_24h', 1_000_000);
        $maxSymbols = (int) config('market_opportunity_brain.max_discovery_symbols', 0);
        $asOfIso = $asOfUtc->format('Y-m-d\TH:i:s\Z');
        $workspaceId = (string) config('market_opportunity_brain.workspace_id_default', 'local-research-workspace');

        $candidates = [];
        foreach ($symbolsInfo as $info) {
            $symbol = (string) ($info['symbol'] ?? '');
            if ($symbol === '') {
                continue;
            }
            $status = (string) ($info['status'] ?? '');
            $contractType = (string) ($info['contractType'] ?? '');
            $quoteAsset = (string) ($info['quoteAsset'] ?? '');

            $reasonCodes = [];
            if ($status !== 'TRADING') {
                $reasonCodes[] = 'NOT_CURRENTLY_TRADING';
            }
            if ($contractType !== 'PERPETUAL') {
                $reasonCodes[] = 'NOT_PERPETUAL_CONTRACT';
            }
            if ($quoteAsset !== 'USDT') {
                $reasonCodes[] = 'NOT_USDT_MARGINED';
            }

            // Symbols that are not even the right market type are excluded outright (never shown
            // as an ineligible USDT perpetual candidate) -- only TRADING USDT perpetuals proceed
            // to the liquidity gate below.
            if ($reasonCodes !== []) {
                continue;
            }

            $volume = $volumeBySymbol[$symbol] ?? null;
            if ($volume === null) {
                $reasonCodes[] = 'MISSING_VOLUME_EVIDENCE';
            } elseif ($volume < $minVolume) {
                $reasonCodes[] = 'INSUFFICIENT_LIQUIDITY_EVIDENCE';
            }
            $eligible = $reasonCodes === [];
            if ($eligible) {
                $reasonCodes[] = 'ELIGIBLE';
            }

            $candidates[] = [
                'symbol' => $symbol,
                'eligible' => $eligible,
                'volume' => $volume ?? 0.0,
                'reasonCodes' => $reasonCodes,
            ];
        }

        $eligibleSorted = array_values(array_filter($candidates, static fn (array $c) => $c['eligible']));
        usort($eligibleSorted, static fn (array $a, array $b) => $b['volume'] <=> $a['volume']);
        if ($maxSymbols > 0) {
            $eligibleSorted = array_slice($eligibleSorted, 0, $maxSymbols);
        }
        $eligibleSymbols = array_column($eligibleSorted, 'symbol');
        $ranks = array_flip($eligibleSymbols);
        $maxVolume = $eligibleSorted === [] ? 0.0 : max(array_column($eligibleSorted, 'volume'));

        $records = [];
        foreach ($candidates as $c) {
            $symbol = $c['symbol'];
            $isRankedEligible = in_array($symbol, $eligibleSymbols, true);
            $rank = $isRankedEligible ? ($ranks[$symbol] + 1) : null;
            $opportunityScore = ($isRankedEligible && $maxVolume > 0.0) ? round($c['volume'] / $maxVolume, 6) : null;
            $reasonCodes = $c['reasonCodes'];
            if ($c['eligible'] && ! $isRankedEligible) {
                $reasonCodes = array_values(array_diff($reasonCodes, ['ELIGIBLE']));
                $reasonCodes[] = 'OUTSIDE_TOP_K_CAP';
            }

            $records[] = new DiscoveryRecord(
                snapshotId: sprintf('binance-public-%s-%d', $symbol, $asOfUtc->getTimestamp()),
                exchange: self::EXCHANGE,
                market: self::MARKET,
                symbol: $symbol,
                workspaceId: $workspaceId,
                asOfUtc: $asOfIso,
                lastClosedCandleUtc: null, // caller should pair with BinancePublicSnapshotProvider for this
                listedAsOf: true,
                eligibleForAnalysis: $c['eligible'] && $isRankedEligible,
                rank: $rank,
                opportunityScore: $opportunityScore,
                direction: null,
                sourceFreshnessSeconds: 0,
                reasonCodes: $reasonCodes,
                featureSchemaVersion: 'mob-discovery-v1',
                featureWindowHashSha256: null,
            );
        }

        return $records;
    }
}
