<?php

namespace App\Console\Commands;

use App\Domain\RatioBot\RatioBotAwaitingExecutionWatchdog;
use App\Domain\RatioBot\RatioBotExchangeStopOrderService;
use App\Domain\RatioBot\RatioBotFundingCostService;
use App\Domain\Trading\BinanceOrderSyncService;
use App\Domain\Trading\FuturesConfigService;
use App\Domain\Trading\FuturesPositionSyncService;
use App\Models\Integration;
use App\Models\Order;
use App\Models\RatioBotConfig;
use App\Models\RatioBotEvent;
use App\Models\RatioBotReconciliationLog;
use App\Models\RatioBotState;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RatioBotsReconcileCommand extends Command
{
    protected $signature = 'ratio-bots:reconcile
        {--bot= : Reconcile one ratio bot config id}
        {--limit=50 : Maximum bots to reconcile}';

    protected $description = 'Reconcile ratio bot open orders, positions, and exchange state.';

    public function handle(
        BinanceOrderSyncService $spotSync,
        FuturesPositionSyncService $futuresSync,
        FuturesConfigService $futuresConfigs,
        RatioBotFundingCostService $fundingCost,
        RatioBotExchangeStopOrderService $stopOrders,
        RatioBotAwaitingExecutionWatchdog $watchdog,
    ): int {
        $botId = trim((string) $this->option('bot'));
        $limit = max(1, min(500, (int) $this->option('limit')));

        $configs = RatioBotConfig::query()
            // Running bots, plus disabled ones still waiting on an order
            // result: errorReviewRequired()/block() disable the bot, and
            // those are exactly the bots whose orders need resolving. Other
            // disabled bots place no orders and are skipped. --bot= still
            // reconciles one specific bot regardless of state.
            ->when($botId === '', fn ($query) => $query->where(fn ($q) => $q->where('enabled', true)
                ->orWhereHas('state', fn ($state) => $state->whereIn('position_state', [
                    RatioBotState::STATE_AWAITING_EXECUTION,
                    RatioBotState::STATE_ERROR_REVIEW,
                ]))))
            ->when($botId !== '', fn ($query) => $query->whereKey($botId))
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        $futuresIntegrations = $this->prefetchFuturesIntegrations($configs);

        foreach ($configs as $config) {
            try {
                $snapshot = $config->marketType() === 'futures'
                    ? $this->reconcileFutures($config, $futuresSync, $futuresConfigs, $futuresIntegrations, $fundingCost, $stopOrders)
                    : $this->reconcileSpot($config, $spotSync);

                RatioBotReconciliationLog::create([
                    'id' => (string) Str::uuid(),
                    'ratio_bot_config_id' => $config->id,
                    'market_type' => $config->marketType(),
                    'status' => 'ok',
                    'detail' => 'reconciliation_completed',
                    'snapshot' => $snapshot,
                    'occurred_at' => now(),
                ]);

                RatioBotEvent::create([
                    'ratio_bot_config_id' => $config->id,
                    'event_type' => RatioBotEvent::EVENT_RECONCILIATION_RUN,
                    'context' => $snapshot,
                    'occurred_at' => now(),
                ]);
            } catch (\Throwable $e) {
                RatioBotReconciliationLog::create([
                    'id' => (string) Str::uuid(),
                    'ratio_bot_config_id' => $config->id,
                    'market_type' => $config->marketType(),
                    'status' => 'failed',
                    'detail' => $e->getMessage(),
                    'snapshot' => ['symbol' => $config->normalizedSymbol()],
                    'occurred_at' => now(),
                ]);

                $this->warn(sprintf('Reconcile failed for %s: %s', $config->normalizedSymbol(), $e->getMessage()));
            }

            // Runs even when the sync above failed: a bot stuck waiting on an
            // order result must always end up resolved or visibly in review.
            try {
                if ($action = $watchdog->check($config)) {
                    $this->info(sprintf('Awaiting-execution watchdog for %s: %s', $config->normalizedSymbol(), $action));
                }
            } catch (\Throwable $e) {
                $this->warn(sprintf('Awaiting-execution watchdog failed for %s: %s', $config->normalizedSymbol(), $e->getMessage()));
            }
        }

        return self::SUCCESS;
    }

    private function reconcileSpot(RatioBotConfig $config, BinanceOrderSyncService $spotSync): array
    {
        $synced = $spotSync->syncForWorkspace((string) $config->workspace_id, $config->normalizedSymbol(), null, 25);

        return [
            'symbol' => $config->normalizedSymbol(),
            'orders_synced' => $synced,
            'market_type' => 'spot',
        ];
    }

    private function reconcileFutures(
        RatioBotConfig $config,
        FuturesPositionSyncService $futuresSync,
        FuturesConfigService $futuresConfigs,
        array $prefetchedIntegrations,
        RatioBotFundingCostService $fundingCost,
        RatioBotExchangeStopOrderService $stopOrders,
    ): array {
        $integration = $prefetchedIntegrations[(string) $config->id] ?? null;
        if (! $integration) {
            throw new \RuntimeException('No active Binance integration found for workspace.');
        }

        $workspaceConfig = $futuresConfigs->effective((string) $config->workspace_id);
        $result = $futuresSync->sync((string) $config->workspace_id, $integration, $workspaceConfig);

        // Part C.2/C.1 -- best-effort, never let a failure here break the
        // position/order sync above, which is the reconcile's primary job.
        try {
            $fundingCost->accumulate($config, $integration, $workspaceConfig);
        } catch (\Throwable $e) {
            $this->warn(sprintf('Funding cost accumulation failed for %s: %s', $config->normalizedSymbol(), $e->getMessage()));
        }

        try {
            $this->detectStopOrderSlippage($config);
        } catch (\Throwable $e) {
            $this->warn(sprintf('Stop-order slippage check failed for %s: %s', $config->normalizedSymbol(), $e->getMessage()));
        }

        return [
            'symbol' => $config->normalizedSymbol(),
            'market_type' => 'futures',
            'sync' => $result,
        ];
    }

    /**
     * Part C.1 flash-move honesty: a stop order can still fill worse than
     * its stopPrice on real slippage. This does not prevent that -- it only
     * measures and logs it, using the same open-orders sync
     * FuturesPositionSyncService::sync() just wrote to the `orders` table,
     * so this never issues its own extra exchange call.
     */
    private function detectStopOrderSlippage(RatioBotConfig $config): void
    {
        $state = $config->state;
        $clientOrderId = $state?->futures_stop_client_order_id;
        if (! $state || ! $clientOrderId) {
            return;
        }

        $order = Order::query()->where('client_order_id', $clientOrderId)->first();
        if (! $order || strtoupper((string) $order->status) !== 'FILLED') {
            return;
        }

        $stopPrice = (float) ($state->futures_stop_price ?? 0);
        $fillPrice = (float) ($order->avg_fill_price ?: $order->price ?: 0);
        if ($stopPrice <= 0 || $fillPrice <= 0) {
            return;
        }

        $slippagePct = abs($fillPrice - $stopPrice) / $stopPrice * 100;
        if ($slippagePct > 0.25) {
            RatioBotEvent::create([
                'ratio_bot_config_id' => $config->id,
                'event_type' => RatioBotEvent::EVENT_STOP_ORDER_SLIPPAGE_DETECTED,
                'context' => [
                    'stop_price' => $stopPrice,
                    'fill_price' => $fillPrice,
                    'slippage_pct' => round($slippagePct, 4),
                    'client_order_id' => $clientOrderId,
                ],
                'occurred_at' => now(),
            ]);
        }

        // The stop order is now filled (position closed via the exchange
        // itself, not via our own app logic) -- clear the tracker so a
        // future position doesn't inherit a stale reference.
        $state->update([
            'futures_stop_order_id' => null,
            'futures_stop_client_order_id' => null,
            'futures_stop_price' => null,
            'futures_stop_basis_price' => null,
            'futures_stop_basis_qty' => null,
        ]);
    }

    /**
     * Resolves the same "active binance integration" every futures config
     * would otherwise look up individually (up to 500 configs x 1 query
     * each, every 5 minutes) into a handful of batched queries — one per
     * distinct workspace among this run's futures configs, instead of one
     * per config.
     *
     * @param  \Illuminate\Support\Collection<int, RatioBotConfig>  $configs
     * @return array<string, Integration|null> keyed by ratio_bot_config_id
     */
    private function prefetchFuturesIntegrations($configs): array
    {
        $futuresConfigs = $configs->filter(fn (RatioBotConfig $c) => $c->marketType() === 'futures');
        if ($futuresConfigs->isEmpty()) {
            return [];
        }

        $byWorkspace = Integration::query()
            ->whereIn('workspace_id', $futuresConfigs->pluck('workspace_id')->unique()->values())
            ->where('provider_key', 'binance')
            ->where('status', 'active')
            ->orderByDesc('updated_at')
            ->get()
            ->groupBy(fn (Integration $integration) => (string) $integration->workspace_id);

        $resolved = [];
        foreach ($futuresConfigs as $config) {
            $candidates = $byWorkspace->get((string) $config->workspace_id, collect());
            $integration = $config->integration_id
                ? $candidates->firstWhere('id', $config->integration_id)
                : $candidates->first();

            $resolved[(string) $config->id] = $integration;
        }

        return $resolved;
    }
}
