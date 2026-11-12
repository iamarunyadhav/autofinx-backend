<?php

namespace App\Http\Controllers\Api;

use App\Domain\QuickTradeManual\ManualFeatureGate;
use App\Domain\QuickTradeManual\ManualPositionPresenter;
use App\Domain\QuickTradeManual\ManualPositionReconciler;
use App\Domain\QuickTradeManual\ManualProtectionService;
use App\Domain\QuickTradeManual\ManualProtectionPairService;
use App\Domain\QuickTradeManual\ManualPositionService;
use App\Domain\QuickTradeManual\Exchange\ManualExchangeGatewayFactory;
use App\Http\Controllers\Concerns\AssertsWorkspaceMembership;
use App\Http\Controllers\Controller;
use App\Models\QuickTradeManualPosition as P;
use App\Models\QuickTradeManualProtectionPair as Pair;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Illuminate\Support\Facades\Cache;

/**
 * Quick Trade "Manual SL/TP" API. Same workspace-membership pattern as RatioBotController
 * (AssertsWorkspaceMembership trait). Everything except status/close/refresh/show is behind
 * the two feature flags (see ManualFeatureGate); close/refresh/show never need a flag so the
 * feature can be switched off without trapping an open position.
 */
class QuickTradeManualController extends Controller
{
    use AssertsWorkspaceMembership;

    public function __construct(
        private readonly ManualFeatureGate $gate,
        private readonly ManualPositionService $positions,
        private readonly ManualPositionReconciler $reconciler,
        private readonly ManualPositionPresenter $presenter,
        private readonly ManualProtectionService $protection,
        private readonly ManualProtectionPairService $pairProtection,
        private readonly ManualExchangeGatewayFactory $gateways,
    ) {
    }

    public function status(Request $request, string $workspace): JsonResponse
    {
        $this->assertWorkspaceMembership($request, $workspace);
        $role = \App\Models\WorkspaceMember::query()->where('workspace_id', $workspace)->where('user_id', $request->user()->id)->value('role');

        return response()->json([
            'server_enabled' => $this->gate->serverEnabled(),
            'workspace_enabled' => $this->gate->workspaceEnabled($workspace),
            'enabled' => $this->gate->enabled($workspace),
            'allow_mainnet' => (bool) config('quick_trade_manual.allow_mainnet', false),
            'can_manage' => in_array((string) $role, ['owner', 'admin'], true),
            'exchange_verification' => 'UNVERIFIED_ON_EXCHANGE',
            'sl_basis' => 'price_movement',
            'allow_no_stop_loss' => (bool) config('quick_trade_manual.allow_no_stop_loss', false),
            'capabilities' => [
                'market_entry' => ['supported' => true],
                'exchange_stop_market' => ['supported' => true, 'verified' => false],
                'exchange_take_profit_market' => ['supported' => true, 'verified' => false],
                'exchange_trailing_stop' => ['supported' => true, 'verified' => false],
                'leverage_before_entry' => ['supported' => true],
                'hedge_mode_positions' => ['supported' => true, 'note' => 'Uses the account mode already set on Binance.'],
                'full_close' => ['supported' => true],
                'limit_entry' => ['supported' => false, 'reason' => 'Manual mode currently guarantees market-entry reconciliation only.'],
                'post_entry_protection_edit' => ['supported' => true, 'verified' => false, 'note' => 'Safe place/verify replacement, then cancel old stop; still requires testnet checklist.'],
                'position_averaging' => ['supported' => false, 'reason' => 'Requires an approved entry-ledger schema and exchange-tested protection resizing.'],
                'margin_mode_change_with_open_position' => ['supported' => false, 'reason' => 'Binance rejects margin-mode changes while quantity or open orders exist.'],
                'exact_cross_liquidation_preview' => ['supported' => false, 'reason' => 'Cross liquidation depends on the complete account portfolio; exchange positionRisk is authoritative after execution.'],
                'pressure_auto_trade' => ['supported' => false, 'reason' => 'Hard-disabled; pressure is informational only.'],
            ],
            'limits' => [
                'no_stop_loss_max_leverage' => (float) config('quick_trade_manual.no_stop_loss_max_leverage'),
                'symbols' => (array) config('quick_trade_manual.symbols'),
                'min_stake' => (float) config('quick_trade_manual.min_stake'),
                'max_stake' => (float) config('quick_trade_manual.max_stake'),
                'max_leverage' => (float) config('quick_trade_manual.max_leverage'),
                'min_sl_pct' => (float) config('quick_trade_manual.min_sl_pct'),
                'max_sl_pct' => (float) config('quick_trade_manual.max_sl_pct'),
                'min_trailing_pct' => (float) config('quick_trade_manual.min_trailing_pct'),
                'max_trailing_pct' => (float) config('quick_trade_manual.max_trailing_pct'),
                'max_trailing_activation_pct' => (float) config('quick_trade_manual.max_trailing_activation_pct'),
                'max_open_positions' => (int) config('quick_trade_manual.max_open_positions'),
                'price_tolerance_pct' => (float) config('quick_trade_manual.price_tolerance_pct'),
            ],
        ]);
    }

