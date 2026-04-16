<?php

namespace App\Domain\Ops;

use App\Domain\Risk\WorkspaceLiveRiskService;
use App\Models\Bot;
use App\Support\RiskGuardService;

class LiveExecutionGuard
{
    public function __construct(
        private readonly TradingEnvironmentGuard $modeGuard,
        private readonly KillSwitchService $killSwitch,
        private readonly SafeLiveGate $safeLiveGate,
        private readonly RiskGuardService $riskGuard,
        private readonly WorkspaceLiveRiskService $workspaceRisk,
    ) {
    }

    public function assertWorkspaceCanSubmit(
        string $workspaceId,
        string $symbol,
        float $notional,
        string $provider = 'binance',
        string $side = 'buy',
        ?string $idempotencyKey = null,
        ?string $riskProfileId = null
    ): array
    {
        $this->modeGuard->assertWorkspaceMode($workspaceId, true);
        $this->killSwitch->assertNotActive($workspaceId);
        $this->riskGuard->enforceOrFail($workspaceId);
        $this->safeLiveGate->ensureLiveAllowed($workspaceId, strtoupper($symbol), max(0, $notional), $provider);

        return $this->workspaceRisk->enforceOrFail($workspaceId, [
            'symbol' => $symbol,
            'side' => $side,
            'notional' => $notional,
            'provider' => $provider,
            'idempotency_key' => $idempotencyKey,
            'risk_profile_id' => $riskProfileId,
        ]);
    }

    public function assertBotCanSubmit(
        Bot $bot,
        string $symbol,
        float $notional,
        ?string $provider = null,
        string $side = 'buy',
        ?string $idempotencyKey = null
    ): array
    {
        $provider = strtolower((string) ($provider ?: $bot->provider ?: $bot->exchange ?: 'binance'));
        $this->modeGuard->assertBotMode($bot);
        $this->killSwitch->assertNotActive((string) $bot->workspace_id);
        $this->riskGuard->enforceOrFail((string) $bot->workspace_id);
        $this->safeLiveGate->ensureLiveAllowed((string) $bot->workspace_id, strtoupper($symbol), max(0, $notional), $provider);

        return $this->workspaceRisk->enforceOrFail((string) $bot->workspace_id, [
            'symbol' => $symbol,
            'side' => $side,
            'notional' => $notional,
            'provider' => $provider,
            'idempotency_key' => $idempotencyKey,
            'risk_profile_id' => $bot->risk_profile_id,
        ]);
    }
}
