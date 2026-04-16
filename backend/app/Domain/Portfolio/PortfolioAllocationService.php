<?php

namespace App\Domain\Portfolio;

use App\Models\PortfolioIntelligenceConfig;

class PortfolioAllocationService
{
    /**
     * @param array<int, array<string, mixed>> $selectedOpportunities
     */
    public function allocate(array $selectedOpportunities, array $portfolioContext, mixed $config = [], array $riskBudget = []): array
    {
        $config = $this->normalizeConfig($config);
        $allocationMode = (string) ($config['allocation_mode'] ?? 'capped_score_weighted');
        $equity = max(0.0, (float) data_get($portfolioContext, 'portfolio.capital.equity_estimate', 0));
        $availableCapital = max(0.0, (float) data_get($riskBudget, 'remaining.capital_notional', data_get($portfolioContext, 'portfolio.capital.free_capital', 0)));

        if ((bool) data_get($riskBudget, 'reduce_risk_mode', false)) {
            $availableCapital *= 0.5;
        }

        $maxSymbolPct = max(0.0, (float) ($config['max_symbol_allocated_pct'] ?? 8));
        $maxGroupPct = max(0.0, (float) ($config['max_correlated_group_allocated_pct'] ?? 15));
        $globalCapNotional = $equity > 0
            ? $equity * (max(0.0, (float) ($config['max_total_allocated_pct'] ?? 35)) / 100)
            : $availableCapital;
        $globalCapNotional = min($globalCapNotional, $availableCapital);

        $weights = $this->weights($selectedOpportunities, $allocationMode, $config);
        $selectedWeightsTotal = array_sum($weights) ?: 1.0;
        $remainingCapital = $globalCapNotional;
        $allocations = [];
        $blocked = [];
        $allocatedTotal = 0.0;
        $allocatedByGroup = [];
        $allocatedBySymbol = [];

        foreach ($selectedOpportunities as $index => $opportunity) {
            $symbol = strtoupper((string) ($opportunity['symbol'] ?? ''));
            $price = max(0.0, (float) data_get($opportunity, 'market_price', 0));
            $metadata = (array) data_get($opportunity, 'exchange_metadata', []);
            $groupKey = (string) data_get($opportunity, 'portfolio_impact.group_key', '');
            $score = max(0.0, (float) ($opportunity['score'] ?? 0));
            $weight = (float) ($weights[$symbol] ?? 0);
            $normalizedWeight = $selectedWeightsTotal > 0 ? $weight / $selectedWeightsTotal : 0;
            $desiredNotional = $remainingCapital * $normalizedWeight;
            $symbolExposureNotional = max(0.0, (float) data_get($portfolioContext, "portfolio.exposure.by_symbol.{$symbol}.notional", 0));
            $groupExposureNotional = max(0.0, (float) data_get($portfolioContext, "portfolio.exposure.by_group.{$groupKey}.notional", 0));
            $symbolAllocated = (float) ($allocatedBySymbol[$symbol] ?? 0);
            $groupAllocated = (float) ($allocatedByGroup[$groupKey] ?? 0);
            $symbolRemaining = $equity > 0 ? max(0.0, ($equity * ($maxSymbolPct / 100)) - $symbolExposureNotional - $symbolAllocated) : $desiredNotional;
            $groupRemaining = $equity > 0 ? max(0.0, ($equity * ($maxGroupPct / 100)) - $groupExposureNotional - $groupAllocated) : $desiredNotional;
            $minNotional = max(0.0, (float) data_get($metadata, 'min_notional', 0));
            $stepSize = max(0.0, (float) data_get($metadata, 'step_size', 0));

            $notionalCap = min($desiredNotional, $remainingCapital, $symbolRemaining, $groupRemaining, $globalCapNotional);
            $cappedBy = [];

            if ($notionalCap < $desiredNotional) {
                $cappedBy[] = 'risk_budget';
            }
            if ($notionalCap < $symbolRemaining) {
                $cappedBy[] = 'symbol_cap';
            }
            if ($notionalCap < $groupRemaining) {
                $cappedBy[] = 'group_cap';
            }

            if ($price <= 0) {
                $blocked[] = [
                    'symbol' => $symbol,
                    'reason_codes' => ['missing_price'],
                    'selection_status' => 'blocked',
                ];
                continue;
            }

            if ($notionalCap <= 0 || $notionalCap < $minNotional) {
                $blocked[] = [
                    'symbol' => $symbol,
                    'reason_codes' => array_values(array_filter([
                        $notionalCap <= 0 ? 'no_capital_remaining' : null,
                        $notionalCap < $minNotional ? 'below_min_notional' : null,
                    ])),
                    'selection_status' => 'blocked',
                ];
                continue;
            }

            $quantity = $notionalCap / $price;
            if ($stepSize > 0) {
                $quantity = floor($quantity / $stepSize) * $stepSize;
            }

            $finalNotional = round($quantity * $price, 8);
            if ($finalNotional < $minNotional || $finalNotional <= 0) {
                $blocked[] = [
                    'symbol' => $symbol,
                    'reason_codes' => ['below_min_notional_after_rounding'],
                    'selection_status' => 'blocked',
                ];
                continue;
            }

            $remainingCapital = max(0.0, $remainingCapital - $finalNotional);
            $allocatedTotal += $finalNotional;
            $allocatedBySymbol[$symbol] = $symbolAllocated + $finalNotional;
            $allocatedByGroup[$groupKey] = $groupAllocated + $finalNotional;
            $allocations[$symbol] = [
                'symbol' => $symbol,
                'score' => $score,
                'allocation_mode' => $allocationMode,
                'rank' => (int) ($opportunity['rank'] ?? ($index + 1)),
                'target_weight_pct' => round($selectedWeightsTotal > 0 ? ($normalizedWeight * 100) : 0, 4),
                'target_notional' => round($finalNotional, 8),
                'target_qty' => round($quantity, 8),
                'price' => round($price, 8),
                'min_notional' => $minNotional,
                'step_size' => $stepSize,
                'capped_by' => array_values(array_unique($cappedBy)),
                'selection_status' => (string) ($opportunity['selection_status'] ?? 'selected'),
                'approval_required' => (bool) ($opportunity['approval_required'] ?? false),
                'auto_allowed' => (bool) ($opportunity['auto_allowed'] ?? false),
                'reason_codes' => array_values(array_filter(array_merge(
                    (array) data_get($opportunity, 'blockers', []),
                    (array) data_get($opportunity, 'cautions', [])
                ))),
                'portfolio_impact' => $opportunity['portfolio_impact'] ?? [],
                'score_breakdown' => $opportunity['score_breakdown'] ?? [],
            ];
        }

        return [
            'mode' => $allocationMode,
            'available_capital' => round($availableCapital, 8),
            'global_capital_cap' => round($globalCapNotional, 8),
            'allocated_capital' => round($allocatedTotal, 8),
            'residual_capital' => round(max(0.0, $remainingCapital), 8),
            'allocations' => $allocations,
            'allocation_list' => array_values($allocations),
            'blocked' => $blocked,
            'summary' => [
                'allocated_count' => count($allocations),
                'blocked_count' => count($blocked),
                'allocated_capital' => round($allocatedTotal, 8),
                'residual_capital' => round(max(0.0, $remainingCapital), 8),
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $selectedOpportunities
     * @return array<string, float>
     */
    private function weights(array $selectedOpportunities, string $mode, array $config): array
    {
        $weights = [];
        foreach ($selectedOpportunities as $opportunity) {
            $symbol = strtoupper((string) ($opportunity['symbol'] ?? ''));
            if ($symbol === '') {
                continue;
            }

            $score = max(0.0, (float) ($opportunity['score'] ?? 0));
            $concentration = max(0.25, min(1.0, (float) data_get($opportunity, 'correlation.concentration_score', data_get($opportunity, 'portfolio_impact.symbol_exposure_pct', 60)) / 100));
            $external = max(0.25, min(1.0, (float) data_get($this->factorByName($opportunity, 'external_context'), 'score', 55) / 100));
            $liquidity = max(0.25, min(1.0, (float) data_get($this->factorByName($opportunity, 'liquidity'), 'score', 55) / 100));

            $weights[$symbol] = match ($mode) {
                'equal_weight' => 1.0,
                'score_weighted' => $score,
                'conservative_risk_weighted' => $score * $concentration * $external * $liquidity,
                default => $score * max(0.5, $concentration) * max(0.5, $external),
            };
        }

        if ($weights === []) {
            return [];
        }

        $sum = array_sum($weights);
        if ($sum <= 0) {
            $count = count($weights) ?: 1;
            foreach ($weights as $symbol => $_) {
                $weights[$symbol] = 1 / $count;
            }
        }

        return $weights;
    }

    private function factorByName(array $opportunity, string $name): array
    {
        foreach ((array) ($opportunity['score_breakdown'] ?? []) as $factor) {
            if (($factor['factor'] ?? null) === $name) {
                return $factor;
            }
        }

        return [];
    }

    private function normalizeConfig(mixed $config): array
    {
        if ($config instanceof PortfolioIntelligenceConfig) {
            return $config->toArray();
        }

        return is_array($config) ? $config : [];
    }
}
