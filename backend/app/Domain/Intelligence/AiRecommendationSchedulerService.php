<?php

namespace App\Domain\Intelligence;

use App\Jobs\RunAiDecisionCycleJob;
use App\Models\AiTradingConfig;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * AI Recommendation Scheduler Service.
 *
 * Responsible for deciding which workspace/symbol pairs should have an AI
 * decision cycle dispatched, and for enforcing cadence, cooldown, and
 * operational safeguards before dispatching.
 *
 * This service is called by the scheduled artisan command / cron trigger.
 * It does NOT execute trades itself — it dispatches jobs that call
 * AiDecisionCycleService, which is the full supervised decision loop.
 *
 * Safety rules applied here:
 *  - Only dispatch for workspaces with enabled AI trading config.
 *  - Only dispatch for symbols on the allowlist if configured.
 *  - Respect per-symbol cadence from config (schedule_interval_minutes).
 *  - Never dispatch if the kill switch is active (checked inside the job too).
 *  - Honour a global max-dispatch-per-run cap to prevent runaway scheduling.
 *  - Log every dispatch and skip decision for auditability.
 */
class AiRecommendationSchedulerService
{
    /**
     * Maximum number of cycles dispatched per single scheduler run.
     * Prevents runaway job flooding if many workspaces are enabled.
     */
    private const MAX_DISPATCHES_PER_RUN = 50;

    public function __construct(
        private readonly AiTradingStateService $state,
    ) {
    }

    /**
     * Dispatch pending AI decision cycles for all eligible workspace/symbol pairs.
     *
     * @return array{dispatched: int, skipped: int, reasons: array<string, int>}
     */
    public function dispatchEligible(array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $dispatchCount = 0;
        $skipCount = 0;
        $skipReasons = [];

        $configs = AiTradingConfig::query()
            ->where('enabled', true)
            ->get();

        foreach ($configs as $config) {
            if ($dispatchCount >= self::MAX_DISPATCHES_PER_RUN) {
                $skipCount++;
                $skipReasons['max_dispatch_cap_reached'] = ($skipReasons['max_dispatch_cap_reached'] ?? 0) + 1;
                continue;
            }

            $workspaceId = (string) $config->workspace_id;
            $symbols = $this->resolveSymbols($config);

            if (empty($symbols)) {
                $skipCount++;
                $skipReasons['no_symbols_configured'] = ($skipReasons['no_symbols_configured'] ?? 0) + 1;
                continue;
            }

            foreach ($symbols as $symbol) {
                if ($dispatchCount >= self::MAX_DISPATCHES_PER_RUN) {
                    $skipCount++;
                    $skipReasons['max_dispatch_cap_reached'] = ($skipReasons['max_dispatch_cap_reached'] ?? 0) + 1;
                    continue;
                }

                $eligible = $this->isEligible($workspaceId, $symbol, $config->toArray());
                if (! $eligible['eligible']) {
                    $skipCount++;
                    $reason = $eligible['reason'] ?? 'unknown';
                    $skipReasons[$reason] = ($skipReasons[$reason] ?? 0) + 1;
                    Log::info('ai_scheduler.skipped', [
                        'workspace_id' => $workspaceId,
                        'symbol' => $symbol,
                        'reason' => $reason,
                    ]);
                    continue;
                }

                if (! $dryRun) {
                    RunAiDecisionCycleJob::dispatch($workspaceId, $symbol, $config->integration_id ?? null);
                }

                $dispatchCount++;
                Log::info('ai_scheduler.dispatched', [
                    'workspace_id' => $workspaceId,
                    'symbol' => $symbol,
                    'dry_run' => $dryRun,
                ]);
            }
        }

        return [
            'dispatched' => $dispatchCount,
            'skipped' => $skipCount,
            'reasons' => $skipReasons,
            'dry_run' => $dryRun,
        ];
    }

    /**
     * Check whether an AI decision cycle should be dispatched for this
     * workspace/symbol pair right now.
     */
    public function isEligible(string $workspaceId, string $symbol, array $config): array
    {
        if (! (bool) ($config['enabled'] ?? false)) {
            return ['eligible' => false, 'reason' => 'config_disabled'];
        }

        $mode = strtolower((string) ($config['mode'] ?? 'advisor'));
        if (! in_array($mode, ['advisor', 'manual_approval', 'restricted_auto'], true)) {
            return ['eligible' => false, 'reason' => 'unsupported_mode'];
        }

        $stateSnapshot = $this->state->snapshot($workspaceId, $symbol, $config);
        if (! ($stateSnapshot['allowed'] ?? false)) {
            $reason = implode(',', (array) ($stateSnapshot['reason_codes'] ?? [])) ?: 'state_blocked';

            return ['eligible' => false, 'reason' => $reason];
        }

        $cadenceCheck = $this->state->enforceCadence($workspaceId, $symbol, $config, false, null);
        if (! ($cadenceCheck['allowed'] ?? false)) {
            $reason = implode(',', (array) ($cadenceCheck['reason_codes'] ?? [])) ?: 'cadence_blocked';

            return ['eligible' => false, 'reason' => $reason];
        }

        return ['eligible' => true, 'reason' => null];
    }

    // -------------------------------------------------------------------------

    private function resolveSymbols(AiTradingConfig $config): array
    {
        $raw = $config->allowed_symbols ?? [];
        $list = is_array($raw) ? $raw : array_filter(array_map('trim', explode(',', (string) $raw)));

        return array_values(array_map(
            fn ($s) => strtoupper(trim((string) $s)),
            $list
        ));
    }
}
