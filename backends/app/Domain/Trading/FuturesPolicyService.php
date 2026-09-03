<?php

namespace App\Domain\Trading;

use App\Domain\Ops\KillSwitchService;
use App\Domain\Ops\OperationalBreakerService;

class FuturesPolicyService
{
    public function __construct(
        private readonly FuturesConfigService $configs,
        private readonly FuturesReadinessService $readiness,
        private readonly KillSwitchService $killSwitch,
        private readonly OperationalBreakerService $breakers,
    ) {
    }

    public function evaluate(string $workspaceId, array $proposal, ?array $config = null): array
    {
        $config ??= $this->configs->effective($workspaceId);
        $readiness = $this->readiness->state($workspaceId);
        $symbol = strtoupper(trim((string) ($proposal['symbol'] ?? '')));
        $source = strtolower((string) ($proposal['source'] ?? 'manual_operator'));
        $confidence = array_key_exists('confidence', $proposal) ? (float) ($proposal['confidence'] ?? 0) : null;
        $marginMode = strtolower((string) ($proposal['margin_mode'] ?? 'isolated'));
        $positionMode = strtolower((string) ($proposal['position_mode'] ?? 'one_way'));

        $reasons = [];
        if (($readiness['state'] ?? 'disabled') === 'disabled') {
            $reasons[] = 'futures_disabled';
        }
        if (in_array((string) ($readiness['state'] ?? ''), ['config_incomplete', 'not_safe_for_live', 'live_blocked_pending_more_hardening'], true)) {
            $reasons[] = 'futures_readiness_incomplete';
        }
        if (! ($config['futures_force_testnet'] ?? false)) {
            $reasons[] = 'live_futures_blocked';
        }
        if ($this->killSwitch->isActive($workspaceId)) {
            $reasons[] = 'kill_switch_active';
        }
        if ($this->breakers->isProviderOutageActive($workspaceId, 'binance')) {
            $reasons[] = 'exchange_unhealthy';
        }
        if ($this->breakers->isStaleFeedBreakerActive($workspaceId, $symbol !== '' ? $symbol : null)) {
            $reasons[] = 'stale_data_breaker_active';
        }

        $allowedSymbols = array_map('strtoupper', (array) ($config['allowed_futures_symbols'] ?? []));
        $blockedSymbols = array_map('strtoupper', (array) ($config['blocked_futures_symbols'] ?? []));
        if ($symbol === '') {
            $reasons[] = 'symbol_required';
        } elseif ($allowedSymbols !== [] && ! in_array($symbol, $allowedSymbols, true)) {
            $reasons[] = 'symbol_not_allowlisted';
        } elseif (in_array($symbol, $blockedSymbols, true)) {
            $reasons[] = 'symbol_blocklisted';
        }

        if (! in_array($marginMode, (array) ($config['allowed_margin_modes'] ?? []), true)) {
            $reasons[] = 'margin_mode_not_allowed';
        }
        if (! in_array($positionMode, (array) ($config['allowed_position_modes'] ?? []), true)) {
            $reasons[] = 'position_mode_not_allowed';
        }

        $minConfidence = $config['futures_confidence_threshold'] ?? null;
        if ($confidence !== null && $minConfidence !== null && $confidence < (float) $minConfidence) {
            $reasons[] = 'confidence_below_futures_threshold';
        }

        $requiresManualApproval = (($config['require_manual_approval_for_futures'] ?? false) && $source !== 'manual_operator')
            || $source === 'ai'
            || ($config['futures_mode'] ?? 'disabled') === 'review_only';

        return [
            'allow' => $reasons === [],
            'reasons' => $reasons,
            'requires_manual_approval' => $requiresManualApproval,
            'testnet_only' => true,
            'mode' => $config['futures_mode'] ?? 'disabled',
            'readiness' => $readiness,
        ];
    }
}
