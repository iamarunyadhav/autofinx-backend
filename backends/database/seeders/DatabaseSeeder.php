<?php

namespace Database\Seeders;

use App\Models\ConfigVersion;
use App\Models\Ledger;
use App\Models\LedgerTransaction;
use App\Models\WalletAccount;
use App\Models\RiskProfile;
use App\Models\Schedule;
use App\Models\Strategy;
use App\Models\StrategyInstance;
use App\Models\StrategyParam;
use App\Models\Symbol;
use App\Models\User;
use App\Models\PortfolioSnapshot;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Models\Guardrails;
use App\Models\RoutingPolicy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $roles = ['owner', 'admin', 'trader', 'viewer', 'botservice'];
        foreach ($roles as $role) {
            Role::firstOrCreate(['name' => $role]);
        }
        $permissions = ['manage_workspaces', 'trade', 'view', 'billing', 'configure'];
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        $user = User::firstOrCreate(
            ['email' => 'admin@autofinx.local'],
            [
                'name' => 'Demo Admin',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
            ]
        );
        $user->syncRoles(['owner']);

        $trader = User::firstOrCreate(
            ['email' => 'trader@autofinx.local'],
            [
                'name' => 'Demo Trader',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
            ]
        );
        $trader->syncRoles(['trader']);

        $viewer = User::firstOrCreate(
            ['email' => 'viewer@autofinx.local'],
            [
                'name' => 'Demo Viewer',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
            ]
        );
        $viewer->syncRoles(['viewer']);

        $workspace = Workspace::firstOrCreate(
            ['name' => 'Demo Workspace'],
            [
                'id' => (string) Str::uuid(),
                'timezone' => 'Asia/Colombo',
            ]
        );

        WorkspaceMember::firstOrCreate(
            ['workspace_id' => $workspace->id, 'user_id' => $user->id],
            ['id' => (string) Str::uuid(), 'role' => 'owner']
        );
        WorkspaceMember::firstOrCreate(
            ['workspace_id' => $workspace->id, 'user_id' => $trader->id],
            ['id' => (string) Str::uuid(), 'role' => 'trader']
        );
        WorkspaceMember::firstOrCreate(
            ['workspace_id' => $workspace->id, 'user_id' => $viewer->id],
            ['id' => (string) Str::uuid(), 'role' => 'viewer']
        );

        $strategies = [
            ['name' => 'MA Crossover', 'slug' => 'ma-crossover', 'category' => 'trend', 'params_schema' => ['fast' => 9, 'slow' => 21, 'rsi' => 40]],
            ['name' => 'Bollinger Mean Reversion', 'slug' => 'bollinger-mean-reversion', 'category' => 'mean', 'params_schema' => ['period' => 20, 'deviation' => 2]],
            ['name' => 'Grid Trading', 'slug' => 'grid-trading', 'category' => 'grid', 'params_schema' => ['levels' => 5, 'spread' => 0.5]],
        ];

        foreach ($strategies as $strategyData) {
            $strategy = Strategy::firstOrCreate(['slug' => $strategyData['slug']], $strategyData + ['id' => (string) Str::uuid()]);
            $instance = StrategyInstance::firstOrCreate([
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspace->id,
                'strategy_id' => $strategy->id,
            ], [
                'name' => "{$strategy->name} Demo",
                'mode' => 'paper',
                'status' => 'running',
            ]);

            foreach ($strategyData['params_schema'] as $key => $value) {
                StrategyParam::updateOrCreate(
                    ['instance_id' => $instance->id, 'key' => $key],
                    ['value' => $value, 'id' => (string) Str::uuid()]
                );
            }
        }

        $symbols = [
            ['symbol' => 'BTCUSDT', 'type' => 'crypto'],
            ['symbol' => 'ETHUSDT', 'type' => 'crypto'],
            ['symbol' => 'USDT', 'type' => 'stablecoin'],
            ['symbol' => 'AAPL', 'type' => 'equity'],
        ];

        foreach ($symbols as $symbol) {
            Symbol::create($symbol + ['id' => (string) Str::uuid()]);
        }

        Schedule::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'name' => 'Default 24/7',
            'window_json' => ['days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], 'start' => '00:00', 'end' => '23:59'],
            'interval_ms' => 60000,
            'cooldown_s' => 30,
            'max_trades_json' => ['per_hour' => 3, 'per_day' => 20],
            'enabled' => true,
        ]);
        Schedule::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'name' => 'Day Session',
            'window_json' => ['days' => ['mon', 'tue', 'wed', 'thu', 'fri'], 'start' => '09:00', 'end' => '17:00'],
            'interval_ms' => 60000,
            'cooldown_s' => 60,
            'max_trades_json' => ['per_hour' => 5, 'per_day' => 30],
            'enabled' => true,
        ]);
        Schedule::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'name' => 'Midnight Window',
            'window_json' => ['days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], 'start' => '22:00', 'end' => '02:00'],
            'interval_ms' => 300000,
            'cooldown_s' => 120,
            'max_trades_json' => ['per_hour' => 2, 'per_day' => 10],
            'enabled' => true,
        ]);

        RiskProfile::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'name' => 'Balanced',
            'rules' => [
                'max_position_percent' => 20,
                'max_trade_size_percent' => 10,
                'daily_loss_limit_percent' => 5,
                'drawdown_kill_percent' => 10,
                'slippage_tolerance_percent' => 0.5,
            ],
        ]);
        RiskProfile::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'name' => 'Conservative',
            'rules' => [
                'max_position_percent' => 10,
                'max_trade_size_percent' => 5,
                'daily_loss_limit_percent' => 3,
                'drawdown_kill_percent' => 6,
                'slippage_tolerance_percent' => 0.3,
            ],
        ]);

        ConfigVersion::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'config_key' => 'feature_flags',
            'new_value' => ['tax' => false, 'kyc' => false, 'custody' => false],
            'actor_id' => $user->id,
        ]);

        $ledger = Ledger::firstOrCreate(
            ['workspace_id' => $workspace->id],
            [
                'id' => (string) Str::uuid(),
                'balance' => 100000,
                'meta' => ['currency' => 'USD'],
            ]
        );

        $wallet = WalletAccount::firstOrCreate(
            ['workspace_id' => $workspace->id, 'currency' => 'USD', 'type' => 'cash'],
            [
                'id' => (string) Str::uuid(),
                'name' => 'Demo Wallet',
                'status' => 'active',
                'balance' => 100000,
                'available' => 100000,
                'metadata' => ['demo' => true],
            ]
        );

        LedgerTransaction::firstOrCreate(
            ['idempotency_key' => 'seed-demo-deposit'],
            [
                'id' => (string) Str::uuid(),
                'ledger_id' => $ledger->id,
                'wallet_account_id' => $wallet->id,
                'type' => 'deposit',
                'amount' => 100000,
                'meta' => ['source' => 'seed', 'currency' => 'USD'],
                'occurred_at' => now(),
            ]
        );

        PortfolioSnapshot::firstOrCreate(
            ['workspace_id' => $workspace->id],
            [
                'id' => (string) Str::uuid(),
                'captured_at' => now(),
                'total_value' => 100000,
                'positions' => [],
                'metrics' => ['currency' => 'USD'],
            ]
        );

        Guardrails::firstOrCreate(
            ['workspace_id' => $workspace->id],
            [
                'max_slippage_pct' => 1,
                'max_spread_pct' => 1,
                'max_open_orders' => 20,
                'loss_streak_cooldown_minutes' => 30,
                'max_daily_loss_pct' => 5,
                'trailing_stop_pct' => 3,
                'take_profit_pct' => 5,
                'volatility_block_pct' => 10,
                'circuit_breaker_failures' => 3,
                'updated_by' => $user->id,
            ]
        );

        RoutingPolicy::firstOrCreate(
            ['workspace_id' => $workspace->id],
            [
                'id' => (string) Str::uuid(),
                'mode' => 'best_price',
                'allow_split_orders' => false,
                'max_venues_per_order' => 1,
                'min_balance_usd' => 50,
                'enabled' => true,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]
        );

        $this->call(PepperstoneInstrumentSeeder::class);
    }
}
