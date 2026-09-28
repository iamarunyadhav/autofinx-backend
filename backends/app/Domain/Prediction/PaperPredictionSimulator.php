<?php

namespace App\Domain\Prediction;

/** Simulates a normalized prediction; intentionally has no broker dependency. */
final class PaperPredictionSimulator
{
    /** @param array<string, mixed> $prediction @param array<string, mixed> $costs */
    public function simulate(array $prediction, float $entryPrice, float $exitPrice, array $costs = []): array
    {
        $decision = strtoupper((string) ($prediction['decision'] ?? $prediction['direction'] ?? 'WAIT'));
        if (!in_array($decision, ['BUY', 'SELL', 'WAIT'], true) || $entryPrice <= 0 || $exitPrice <= 0) {
            return ['status' => 'UNAVAILABLE', 'execution' => false, 'decision' => 'WAIT'];
        }
        if ($decision === 'WAIT') {
            return ['status' => 'SIMULATED', 'execution' => false, 'decision' => 'WAIT', 'entry_price' => $entryPrice, 'exit_price' => $exitPrice, 'net_pnl_pct' => 0.0];
        }

        $gross = (($exitPrice / $entryPrice) - 1) * 100 * ($decision === 'SELL' ? -1 : 1);
        $cost = (2 * max(0, (float) ($costs['fee_pct'] ?? 0)))
            + (2 * max(0, (float) ($costs['slippage_pct'] ?? 0)))
            + (float) ($costs['funding_pct'] ?? 0);

        return [
            'status' => 'SIMULATED', 'execution' => false, 'decision' => $decision,
            'entry_price' => $entryPrice, 'exit_price' => $exitPrice,
            'gross_pnl_pct' => round($gross, 8), 'cost_pct' => round($cost, 8),
            'net_pnl_pct' => round($gross - $cost, 8), 'profitable' => $gross - $cost > 0,
        ];
    }
}
