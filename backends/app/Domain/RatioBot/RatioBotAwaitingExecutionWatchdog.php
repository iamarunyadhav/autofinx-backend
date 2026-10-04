<?php

namespace App\Domain\RatioBot;

use App\Models\Order;
use App\Models\RatioBotConfig;
use App\Models\RatioBotDecision;
use App\Models\RatioBotState;
use Illuminate\Support\Facades\Log;

/**
 * A bot enters AWAITING_EXECUTION right before it submits an order. If the
 * worker dies in between (job timeout, supervisor restart, deploy) nothing
 * ever moved it out again: the evaluator skips it, the decision service
 * HOLDs, and Start/Resume refuse. This resolves such a bot from the order
 * record, which LiveOrderIntentService / FuturesOrderIntentService always
 * create BEFORE calling the exchange:
 *  - no order row: the order never left the app, so the attempt is failed
 *    and the bot returns to its previous trading state;
 *  - a terminal order (filled / rejected / cancelled): its result is applied
 *    the same way a normal order update would;
 *  - an order whose outcome is still unknown: the bot goes to error review
 *    (visible, alerted) instead of hanging silently.
 */
class RatioBotAwaitingExecutionWatchdog
{
    public function __construct(
        private readonly RatioBotStateService $states,
        private readonly RatioBotOrderUpdateService $orderUpdates,
    ) {
    }

    /** @return string|null what was done, null when nothing was due */
    public function check(RatioBotConfig $config): ?string
    {
        $state = RatioBotState::query()->where('ratio_bot_config_id', $config->id)->first();
        if (! $state || $state->position_state !== RatioBotState::STATE_AWAITING_EXECUTION) {
            return null;
        }

        $timeout = max(60, (int) config('services.binance.ratio_bot_awaiting_timeout_seconds', 180));
        $since = $state->awaiting_since ?? $state->updated_at;
        if ($since !== null && $since->gt(now()->subSeconds($timeout))) {
            return null;
        }

        $config->setRelation('state', $state);
        $clientOrderId = (string) ($state->last_active_idempotency_key ?: $state->last_unresolved_client_order_id ?: '');
        $decision = RatioBotDecision::query()
            ->where('ratio_bot_config_id', $config->id)
            ->when($clientOrderId !== '', fn ($query) => $query->where('client_order_id', $clientOrderId))
            ->where('execution_status', RatioBotDecision::EXEC_SUBMITTED)
            ->latest('created_at')
            ->first();

        if (! $decision) {
            $this->states->block($config, 'execution_stuck',
                'Bot was waiting for an order result but no submitted decision could be found. Check the exchange, then Resume.');

            return 'blocked_no_decision';
        }

        $order = $clientOrderId === '' ? null : Order::query()
            ->where('workspace_id', $config->workspace_id)
            ->where(fn ($query) => $query->where('idempotency_key', $clientOrderId)->orWhere('client_order_id', $clientOrderId))
            ->first();

        if (! $order) {
            $this->states->onOrderFailed($config, $decision, 'submission_interrupted_before_order_was_sent');
            Log::warning('[RatioBot] Awaiting execution released: order was never sent', [
                'config_id' => $config->id, 'decision_id' => $decision->id, 'client_order_id' => $clientOrderId,
            ]);

            return 'released_never_sent';
        }

        $status = strtoupper((string) $order->status);
        if (in_array($status, ['FILLED', 'REJECTED', 'FAILED', 'CANCELLED'], true)) {
            $this->orderUpdates->syncFromOrder($order);
            if ($state->fresh()?->position_state !== RatioBotState::STATE_AWAITING_EXECUTION) {
                return 'applied_order_result';
            }
        }

        $this->states->errorReviewRequired($config, $decision, 'execution_stuck',
            "Order {$clientOrderId} is still unresolved ({$status}) after {$timeout}s. Check it on the exchange; the bot stays paused until then.",
            ['order_id' => $order->id, 'exchange_order_id' => $order->exchange_order_id, 'client_order_id' => $clientOrderId]);

        return 'error_review';
    }
}
