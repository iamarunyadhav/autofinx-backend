<?php

namespace App\Domain\Portfolio;

use App\Domain\Exchange\BinanceSymbolNormalizer;
use App\Models\ExchangeSymbolMetadata;

class CorrelationHeuristicService
{
    private const STABLE_QUOTES = ['USDT', 'USDC', 'BUSD', 'FDUSD', 'TUSD', 'DAI', 'USD'];

    private const CORE_BENCHMARKS = ['BTC', 'ETH'];

    public function classify(string $symbol, array $portfolioContext = [], array $metadata = []): array
    {
        $symbol = BinanceSymbolNormalizer::normalize($symbol);
        $metadata = $this->normalizeMetadata($metadata);
        $quote = strtoupper((string) ($metadata['quote_asset'] ?? $this->inferQuoteAsset($symbol)));
        $base = strtoupper((string) ($metadata['base_asset'] ?? $this->inferBaseAsset($symbol, $quote)));

        $groupKey = $this->groupKey($base, $quote, $symbol);
        $groupLabel = $this->groupLabel($base, $quote, $symbol);
        $betaGroup = $this->betaGroup($base, $quote);
        $equity = max(0.0, (float) data_get($portfolioContext, 'portfolio.capital.equity_estimate', 0));
        $groupExposure = (array) data_get($portfolioContext, "portfolio.exposure.by_group.{$groupKey}", []);
        $symbolExposure = (array) data_get($portfolioContext, "portfolio.exposure.by_symbol.{$symbol}", []);
        $groupExposurePct = $equity > 0 ? (float) ($groupExposure['exposure_pct'] ?? 0) : null;
        $symbolExposurePct = $equity > 0 ? (float) ($symbolExposure['exposure_pct'] ?? 0) : null;

        return [
            'symbol' => $symbol,
            'group_key' => $groupKey,
            'group_label' => $groupLabel,
            'beta_group' => $betaGroup,
            'base_asset' => $base,
            'quote_asset' => $quote,
            'stable_quote' => in_array($quote, self::STABLE_QUOTES, true),
            'group_count' => (int) ($groupExposure['count'] ?? 0),
            'group_exposure_pct' => $groupExposurePct !== null ? round($groupExposurePct, 4) : null,
            'symbol_exposure_pct' => $symbolExposurePct !== null ? round($symbolExposurePct, 4) : null,
            'concentration_score' => round($this->penalty($groupExposurePct, $symbolExposurePct) * 100, 4),
            'concentration_risk' => round((1 - $this->penalty($groupExposurePct, $symbolExposurePct)) * 100, 4),
            'correlation_penalty' => round(1 - $this->penalty($groupExposurePct, $symbolExposurePct), 4),
            'concentration_note' => $this->note($groupExposurePct, $symbolExposurePct, (int) ($groupExposure['count'] ?? 0)),
            'metadata_source' => $metadata !== [] ? 'exchange_symbol_metadata' : 'heuristic',
        ];
    }

    public function metadataForSymbol(string $symbol): array
    {
        $row = ExchangeSymbolMetadata::query()
            ->where('exchange', 'binance')
            ->where('symbol', BinanceSymbolNormalizer::normalize($symbol))
            ->first();

        return $row ? $row->toArray() : [];
    }

    /**
     * @param array<int, array<string, mixed>> $positions
     * @return array<string, array<string, mixed>>
     */
    public function groupExposure(array $positions, float $equityEstimate): array
    {
        $groups = [];

        foreach ($positions as $position) {
            $symbol = strtoupper((string) ($position['symbol'] ?? $position['asset_code'] ?? ''));
            if ($symbol === '') {
                continue;
            }

            $classification = $this->classify($symbol, ['portfolio' => ['capital' => ['equity_estimate' => $equityEstimate]]], $this->metadataForSymbol($symbol));
            $groupKey = (string) $classification['group_key'];
            $notional = max(0.0, (float) ($position['market_value'] ?? $position['notional'] ?? 0));

            if (! isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'group_key' => $groupKey,
                    'group_label' => $classification['group_label'],
                    'beta_group' => $classification['beta_group'],
                    'symbols' => [],
                    'notional' => 0.0,
                    'count' => 0,
                ];
            }

            $groups[$groupKey]['notional'] += $notional;
            $groups[$groupKey]['count']++;
            $groups[$groupKey]['symbols'][] = $symbol;
        }

        foreach ($groups as &$group) {
            $group['notional'] = round((float) $group['notional'], 8);
            $group['exposure_pct'] = $equityEstimate > 0
                ? round(((float) $group['notional'] / $equityEstimate) * 100, 4)
                : null;
            $group['symbols'] = array_values(array_unique(array_filter($group['symbols'])));
        }

        return $groups;
    }

    private function normalizeMetadata(array $metadata): array
    {
        return [
            'base_asset' => strtoupper((string) ($metadata['base_asset'] ?? '')),
            'quote_asset' => strtoupper((string) ($metadata['quote_asset'] ?? '')),
        ];
    }

    private function inferQuoteAsset(string $symbol): string
    {
        foreach (self::STABLE_QUOTES as $quote) {
            if (str_ends_with($symbol, $quote)) {
                return $quote;
            }
        }

        return 'USDT';
    }

    private function inferBaseAsset(string $symbol, string $quote): string
    {
        if ($symbol === '') {
            return '';
        }

        if ($quote !== '' && str_ends_with($symbol, $quote)) {
            return rtrim(substr($symbol, 0, -strlen($quote)));
        }

        return $symbol;
    }

    private function groupKey(string $base, string $quote, string $symbol): string
    {
        if ($base === '') {
            return 'symbol:' . strtolower($symbol);
        }

        if (in_array($base, self::STABLE_QUOTES, true) || in_array($quote, self::STABLE_QUOTES, true)) {
            return 'stable:' . strtolower($base ?: $quote);
        }

        return 'base:' . strtolower($base);
    }

    private function groupLabel(string $base, string $quote, string $symbol): string
    {
        if ($base === '') {
            return $symbol;
        }

        if (in_array($base, self::STABLE_QUOTES, true) || in_array($quote, self::STABLE_QUOTES, true)) {
            return 'Stable asset family';
        }

        if (in_array($base, self::CORE_BENCHMARKS, true)) {
            return $base . ' core family';
        }

        return $base . ' family';
    }

    private function betaGroup(string $base, string $quote): string
    {
        if (in_array($base, self::STABLE_QUOTES, true) || in_array($quote, self::STABLE_QUOTES, true)) {
            return 'stable';
        }

        if (in_array($base, self::CORE_BENCHMARKS, true)) {
            return strtolower($base);
        }

        return 'alt_beta';
    }

    private function penalty(?float $groupExposurePct, ?float $symbolExposurePct): float
    {
        $components = [];

        if ($groupExposurePct !== null) {
            $components[] = max(0.0, 1 - min(1.0, $groupExposurePct / 20.0));
        }

        if ($symbolExposurePct !== null) {
            $components[] = max(0.0, 1 - min(1.0, $symbolExposurePct / 10.0));
        }

        if ($components === []) {
            return 1.0;
        }

        return array_sum($components) / count($components);
    }

    private function note(?float $groupExposurePct, ?float $symbolExposurePct, int $groupCount): string
    {
        if ($groupExposurePct !== null && $groupExposurePct >= 15) {
            return 'Group exposure is elevated.';
        }

        if ($symbolExposurePct !== null && $symbolExposurePct >= 8) {
            return 'Symbol exposure is elevated.';
        }

        if ($groupCount > 1) {
            return 'Multiple symbols already share this family.';
        }

        return 'Exposure is not concentrated.';
    }
}
