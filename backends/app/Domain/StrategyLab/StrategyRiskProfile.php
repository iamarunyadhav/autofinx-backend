<?php

namespace App\Domain\StrategyLab;

use InvalidArgumentException;

/**
 * Deterministic risk math shared by Strategy Lab simulations and a future
 * approved-strategy execution adapter. It has no exchange or order side
 * effects. Percentages are price percentages; risk_percent is account equity
 * risk, not leverage.
 */
final class StrategyRiskProfile
{
    /** @param array<string, mixed> $config @return array<string, float> */
    public function levels(float $entry, string $side, float $atr, array $config): array
    {
        if ($entry <= 0 || $atr < 0) {
            throw new InvalidArgumentException('Entry and ATR must be non-negative, with entry above zero.');
        }
        $side = strtoupper($side);
        if (! in_array($side, ['BUY', 'SELL'], true)) {
            throw new InvalidArgumentException('Side must be BUY or SELL.');
        }

        $slPct = $this->distance($entry, $atr, $config, 'stop_loss_pct', 'stop_loss_atr');
        $tpPct = $this->distance($entry, $atr, $config, 'take_profit_pct', 'take_profit_atr');
        if ($slPct <= 0 || $tpPct <= 0) {
            throw new InvalidArgumentException('Stop loss and take profit distances must be positive.');
        }
        $sign = $side === 'BUY' ? 1.0 : -1.0;

        return [
            'entry' => $entry,
            'stop_loss' => $entry * (1 - $sign * $slPct / 100),
            'take_profit' => $entry * (1 + $sign * $tpPct / 100),
            'stop_loss_pct' => $slPct,
            'take_profit_pct' => $tpPct,
            'risk_reward' => $tpPct / $slPct,
            'trailing_pct' => $this->distance($entry, $atr, $config, 'trailing_pct', 'trailing_atr'),
        ];
    }

    public function quantity(float $equity, float $riskPercent, float $entry, float $stopLoss, float $maxNotional = 0): float
    {
        if ($equity <= 0 || $riskPercent <= 0 || $entry <= 0 || $stopLoss <= 0 || $entry === $stopLoss) {
            throw new InvalidArgumentException('Equity, risk, entry and stop loss must define a valid position.');
        }
        $riskAmount = $equity * $riskPercent / 100;
        $qty = $riskAmount / abs($entry - $stopLoss);
        if ($maxNotional > 0) {
            $qty = min($qty, $maxNotional / $entry);
        }

        return $qty;
    }

    /** @param array<string, mixed> $config */
    private function distance(float $entry, float $atr, array $config, string $fixed, string $dynamic): float
    {
        $mode = strtolower((string) ($config['mode'] ?? 'fixed'));
        $value = (float) ($config[$fixed] ?? 0);
        if ($mode === 'atr') {
            $multiple = (float) ($config[$dynamic] ?? 0);
            if ($multiple <= 0 || $atr <= 0) {
                throw new InvalidArgumentException("{$dynamic} requires a positive ATR and multiplier.");
            }
            $value = $atr / $entry * 100 * $multiple;
        } elseif ($mode !== 'fixed') {
            throw new InvalidArgumentException('Risk mode must be fixed or atr.');
        }

        return $value;
    }
}
