<?php

namespace App\Domain\Fast2X;

class PnLTrackingService
{
    public function calculate(float $entryPrice, float $currentPrice, float $quantity, array $settings): array
    {
        $gross = ($currentPrice - $entryPrice) * $quantity;
        $notional = $currentPrice * $quantity;
        $fees = $notional * ((float) ($settings['feePercent'] ?? 0.1) / 100);
        $slippage = $notional * ((float) ($settings['estimatedSlippagePercent'] ?? 0.02) / 100);
        $net = $gross - $fees - $slippage;
        $netPercent = $entryPrice > 0 ? (($currentPrice - $entryPrice) / $entryPrice * 100) - (float) ($settings['feePercent'] ?? 0.1) - (float) ($settings['estimatedSlippagePercent'] ?? 0.02) : 0;

        return [
            'entryPrice' => $entryPrice,
            'currentPrice' => $currentPrice,
            'quantity' => $quantity,
            'grossPnL' => round($gross, 8),
            'estimatedFees' => round($fees, 8),
            'estimatedSlippage' => round($slippage, 8),
            'netPnL' => round($net, 8),
            'netPnLPercent' => round($netPercent, 6),
            'realizedPnL' => 0,
            'unrealizedPnL' => round($net, 8),
        ];
    }
}
