<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

class TableController extends Controller
{
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
            $query->update($data);
            return response()->json($query->get()->first());
        }

        if ($request->isMethod('delete')) {
            $count = $query->delete();
            return response()->json(['deleted' => $count]);
        }

        return response()->json(['error' => 'Method not allowed'], 405);
    }

    private function withId(array $data): array
    {
        if (! isset($data['id'])) {
            $data['id'] = (string) Str::uuid();
        }

        return $data;
    }
}
