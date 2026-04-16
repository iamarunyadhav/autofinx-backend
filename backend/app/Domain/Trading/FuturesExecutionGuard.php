<?php

namespace App\Domain\Trading;

use App\Domain\Risk\FuturesRiskService;
use RuntimeException;

class FuturesExecutionGuard
{
    public function __construct(
        private readonly FuturesConfigService $configs,
        private readonly FuturesPolicyService $policy,
        private readonly FuturesRiskService $risk,
    ) {
    }

    public function evaluate(string $workspaceId, array $proposal, array $market = []): array
    {
        $config = $this->configs->effective($workspaceId);
        $policy = $this->policy->evaluate($workspaceId, $proposal, $config);
        $risk = $this->risk->evaluate($workspaceId, $config, $proposal, $market);

        return [
            'config' => $config,
            'policy' => $policy,
            'risk' => $risk,
            'allow' => ($policy['allow'] ?? false) && ($risk['allow'] ?? false),
        ];
    }

    public function assertCanSubmit(string $workspaceId, array $proposal, array $market = []): array
    {
        $evaluation = $this->evaluate($workspaceId, $proposal, $market);

        if (! ($evaluation['policy']['allow'] ?? false)) {
            throw new RuntimeException('Futures policy rejected request: ' . implode(', ', $evaluation['policy']['reasons'] ?? []));
        }

        if (! ($evaluation['risk']['allow'] ?? false)) {
            throw new RuntimeException('Futures risk rejected request: ' . implode(', ', $evaluation['risk']['reasons'] ?? []));
        }

        if (($evaluation['policy']['requires_manual_approval'] ?? false) && strtolower((string) ($proposal['source'] ?? 'manual_operator')) !== 'manual_operator') {
            throw new RuntimeException('Futures action requires manual approval.');
        }

        if (($evaluation['policy']['mode'] ?? 'disabled') === 'review_only' && strtolower((string) ($proposal['source'] ?? 'manual_operator')) !== 'manual_operator') {
            throw new RuntimeException('Review-only futures mode does not allow direct submission.');
        }

        return $evaluation;
    }
}