    /** Owner/admin only (same narrower gate Future Trade uses for its live switch). */
    public function setEnabled(Request $request, string $workspace): JsonResponse
    {
        $this->assertWorkspaceRole($request, $workspace, ['owner', 'admin']);
        $data = $request->validate(['enabled' => 'required|boolean']);
        if ($data['enabled'] && ! $this->gate->serverEnabled()) {
            return response()->json(['message' => 'feature_disabled: Manual SL/TP is disabled on this server (QUICK_TRADE_MANUAL_ENABLED).'], 403);
        }
        $this->gate->setWorkspaceEnabled($workspace, (bool) $data['enabled']);

        return response()->json(['workspace_enabled' => $this->gate->workspaceEnabled($workspace), 'enabled' => $this->gate->enabled($workspace)]);
    }

    public function preview(Request $request, string $workspace): JsonResponse
    {
        $this->assertWorkspaceMembership($request, $workspace);
        $data = $this->validateOrder($request, false);

        try {
            return response()->json($this->positions->previewFor($workspace, $data));
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }
    }

    public function open(Request $request, string $workspace): JsonResponse
    {
        $this->assertWorkspaceMembership($request, $workspace);
        $data = $this->validateOrder($request, true);
        if (empty($data['idempotency_key']) && is_string($request->header('Idempotency-Key'))) {
            $data['idempotency_key'] = trim((string) $request->header('Idempotency-Key'));
        }

        try {
            $p = $this->positions->open($workspace, $request->user()?->id, $data);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return response()->json($this->presenter->toArray($p), 201);
    }

    public function index(Request $request, string $workspace): JsonResponse
    {
        $this->assertWorkspaceMembership($request, $workspace);
        $scope = $request->query('scope', 'active');
        $q = P::query()->where('workspace_id', $workspace);
        $q = $scope === 'history' ? $q->whereIn('status', P::TERMINAL)->orderByDesc('closed_at') : $q->whereNotIn('status', P::TERMINAL)->orderByDesc('created_at');

        return response()->json([
            'positions' => $q->with('integration:id,display_name')->limit(min(200, max(1, (int) $request->query('limit', 50))))->get()->map(fn (P $p) => $this->presenter->toArray($p))->all(),
        ]);
    }

    public function show(Request $request, string $workspace, string $position): JsonResponse
    {
        $this->assertWorkspaceMembership($request, $workspace);
        $p = $this->find($workspace, $position)->loadMissing('integration:id,display_name');

        return response()->json($this->presenter->toArray($p, $this->presenter->live($p), true));
    }

    /** Force one reconciliation pass against the exchange, then return the fresh position. */
    public function refresh(Request $request, string $workspace, string $position): JsonResponse
    {
        $this->assertWorkspaceMembership($request, $workspace);
        $p = $this->find($workspace, $position);
        try {
            $p = $this->reconciler->reconcileOne($p, 'api');
        } catch (\Throwable $e) {
            return response()->json(['message' => 'exchange_unavailable: '.mb_substr($e->getMessage(), 0, 200)], 503);
        }

        return response()->json($this->presenter->toArray($p->refresh(), $this->presenter->live($p), true));
    }

    public function closePreview(Request $request, string $workspace, string $position): JsonResponse
    {
        $this->assertWorkspaceMembership($request, $workspace);
        $this->find($workspace, $position);
        try {
            return response()->json($this->positions->closePreview($workspace, $position));
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }
    }

    public function close(Request $request, string $workspace, string $position): JsonResponse
    {
        $this->assertWorkspaceMembership($request, $workspace);
        $this->find($workspace, $position);
        try {
            $p = $this->positions->close($workspace, $position, $request->user()?->id);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return response()->json($this->presenter->toArray($p->refresh(), null, true));
    }

    /** Edit the normal exchange-side stop on an open manual position. */
    public function updateStopLoss(Request $request, string $workspace, string $position): JsonResponse
    {
        $this->assertWorkspaceMembership($request, $workspace);
        $p = $this->find($workspace, $position)->loadMissing('integration');
        $data = $request->validate([
            'stop_loss_price' => 'nullable|numeric|gt:0',
            'sl_pct' => 'nullable|numeric|gt:0',
        ]);
        if (array_key_exists('stop_loss_price', $data) === array_key_exists('sl_pct', $data)) {
            return response()->json(['message' => 'Provide exactly one of stop_loss_price or sl_pct.', 'code' => 'invalid_stop_loss_edit'], 422);
        }

        $entry = (float) $p->entry_price;
        $trigger = array_key_exists('stop_loss_price', $data)
            ? (float) $data['stop_loss_price']
            : ($p->isLong() ? $entry * (1 - (float) $data['sl_pct'] / 100) : $entry * (1 + (float) $data['sl_pct'] / 100));
        $pct = $entry > 0 ? abs($entry - $trigger) / $entry * 100 : 0;
        if ($pct < (float) config('quick_trade_manual.min_sl_pct') || $pct > (float) config('quick_trade_manual.max_sl_pct')) {
            return response()->json(['message' => 'Stop-loss percentage is outside the configured limits.', 'code' => 'invalid_stop_loss_edit'], 422);
        }

        $lock = Cache::lock('qtm:pos:'.$p->id, 150);
        if (! $lock->get()) {
            return response()->json(['message' => 'position_update_in_progress: Try again shortly.', 'code' => 'position_update_in_progress'], 409);
        }
        try {
            $result = $this->protection->replaceStopLoss($p->refresh(), $this->gateways->make($p->integration), $trigger);
            if (! $result['ok']) {
                $code = strtok((string) $result['reason'], ':') ?: 'stop_loss_edit_failed';
                return response()->json(['message' => $result['reason'], 'code' => $code], in_array($code, ['position_not_editable', 'old_stop_cancel_unconfirmed'], true) ? 409 : 422);
            }
            $p->update(['sl_pct' => round($pct, 4)]);

            return response()->json($this->presenter->toArray($p->refresh(), null, true));
        } catch (RuntimeException $e) {
            return $this->fail($e);
        } finally {
            $lock->release();
        }
    }

    public function updateProtectionPair(Request $request, string $workspace, string $position, string $pair): JsonResponse
    {
        $this->assertWorkspaceMembership($request, $workspace);
        $p = $this->find($workspace, $position)->loadMissing('integration');
        $row = Pair::query()->where('position_id', $p->id)->whereKey($pair)->firstOrFail();
        $data = $request->validate([
            'tp_mode' => 'nullable|string|in:percent,price', 'tp_value' => 'nullable|numeric|gt:0',
            'sl_mode' => 'nullable|string|in:fixed,trailing', 'sl_input_mode' => 'nullable|string|in:percent,price,distance', 'sl_value' => 'nullable|numeric|gt:0',
        ]);
        $lock = Cache::lock('qtm:pos:'.$p->id, 150);
        if (! $lock->get()) return response()->json(['message' => 'position_update_in_progress: Try again shortly.', 'code' => 'position_update_in_progress'], 409);
        try {
            $result = $this->pairProtection->update($p->refresh(), $row->refresh(), $this->gateways->make($p->integration), $data);
            if (! $result['ok']) return response()->json(['message' => $result['reason'], 'code' => strtok((string) $result['reason'], ':')], 422);
            return response()->json($this->presenter->toArray($p->refresh(), null, true));
        } catch (RuntimeException $e) { return $this->fail($e); }
        finally { $lock->release(); }
    }

    public function cancelProtectionPair(Request $request, string $workspace, string $position, string $pair): JsonResponse
    {
        $this->assertWorkspaceMembership($request, $workspace);
        $p = $this->find($workspace, $position)->loadMissing('integration');
        $row = Pair::query()->where('position_id', $p->id)->whereKey($pair)->firstOrFail();
        $lock = Cache::lock('qtm:pos:'.$p->id, 150);
        if (! $lock->get()) return response()->json(['message' => 'position_update_in_progress: Try again shortly.', 'code' => 'position_update_in_progress'], 409);
        try {
            $result = $this->pairProtection->cancel($p->refresh(), $row->refresh(), $this->gateways->make($p->integration));
            if (! $result['ok']) return response()->json(['message' => $result['reason'], 'code' => strtok((string) $result['reason'], ':')], 409);
            return response()->json($this->presenter->toArray($p->refresh(), null, true));
        } catch (RuntimeException $e) { return $this->fail($e); }
        finally { $lock->release(); }
    }

    // ---------------------------------------------------------------- helpers

    private function validateOrder(Request $request, bool $isOpen): array
    {
        return $request->validate([
            'symbol' => 'required|string|max:32',
            'direction' => 'required|string|in:long,short,buy,sell',
            'stake_amount' => 'required|numeric|gt:0',
            'leverage' => 'required|numeric|min:1',
            // Stop-loss (PRICE-MOVEMENT %, not margin %) is required unless the explicit per-trade
            // no_stop_loss opt-in is sent; the service enforces that rule, never "missing = no stop".
            'sl_pct' => 'nullable|numeric|gt:0',
            'no_stop_loss' => 'nullable|boolean',
            'no_stop_loss_ack' => 'nullable|boolean',
            'tp_pct' => 'nullable|numeric|gt:0',
            'trailing_pct' => 'nullable|numeric|gt:0',
            'trailing_activation_pct' => 'nullable|numeric|gte:0',
            'protection_pairs' => 'sometimes|array|max:20',
            'protection_pairs.*' => 'array',
            'protection_pairs.*.tp_mode' => 'nullable|string|in:percent,price',
            'protection_pairs.*.tp_value' => 'nullable|numeric|gt:0',
            'protection_pairs.*.sl_mode' => 'nullable|string|in:fixed,trailing',
            'protection_pairs.*.sl_input_mode' => 'nullable|string|in:percent,price,distance',
            'protection_pairs.*.sl_value' => 'nullable|numeric|gt:0',
            'integration_id' => 'nullable|uuid',
            'reference_price' => 'nullable|numeric|gt:0',
            'idempotency_key' => $isOpen ? 'nullable|string|max:120' : 'nullable',
            // The second, separate confirmation step in the UI.
            'confirm' => $isOpen ? 'required|accepted' : 'nullable',
        ]);
    }

    private function find(string $workspace, string $position): P
    {
        try {
            return P::query()->where('workspace_id', $workspace)->whereKey($position)->firstOrFail();
        } catch (ModelNotFoundException) {
            abort(404, 'Manual position not found.');
        }
    }

    private function fail(RuntimeException $e): JsonResponse
    {
        $message = $e->getMessage();
        $code = strtok($message, ':') ?: 'error';
        $status = match (true) {
            in_array($code, ['feature_disabled', 'mainnet_blocked', 'no_stop_loss_disabled'], true) => 403,
            in_array($code, ['duplicate_manual_position', 'open_in_progress', 'price_moved', 'time_based_position_open', 'max_open_positions', 'not_closable'], true) => 409,
            in_array($code, ['exchange_unavailable', 'integration_missing'], true) => 503,
            default => 422,
        };

        return response()->json(['message' => $message, 'code' => $code], $status);
    }
}
