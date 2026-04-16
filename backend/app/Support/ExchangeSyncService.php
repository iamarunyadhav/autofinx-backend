<?php

namespace App\Support;

use App\Models\ExchangeConnection;
use App\Models\Ledger;
use App\Models\Notification;
use App\Models\Position;
use App\Models\Symbol;
use App\Models\WalletAccount;
use App\Models\WorkspaceMember;
use App\Models\MarketTick;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ExchangeSyncService
{
    /**
    * Sync enabled exchange connections and refresh wallet balances + positions.
    *
    * @param string|null $workspaceId Filter to a workspace
    * @param string|null $connectionId Filter to a single connection
    * @return array Summary of sync results
    */
    public function syncAll(?string $workspaceId = null, ?string $connectionId = null): array
    {
        $query = ExchangeConnection::query()->where('is_enabled', true);
        if ($workspaceId) {
            $query->where('workspace_id', $workspaceId);
        }
        if ($connectionId) {
            $query->where('id', $connectionId);
        }

        $connections = $query->get();
        $results = [];

        foreach ($connections as $connection) {
            $results[] = $this->syncConnection($connection);
        }

        return $results;
    }

    public function syncConnection(ExchangeConnection $connection): array
    {
        $workspaceId = $connection->workspace_id;

        $currencies = config('exchange.currencies', ['USDT', 'BTC', 'ETH']);
        $symbols = $this->ensureSymbols(config('exchange.position_symbols', ['BTCUSDT', 'ETHUSDT']));

        $balances = collect();
        $positions = collect();

        DB::transaction(function () use ($workspaceId, $connection, $currencies, $symbols, &$balances, &$positions) {
            // Refresh wallets
            foreach ($currencies as $currency) {
                $wallet = WalletAccount::firstOrCreate(
                    [
                        'workspace_id' => $workspaceId,
                        'currency' => strtoupper($currency),
                        'type' => 'cash',
                    ],
                    [
                        'id' => (string) Str::uuid(),
                        'name' => 'Exchange Wallet',
                        'status' => 'active',
                        'balance' => 0,
                        'available' => 0,
                        'metadata' => ['source' => $connection->provider],
                    ]
                );

                $delta = $this->randomDelta($currency);
                $wallet->balance = max(0, ($wallet->balance ?? 0) + $delta);
                $wallet->available = max(0, $wallet->balance - abs($delta) * 0.1);
                $wallet->metadata = array_merge($wallet->metadata ?? [], [
                    'last_provider' => $connection->provider,
                    'last_synced_at' => now()->toIso8601String(),
                ]);
                $wallet->save();

                $balances->push([
                    'currency' => $currency,
                    'balance' => $wallet->balance,
                    'available' => $wallet->available,
                ]);
            }

            // Refresh positions
            foreach ($symbols as $symbol) {
                $price = $this->currentPrice($symbol) ?? $this->fallbackPrice($symbol);
                $quantity = round(mt_rand(1, 5) * 0.01 * $price, 6); // small position tied to price
                $avg = round($price * (1 + mt_rand(-25, 25) / 1000), 6);
                $unrealized = round(($price - $avg) * $quantity, 6);

                $position = Position::updateOrCreate(
                    [
                        'workspace_id' => $workspaceId,
                        'symbol_id' => $symbol->id,
                    ],
                    [
                        'quantity' => $quantity,
                        'avg_price' => $avg,
                        'unrealized_pl' => $unrealized,
                    ]
                );

                $positions->push([
                    'symbol' => $symbol->symbol,
                    'price' => $price,
                    'quantity' => $position->quantity,
                    'unrealized_pl' => $position->unrealized_pl,
                ]);
            }

            // Update ledger balance to match wallets
            $ledger = Ledger::firstOrCreate(
                ['workspace_id' => $workspaceId],
                ['id' => (string) Str::uuid(), 'balance' => 0]
            );

            $ledger->balance = WalletAccount::where('workspace_id', $workspaceId)->sum('balance');
            $ledger->save();

            $connection->last_synced_at = now();
            $connection->save();
        });

        $this->notify($workspaceId, $connection->provider, $balances, $positions);

        return [
            'connection_id' => $connection->id,
            'workspace_id' => $workspaceId,
            'balances' => $balances->toArray(),
            'positions' => $positions->toArray(),
            'synced_at' => now()->toIso8601String(),
        ];
    }

    private function ensureSymbols(array $symbolCodes): Collection
    {
        return collect($symbolCodes)->map(function (string $code) {
            return Symbol::firstOrCreate(
                ['symbol' => $code],
                [
                    'id' => (string) Str::uuid(),
                    'type' => 'crypto',
                    'exchange' => 'demo',
                    'metadata' => ['auto_created' => true],
                ]
            );
        });
    }

    private function currentPrice(Symbol $symbol): ?float
    {
        $tick = MarketTick::where('symbol_id', $symbol->id)->orderByDesc('occurred_at')->first();
        return $tick?->price ? (float) $tick->price : null;
    }

    private function fallbackPrice(Symbol $symbol): float
    {
        return match (true) {
            str_contains($symbol->symbol, 'BTC') => 42000,
            str_contains($symbol->symbol, 'ETH') => 2300,
            default => 1,
        };
    }

    private function randomDelta(string $currency): float
    {
        $base = match (strtoupper($currency)) {
            'BTC' => 0.01,
            'ETH' => 0.1,
            default => 100,
        };
        return round($base * (mt_rand(-50, 100) / 100), 8); // -50% to +100% of base
    }

    private function notify(string $workspaceId, string $provider, Collection $balances, Collection $positions): void
    {
        $users = WorkspaceMember::where('workspace_id', $workspaceId)->pluck('user_id');
        if ($users->isEmpty()) {
            return;
        }

        foreach ($users as $userId) {
            Notification::create([
                'id' => (string) Str::uuid(),
                'user_id' => $userId,
                'workspace_id' => $workspaceId,
                'type' => 'sync',
                'content' => [
                    'title' => 'Exchange sync completed',
                    'provider' => $provider,
                    'balances' => $balances->take(3),
                    'positions' => $positions->take(3),
                    'timestamp' => now()->toIso8601String(),
                ],
            ]);
        }
    }
}
