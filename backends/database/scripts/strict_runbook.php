<?php

declare(strict_types=1);

use App\Domain\Billing\AutoWithdrawalService;
use App\Http\Controllers\Api\BotController;
use App\Http\Controllers\Api\LiveWalletController;
use App\Jobs\EvaluateBotJob;
use App\Jobs\ExecuteSignalJob;
use App\Jobs\ProcessPayout;
use App\Models\Bot;
use App\Models\ExchangeConnection;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Position;
use App\Models\RiskProfile;
use App\Models\Setting;
use App\Models\Symbol;
use App\Models\Trade;
use App\Models\TradeSignal;
use App\Models\User;
use App\Models\WalletAccount;
use App\Models\WalletLedgerEntry;
use App\Models\WithdrawalRequest;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Support\ExchangeSecretVault;
use App\Support\PaymentGatewayService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
/** @var Kernel $kernel */
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$results = [];
$meta = [];

$record = static function (string $item, bool $pass, array $details = []) use (&$results): void {
    $results[] = [
        'item' => $item,
        'status' => $pass ? 'PASS' : 'FAIL',
        'details' => $details,
    ];
};

$json = static function ($response): array {
    if (! $response) {
        return [];
    }
    if (method_exists($response, 'getData')) {
        return (array) $response->getData(true);
    }
    if (method_exists($response, 'getContent')) {
        $raw = $response->getContent();
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    return is_array($response) ? $response : [];
};

$envBool = static function (mixed $value): bool {
    if (is_bool($value)) {
        return $value;
    }
    if (is_numeric($value)) {
        return ((int) $value) === 1;
    }
    return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
};

try {
    $meta['env'] = [
        'app_env' => (string) config('app.env'),
        'trading_mode' => (string) env('TRADING_MODE', 'paper'),
        'live_trading_enabled' => $envBool(env('LIVE_TRADING_ENABLED', false)),
        'billing_mode' => (string) config('billing.mode', 'sandbox'),
        'metal_broker_mode' => (string) config('services.metal_broker.mode', 'sandbox'),
        'queue_connection' => (string) config('queue.default'),
    ];

    // 1) Create isolated workspace/user
    $workspace = Workspace::create([
        'id' => (string) Str::uuid(),
        'name' => 'Runbook WS ' . now()->format('YmdHis'),
        'timezone' => 'Asia/Colombo',
    ]);

    $user = User::create([
        'id' => (string) Str::uuid(),
        'name' => 'Runbook User',
        'email' => 'runbook+' . now()->timestamp . '@autofinx.local',
        'password' => bcrypt('Runbook#123'),
        'email_verified_at' => now(),
    ]);

    WorkspaceMember::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'owner',
    ]);

    $record('Workspace + user bootstrap', true, [
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    // Trading settings to allow live execution in runbook workspace.
    foreach ([
        'trading_mode' => 'live',
        'live_enabled' => true,
        'venue_environment' => 'live',
    ] as $key => $value) {
        Setting::updateOrCreate(
            ['workspace_id' => $workspace->id, 'group' => 'trading', 'key' => $key],
            ['id' => (string) Str::uuid(), 'value_json' => $value, 'encrypted' => false, 'updated_by' => $user->id],
        );
    }

    // 2) Deposit intent + PayHere webhook credit
    $walletController = app(LiveWalletController::class);
    $depositReq = Request::create('/api/v1/wallet/deposit/intent', 'POST', [
        'workspace_id' => $workspace->id,
        'amount' => 1000,
        'currency' => 'USD',
        'gateway' => 'payhere',
    ]);
    $depositReq->setUserResolver(static fn () => $user);
    $depositResponse = $walletController->depositIntent($depositReq, app(PaymentGatewayService::class));
    $depositPayload = $json($depositResponse);

    $depositRef = (string) ($depositPayload['reference'] ?? '');
    if ($depositRef === '') {
        throw new RuntimeException('Deposit intent did not return reference.');
    }

    $merchantId = 'RUNBOOK_MERCHANT';
    $amount = number_format(1000, 2, '.', '');
    $currency = 'USD';
    $statusCode = '2';
    $merchantSecret = (string) env('PAYHERE_MERCHANT_SECRET', '');
    $sig = '';
    if ($merchantSecret !== '') {
        $localSecret = strtoupper(md5($merchantSecret));
        $sig = strtoupper(md5($merchantId . $depositRef . $amount . $currency . $statusCode . $localSecret));
    }

    $depositWebhookReq = Request::create('/api/v1/wallet/deposit/webhook', 'POST', [
        'merchant_id' => $merchantId,
        'order_id' => $depositRef,
        'payhere_amount' => $amount,
        'payhere_currency' => $currency,
        'status_code' => $statusCode,
        'md5sig' => $sig,
    ]);
    $depositWebhookRes = $walletController->payHereWebhook($depositWebhookReq);
    $depositWebhookPayload = $json($depositWebhookRes);

    $cashWallet = WalletAccount::where('workspace_id', $workspace->id)
        ->where('currency', 'USD')
        ->where('type', 'cash')
        ->first();

    $record(
        'Deposit -> wallet credit',
        ($depositWebhookPayload['ok'] ?? false) === true && $cashWallet && (float) $cashWallet->balance >= 1000,
        [
            'deposit_reference' => $depositRef,
            'wallet_balance_usd' => (float) ($cashWallet->balance ?? 0),
            'webhook_status' => $depositWebhookPayload['status'] ?? null,
        ]
    );

    // 3) Setup live metals bot + connection
    $vault = app(ExchangeSecretVault::class);
    $connection = ExchangeConnection::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'name' => 'Runbook Metals',
        'provider' => 'metal_broker',
        'exchange' => 'metal_broker',
        'api_key_encrypted' => $vault->encrypt('runbook-key'),
        'api_secret_encrypted' => $vault->encrypt('runbook-secret'),
        'passphrase_encrypted' => null,
        'is_enabled' => true,
        'is_active' => true,
        'is_testnet' => true,
        'permissions' => ['trade' => true, 'read' => true],
    ]);

    $risk = RiskProfile::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'name' => 'Runbook Risk',
        'max_daily_loss' => 100000,
        'max_drawdown_pct' => 90,
        'max_open_positions' => 1,
        'max_slippage_pct_default' => 0.5,
        'circuit_breaker_vol_threshold' => 999,
        'cooldown_sec_after_stop' => 0,
        'rules' => [],
    ]);

    $bot = Bot::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'connection_id' => $connection->id,
        'name' => 'Runbook Metals Bot',
        'asset_class' => 'METALS',
        'metal_type' => 'GOLD',
        'asset_code' => 'XAUUSD',
        'quote_currency' => 'USD',
        'provider' => 'metal_broker',
        'exchange' => 'metal_broker',
        'symbol' => 'XAUUSD',
        'interval_sec' => 1,
        'is_live' => true,
        'status' => 'STOPPED',
        'risk_profile_id' => $risk->id,
        'strategy_type' => 'threshold',
        'strategy_params' => [
            'buy_drop_pct' => 0.01,
            'sell_rise_pct' => 0.01,
            'buy_drop_value' => 0,
            'sell_rise_value' => 0,
            'max_slippage_pct' => 0.5,
            'ttl_ms' => 10000,
            'fill_timeout_ms' => 2000,
            'max_retries' => 1,
            'order_notional' => 30,
            'max_spread_pct' => 5,
            'min_profit_after_fees_pct' => 0,
            'fee_pct' => 0.1,
            'exec_policy' => 'SAFE',
        ],
        'allocation_mode' => 'FIXED',
        'trade_allocation_amount' => 100,
        'trade_allocation_currency' => 'USD',
        'allocation_percent' => 10,
    ]);

    $botController = app(BotController::class);
    $startResponse = app()->call([$botController, 'start'], ['bot' => $bot]);
    $startPayload = $json($startResponse);
    $bot->refresh();

    $record('Bot start (live mode guard + provider check)', $bot->status === 'RUNNING', [
        'bot_status' => $bot->status,
        'message' => $startPayload['message'] ?? null,
    ]);

    // 4) Evaluate + execute cycle
    app()->call([new EvaluateBotJob($bot->id), 'handle']);
    app()->call([new EvaluateBotJob($bot->id), 'handle']);
    $signal = TradeSignal::where('bot_id', $bot->id)->latest('created_at')->first();

    if (! $signal) {
        $metals = app(\App\Domain\Market\MetalsMarketDataService::class);
        $quote = $metals->getPrice('XAUUSD', 'USD', 'metal_broker');
        $mid = (float) ($quote['usd']['mid'] ?? 0);
        if ($mid <= 0) {
            throw new RuntimeException('Could not create fallback signal: no metals quote.');
        }

        $signal = TradeSignal::create([
            'id' => (string) Str::uuid(),
            'bot_id' => $bot->id,
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'asset_class' => 'METALS',
            'asset_code' => 'XAUUSD',
            'quote_currency' => 'USD',
            'provider' => 'metal_broker',
            'symbol' => 'XAUUSD',
            'side' => 'BUY',
            'decision_price' => $mid,
            'decision_ts' => now(),
            'max_slippage_pct' => 0.5,
            'ttl_ms' => 10000,
            'fill_timeout_ms' => 2000,
            'exec_policy' => 'SAFE',
            'status' => 'PENDING',
            'trace_id' => (string) Str::uuid(),
            'meta_json' => ['qty' => 0.01, 'source' => 'runbook_fallback'],
        ]);
    }

    app()->call([new ExecuteSignalJob($signal->id), 'handle']);
    $signal->refresh();

    $order = Order::where('bot_id', $bot->id)->latest('created_at')->first();
    $trade = Trade::where('bot_id', $bot->id)->latest('created_at')->first();
    $position = Position::where('bot_id', $bot->id)->latest('updated_at')->first();

    $record(
        'Signal -> order -> reconcile -> trade/position',
        $signal !== null && $order !== null && in_array(strtoupper((string) $order->status), ['FILLED', 'PARTIAL'], true) && $trade !== null,
        [
            'signal_id' => $signal?->id,
            'signal_status' => $signal?->status,
            'order_id' => $order?->id,
            'order_status' => $order?->status,
            'trade_id' => $trade?->id,
            'position_qty' => (float) ($position?->qty ?? $position?->quantity ?? 0),
        ]
    );

    $fillsCount = WalletLedgerEntry::whereIn('type', ['TRADE_BUY', 'TRADE_SELL', 'TRADE_FEE'])
        ->where('reference_id', $order?->id)
        ->count();
    $record('Ledger entries on fill', $fillsCount > 0, [
        'order_id' => $order?->id,
        'ledger_entries' => $fillsCount,
    ]);

    // 5) Slippage guard negative test
    $badSignal = TradeSignal::create([
        'id' => (string) Str::uuid(),
        'bot_id' => $bot->id,
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'asset_class' => 'METALS',
        'asset_code' => 'XAUUSD',
        'quote_currency' => 'USD',
        'provider' => 'metal_broker',
        'symbol' => 'XAUUSD',
        'side' => 'BUY',
        'decision_price' => 1,
        'decision_ts' => now(),
        'max_slippage_pct' => 0.1,
        'ttl_ms' => 10000,
        'fill_timeout_ms' => 1500,
        'exec_policy' => 'SAFE',
        'status' => 'PENDING',
        'trace_id' => (string) Str::uuid(),
        'meta_json' => ['qty' => 0.01],
    ]);
    app()->call([new ExecuteSignalJob($badSignal->id), 'handle']);
    $badSignal->refresh();

    $record('Slippage guard blocks bad fill', $badSignal->status === 'CANCELLED', [
        'signal_id' => $badSignal->id,
        'status' => $badSignal->status,
    ]);

    // 6) Risk block (max open positions = 1 should reject additional BUY when already open)
    $hasOpenPosition = (float) ($position?->qty ?? $position?->quantity ?? 0) > 0;
    if (! $hasOpenPosition) {
        $symbolModel = Symbol::firstOrCreate(
            ['symbol' => 'XAUUSD'],
            ['id' => (string) Str::uuid(), 'type' => 'metals', 'exchange' => 'metal_broker']
        );
        Position::updateOrCreate(
            ['workspace_id' => $workspace->id, 'bot_id' => $bot->id, 'symbol_id' => $symbolModel->id],
            [
                'id' => (string) Str::uuid(),
                'qty' => 0.05,
                'quantity' => 0.05,
                'avg_entry' => 2100,
                'avg_price' => 2100,
                'unrealized_pnl' => 0,
                'unrealized_pl' => 0,
                'realized_pnl' => 0,
                'last_mark_price' => 2100,
                'asset_class' => 'METALS',
                'asset_code' => 'XAUUSD',
                'quote_currency' => 'USD',
                'provider' => 'metal_broker',
            ]
        );
        $hasOpenPosition = true;
    }
    $riskSignal = null;
    if ($hasOpenPosition) {
        $riskSignal = TradeSignal::create([
            'id' => (string) Str::uuid(),
            'bot_id' => $bot->id,
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'asset_class' => 'METALS',
            'asset_code' => 'XAUUSD',
            'quote_currency' => 'USD',
            'provider' => 'metal_broker',
            'symbol' => 'XAUUSD',
            'side' => 'BUY',
            'decision_price' => 2100,
            'decision_ts' => now(),
            'max_slippage_pct' => 5,
            'ttl_ms' => 10000,
            'fill_timeout_ms' => 1500,
            'exec_policy' => 'SAFE',
            'status' => 'PENDING',
            'trace_id' => (string) Str::uuid(),
            'meta_json' => ['qty' => 0.01],
        ]);
        app()->call([new ExecuteSignalJob($riskSignal->id), 'handle']);
        $riskSignal->refresh();
    }

    $record(
        'Risk gate (max open positions)',
        ! $hasOpenPosition || ($riskSignal && $riskSignal->status === 'CANCELLED'),
        [
            'has_open_position' => $hasOpenPosition,
            'risk_signal_id' => $riskSignal?->id,
            'risk_signal_status' => $riskSignal?->status,
        ]
    );

    // 7) Manual withdraw request + webhook completion
    $withdrawReq = Request::create('/api/v1/wallet/withdraw/request', 'POST', [
        'workspace_id' => $workspace->id,
        'amount' => 100,
        'currency' => 'USD',
        'method' => 'bank_transfer',
        'kyc_confirmed' => true,
        'destination_meta' => ['account' => 'RUNBOOK-ACC-001'],
    ]);
    $withdrawReq->setUserResolver(static fn () => $user);
    $withdrawRes = $walletController->withdraw($withdrawReq);
    $withdrawPayload = $json($withdrawRes);
    $withdrawalId = (string) ($withdrawPayload['withdrawal']['id'] ?? '');

    $withdrawWebhookReq = Request::create('/api/v1/wallet/withdraw/webhook', 'POST', [
        'withdrawal_id' => $withdrawalId,
        'status' => 'completed',
        'provider_reference' => 'RUNBOOK-WD-REF',
        'meta' => ['source' => 'strict_runbook'],
    ]);
    $withdrawToken = (string) env('WITHDRAW_WEBHOOK_TOKEN', '');
    if ($withdrawToken !== '') {
        $withdrawWebhookReq->headers->set('X-Withdraw-Token', $withdrawToken);
    }
    $walletController->withdrawWebhook($withdrawWebhookReq);
    $withdrawRow = WithdrawalRequest::find($withdrawalId);

    $record(
        'Withdraw request -> completed',
        $withdrawalId !== '' && $withdrawRow && $withdrawRow->status === 'completed',
        [
            'withdrawal_id' => $withdrawalId,
            'status' => $withdrawRow?->status,
        ]
    );

    // 8) Auto-withdraw schedule run + payout processing
    $scheduleService = app(AutoWithdrawalService::class);
    $schedule = $scheduleService->create([
        'workspace_id' => $workspace->id,
        'name' => 'Runbook Weekly',
        'currency' => 'USD',
        'method' => 'bank_transfer',
        'cadence' => 'weekly',
        'day_of_week' => 5,
        'hour' => 9,
        'minute' => 0,
        'timezone' => 'Asia/Colombo',
        'amount_mode' => 'fixed',
        'amount_value' => 50,
        'reserve_amount' => 0,
        'min_payout_amount' => 10,
        'destination_meta' => ['account' => 'RUNBOOK-AUTO-001'],
        'is_active' => true,
    ], $user->id);

    $scheduleRun = $scheduleService->runOne($schedule, true);
    $autoPaymentId = (string) ($scheduleRun['payment_id'] ?? '');
    if ($autoPaymentId !== '') {
        app()->call([new ProcessPayout($autoPaymentId), 'handle']);
    }
    $autoPayment = $autoPaymentId !== '' ? Payment::find($autoPaymentId) : null;

    $record(
        'Auto-withdraw schedule -> payout',
        $autoPaymentId !== '' && $autoPayment && in_array($autoPayment->status, ['succeeded', 'pending'], true),
        [
            'schedule_id' => $schedule->id,
            'run_status' => $scheduleRun['status'] ?? null,
            'payment_id' => $autoPaymentId,
            'payment_status' => $autoPayment?->status,
        ]
    );
} catch (Throwable $e) {
    $record('Runbook execution fatal', false, [
        'error' => $e->getMessage(),
        'trace' => $e->getFile() . ':' . $e->getLine(),
    ]);
}

$summary = [
    'meta' => $meta,
    'results' => $results,
    'totals' => [
        'pass' => count(array_filter($results, static fn ($r) => $r['status'] === 'PASS')),
        'fail' => count(array_filter($results, static fn ($r) => $r['status'] === 'FAIL')),
    ],
];

echo json_encode($summary, JSON_PRETTY_PRINT) . PHP_EOL;
