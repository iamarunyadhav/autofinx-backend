<?php

namespace App\Domain\Portfolio;

use App\Models\Fast2XConfig;
use App\Models\FutureTradeBotConfig;

class LivePortfolioGuardService
{
    public function canActivate(string $workspaceId, string $requestingBot): array
    {
        $active = $this->activeLiveBot($workspaceId, $requestingBot);

        if (! $active) {
            return ['allowed' => true, 'reason' => null, 'activeBot' => null];
        }

        return [
            'allowed' => false,
            'reason' => sprintf('%s is already active in live mode for this workspace/account.', $active['label']),
            'activeBot' => $active,
        ];
    }

    public function activeLiveBot(string $workspaceId, ?string $ignoreBot = null): ?array
    {
        if ($ignoreBot !== 'fast2x') {
            $fast2x = Fast2XConfig::where('workspace_id', $workspaceId)->with('state')->first();
            if ($fast2x && $fast2x->enable_live_trading && $fast2x->state && ($fast2x->state->status === 'Running' || (float) $fast2x->state->quantity > 0)) {
                return [
                    'key' => 'fast2x',
                    'label' => 'Fast2X',
                    'workspaceId' => $workspaceId,
                ];
            }
        }

        if ($ignoreBot !== 'future_trade') {
            $futureTrade = FutureTradeBotConfig::where('workspace_id', $workspaceId)->with('state')->first();
            if ($futureTrade && $futureTrade->enable_live_trading && $futureTrade->enabled && $futureTrade->state && ($futureTrade->state->status === 'Running' || (float) $futureTrade->state->quantity > 0)) {
                return [
                    'key' => 'future_trade',
                    'label' => 'Future Trade Bot',
                    'workspaceId' => $workspaceId,
                ];
            }
        }

        return null;
    }
}
