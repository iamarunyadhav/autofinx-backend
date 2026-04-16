<?php

namespace App\Console\Commands;

use App\Models\Bot;
use App\Models\ExchangeConnection;
use App\Models\Order;
use App\Models\TradeSignal;
use App\Models\WalletAccount;
use Illuminate\Console\Command;

class TradingHealthCheckCommand extends Command
{
    protected $signature = 'trading:health-check {--workspace= : Workspace UUID} {--json : Output as JSON}';

    protected $description = 'Quick production readiness checks for trading, reconciliation, and wallet rails.';

    public function handle(): int
    {
        $workspaceId = (string) $this->option('workspace');

        $bots = Bot::query();
        $signals = TradeSignal::query();
        $orders = Order::query();
        $wallets = WalletAccount::query();
        $connections = ExchangeConnection::where('is_active', true);

        if ($workspaceId !== '') {
            $bots->where('workspace_id', $workspaceId);
            $signals->where('workspace_id', $workspaceId);
            $orders->where('workspace_id', $workspaceId);
            $wallets->where('workspace_id', $workspaceId);
            $connections->where('workspace_id', $workspaceId);
        }

        $result = [
            'workspace_id' => $workspaceId !== '' ? $workspaceId : null,
            'checks' => [
                'running_bots' => (clone $bots)->where('status', 'RUNNING')->count(),
                'live_running_bots' => (clone $bots)->where('status', 'RUNNING')->where('is_live', true)->count(),
                'pending_signals' => (clone $signals)->where('status', 'PENDING')->count(),
                'open_orders' => (clone $orders)->whereIn('status', ['NEW', 'PARTIAL'])->count(),
                'cash_wallet_accounts' => (clone $wallets)->where('type', 'cash')->count(),
                'provider_wallet_accounts' => (clone $wallets)->where('type', 'exchange')->count(),
                'active_connections' => (clone $connections)->count(),
                'active_binance_connections' => (clone $connections)->where('provider', 'binance')->count(),
                'active_metals_connections' => (clone $connections)->where('provider', 'metal_broker')->count(),
            ],
            'ts' => now()->toIso8601String(),
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT));
        } else {
            $this->table(
                ['Metric', 'Value'],
                collect($result['checks'])
                    ->map(fn ($value, $key) => [str_replace('_', ' ', (string) $key), (string) $value])
                    ->values()
                    ->all()
            );
        }

        return self::SUCCESS;
    }
}
