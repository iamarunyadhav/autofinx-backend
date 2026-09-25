<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AssertsWorkspaceMembership;
use App\Models\AssistantSession;
use App\Models\AuditLog;
use App\Models\BrokerConnection;
use App\Models\ConfigVersion;
use App\Models\ExchangeConnection;
use App\Models\Invoice;
use App\Models\MarketCandle;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Position;
use App\Models\RiskProfile;
use App\Models\Schedule;
use App\Models\Strategy;
use App\Models\StrategyInstance;
use App\Models\StrategyParam;
use App\Models\Subscription;
use App\Models\Trade;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Generic table proxy (the Supabase-shim `/table/{table}` endpoint).
 *
 * Every verb is scoped to workspaces the caller is a real member of (the
 * RatioBotController membership rule): reads/updates/deletes only ever touch
 * rows inside those workspaces, and every inserted row must name one of them.
 * Previously an empty filter set read, updated or deleted EVERY workspace's
 * rows (orders, exchange_connections, payments, ...).
 */
class TableController extends Controller
{
    use AssertsWorkspaceMembership;

    /**
     * Tables with no workspace_id column. market_candles/strategies are
     * shared catalogs: readable, never writable through this proxy.
     * strategy_params is scoped through its strategy instance; workspaces
     * through its own id and is read-only here (WorkspaceController owns
     * workspace/membership changes with its owner/admin rule).
     */
    private const READ_ONLY_SHARED_TABLES = ['market_candles', 'strategies'];

    protected array $map = [
        'assistant_sessions' => AssistantSession::class,
        'audit_logs' => AuditLog::class,
        'broker_connections' => BrokerConnection::class,
        'config_versions' => ConfigVersion::class,
        'exchange_connections' => ExchangeConnection::class,
        'invoices' => Invoice::class,
        'market_candles' => MarketCandle::class,
        'notifications' => Notification::class,
        'orders' => Order::class,
        'payments' => Payment::class,
        'payment_methods' => PaymentMethod::class,
        'positions' => Position::class,
        'risk_profiles' => RiskProfile::class,
        'schedules' => Schedule::class,
        'strategies' => Strategy::class,
        'strategy_instances' => StrategyInstance::class,
        'strategy_params' => StrategyParam::class,
        'subscriptions' => Subscription::class,
        'trades' => Trade::class,
        'workspaces' => Workspace::class,
    ];

    public function handle(Request $request, string $table)
    {
        $modelClass = $this->map[$table] ?? null;
        if (! $modelClass) {
            return response()->json(['error' => 'Table not allowed'], 404);
        }

        $filters = $request->input('filters', []);
        if (is_string($filters)) {
            $filters = json_decode($filters, true) ?? [];
        }

        $order = $request->input('order');
        if (is_string($order)) {
            $order = json_decode($order, true) ?? null;
        }
        $single = $request->boolean('single', false);
        $data = $request->input('data', $request->all());
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            $data = $decoded ?? $data;
        }

        $query = $modelClass::query();
        $memberWorkspaceIds = $this->memberWorkspaceIds($request);
        $isRead = $request->isMethod('get');

        if (in_array($table, self::READ_ONLY_SHARED_TABLES, true)) {
            if (! $isRead) {
                abort(403, 'You do not have access to this workspace.');
            }
        } elseif ($table === 'workspaces') {
            if (! $isRead) {
                abort(403, 'You do not have access to this workspace.');
            }
            $query->whereIn('id', $memberWorkspaceIds);
        } elseif ($table === 'strategy_params') {
            $memberInstanceIds = StrategyInstance::query()
                ->whereIn('workspace_id', $memberWorkspaceIds)
                ->pluck('id')
                ->map(fn ($id) => (string) $id)
                ->all();
            $query->whereIn('instance_id', $memberInstanceIds);
        } else {
            $query->whereIn('workspace_id', $memberWorkspaceIds);
        }

        foreach ($filters as $column => $value) {
            $query->where($column, $value);
        }

        if ($order && isset($order['column'])) {
            $query->orderBy($order['column'], $order['direction'] ?? 'asc');
        }

        if ($request->isMethod('get')) {
            $results = $query->get();
            if ($single) {
                return response()->json($results->first());
            }
            return response()->json($results);
        }

        if ($request->isMethod('post')) {
            $rows = isset($data[0]) ? $data : [$data];
            foreach ($rows as $row) {
                $this->assertRowWritable($request, $table, is_array($row) ? $row : []);
            }
            if (isset($data[0])) {
                $created = [];
                foreach ($data as $row) {
                    $created[] = $modelClass::create($this->withId($row));
                }
                return response()->json($created, 201);
            }
            $record = $modelClass::create($this->withId($data));
            return response()->json($record, 201);
        }

        if ($request->isMethod('put') || $request->isMethod('patch')) {
            // An update may not move rows into a workspace/instance the
            // caller is not a member of.
            if (is_array($data) && (array_key_exists('workspace_id', $data) || array_key_exists('instance_id', $data))) {
                $this->assertRowWritable($request, $table, $data);
            }
            $query->update($data);
            return response()->json($query->get()->first());
        }

        if ($request->isMethod('delete')) {
            $count = $query->delete();
            return response()->json(['deleted' => $count]);
        }

        return response()->json(['error' => 'Method not allowed'], 405);
    }

    /** A written row must belong to a workspace (or instance) the caller is a member of. */
    private function assertRowWritable(Request $request, string $table, array $row): void
    {
        if ($table === 'strategy_params') {
            $instance = StrategyInstance::query()->find((string) ($row['instance_id'] ?? ''));
            $this->assertWorkspaceMembership($request, $instance ? (string) $instance->workspace_id : null);

            return;
        }

        $this->assertWorkspaceMembership($request, isset($row['workspace_id']) ? (string) $row['workspace_id'] : null);
    }

    private function withId(array $data): array
    {
        if (! isset($data['id'])) {
            $data['id'] = (string) Str::uuid();
        }

        return $data;
    }
}
