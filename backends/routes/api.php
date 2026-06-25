<?php

use App\Http\Controllers\Api\AssistantController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\ConfigController;
use App\Http\Controllers\Api\ConnectionController;
use App\Http\Controllers\Api\MarketController;
use App\Http\Controllers\Api\OpsController;
use App\Http\Controllers\Api\PortfolioController;
use App\Http\Controllers\Api\PortfolioIntelligenceController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\StrategyController;
use App\Http\Controllers\Api\TableController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\TradingController;
use App\Http\Controllers\Api\TwoFactorController;
use App\Http\Controllers\Api\WorkspaceController;
use App\Http\Controllers\Api\StakingController;
use App\Http\Controllers\Api\StreamController;
use App\Http\Controllers\Api\ArbitrageController;
use App\Http\Controllers\Api\ProviderWebhookController;
use App\Http\Controllers\Api\PayoutController;
use App\Http\Controllers\Api\VenueController;
use App\Http\Controllers\Api\LivePromotionController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\SignalController;
use App\Http\Controllers\Api\CopyTradingController;
use App\Http\Controllers\Api\ApiTokenController;
use App\Http\Controllers\Api\WebhookConfigController;
use App\Http\Controllers\Api\RegimeController;
use App\Http\Controllers\Api\IntegrationController;
use App\Http\Controllers\Api\ModuleSettingsController;
use App\Http\Controllers\Api\ProfitCenterController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AiTradingController;
use App\Http\Controllers\Api\LiveOrderController;
use App\Http\Controllers\Api\LiveExecutionController;
use App\Http\Controllers\Api\RoutingController;
use App\Http\Controllers\Api\GuardrailsController;
use App\Http\Controllers\Api\PortfolioTargetsController;
use App\Http\Controllers\Api\AssistantActionController;
use App\Http\Controllers\Api\ArbitrageOpportunityController;
use App\Http\Controllers\Api\BinanceUserStreamController;
use App\Http\Controllers\Api\FutureTradeUserStreamController;
use App\Http\Controllers\Api\BotController;
use App\Http\Controllers\Api\ExitAutomationController;
use App\Http\Controllers\Api\LiveWalletController;
use App\Http\Controllers\Api\AssetController;
use App\Http\Controllers\Api\MetalsController;
use App\Http\Controllers\Api\ProviderController;
use App\Http\Controllers\Api\QuickTradeController;
use App\Http\Controllers\Api\RobotSessionController;
use App\Http\Controllers\Api\RatioBotController;
use App\Http\Controllers\Api\Fast2XController;
use App\Http\Controllers\Api\FutureTradeBotController;
use App\Http\Controllers\Api\FuturesController;
use App\Http\Controllers\Api\JobsController;
use App\Http\Controllers\Api\SweepController;
use App\Http\Controllers\Api\StrategySchemaController;
use App\Http\Controllers\Api\WithdrawalScheduleController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/register', [AuthController::class, 'register']);
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('billing/webhook/{gateway}', [BillingController::class, 'webhook'])->middleware('throttle:30,1');
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/2fa/setup', [TwoFactorController::class, 'setup']);
        Route::post('auth/2fa/confirm', [TwoFactorController::class, 'confirm']);
        Route::post('auth/2fa/disable', [TwoFactorController::class, 'disable']);
    });
    Route::post('auth/2fa/verify', [TwoFactorController::class, 'confirm']); // backward compatible path
    Route::post('auth/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::get('auth/me', [AuthController::class, 'me'])->middleware('auth:sanctum');
    Route::get('auth', function () {
        return response()->json(['message' => 'Auth endpoints are POST only: /auth/register, /auth/login']);
    });

    // Public endpoints needed by the landing/SPA without auth redirects
    Route::get('market/candles', [MarketController::class, 'candles']);
    Route::get('market/ticks', [MarketController::class, 'ticks']);
    Route::get('market/symbols', [MarketController::class, 'symbols']);
    Route::get('market/price', [MarketController::class, 'price']);
    Route::get('market/fx', [MarketController::class, 'fx']);
    Route::get('assets', [AssetController::class, 'index']);
    Route::get('signals/latest', [SignalController::class, 'latest'])->middleware('api.access:read:signals');
    Route::get('signals/history', [SignalController::class, 'history'])->middleware('api.access:read:signals');
    Route::post('signals/publish', [SignalController::class, 'publish'])->middleware('api.access:write:signals');
    Route::get('strategies/schema', [StrategySchemaController::class, 'index']);
    Route::get('metals/rates', [MetalsController::class, 'rates']);
    Route::post('metals/convert', [MetalsController::class, 'convert']);
    Route::get('venues', [VenueController::class, 'index']);
    Route::post('wallet/payhere/webhook', [LiveWalletController::class, 'payHereWebhook'])->middleware('throttle:60,1');
    Route::post('wallet/deposit/webhook', [LiveWalletController::class, 'payHereWebhook'])->middleware('throttle:60,1');
    Route::post('wallet/stripe/webhook', [LiveWalletController::class, 'stripeWebhook'])->middleware('throttle:120,1');
    Route::post('wallet/deposit/webhook/stripe', [LiveWalletController::class, 'stripeWebhook'])->middleware('throttle:120,1');
    Route::post('wallet/withdraw/webhook', [LiveWalletController::class, 'withdrawWebhook'])->middleware('throttle:60,1');
    Route::post('exchange/binance/user-stream/ingest', [BinanceUserStreamController::class, 'ingest'])->middleware('throttle:120,1');
    Route::post('future-trade/user-stream/ingest', [FutureTradeUserStreamController::class, 'ingest'])->middleware('throttle:240,1');
    Route::post('future-trade/webhook/tradingview', [FutureTradeBotController::class, 'tradingViewWebhook'])->middleware('throttle:120,1');
    Route::post('future-trade/pine-webhook/{workspace}', [FutureTradeBotController::class, 'pineWebhook'])->middleware('throttle:120,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('workspaces', [WorkspaceController::class, 'index']);
        Route::post('workspaces', [WorkspaceController::class, 'store']);
        Route::get('workspaces/{workspace}', [WorkspaceController::class, 'show']);
        Route::put('workspaces/{workspace}', [WorkspaceController::class, 'update']);
        Route::delete('workspaces/{workspace}', [WorkspaceController::class, 'destroy']);
        Route::get('workspaces/{workspace}/members', [WorkspaceController::class, 'members']);
        Route::post('workspaces/{workspace}/members', [WorkspaceController::class, 'addMember']);
        Route::post('workspaces/{workspace}/invite', [WorkspaceController::class, 'invite']);

        Route::apiResource('strategies', StrategyController::class);
        Route::get('workspaces/{workspace}/strategy-instances', [StrategyController::class, 'instances']);
        Route::post('workspaces/{workspace}/strategy-instances', [StrategyController::class, 'createInstance']);
        Route::put('strategy-instances/{instance}', [StrategyController::class, 'updateInstance']);
        Route::delete('strategy-instances/{instance}', [StrategyController::class, 'deleteInstance']);
        Route::post('strategy-instances/{instance}/backtest', [StrategyController::class, 'backtest']);

        Route::get('workspaces/{workspace}/schedules', [ScheduleController::class, 'index']);
        Route::post('workspaces/{workspace}/schedules', [ScheduleController::class, 'store']);
        Route::put('schedules/{schedule}', [ScheduleController::class, 'update']);
        Route::delete('schedules/{schedule}', [ScheduleController::class, 'destroy']);
        Route::post('schedule/simulate', [ScheduleController::class, 'simulate']);

        Route::get('workspaces/{workspace}/connections', [ConnectionController::class, 'index']);
        Route::middleware('require.2fa')->group(function () {
            Route::post('workspaces/{workspace}/connections', [ConnectionController::class, 'store'])->middleware('throttle:20,1');
            Route::put('connections/{connection}', [ConnectionController::class, 'update'])->middleware('throttle:20,1');
            Route::delete('connections/{connection}', [ConnectionController::class, 'destroy'])->middleware('throttle:20,1');
            Route::post('connections/{connection}/test', [ConnectionController::class, 'test'])->middleware('throttle:20,1');
            Route::post('connections/{connection}/toggle', [ConnectionController::class, 'toggle'])->middleware('throttle:20,1');
            Route::post('connections/{connection}/rotate', [ConnectionController::class, 'rotate'])->middleware('throttle:20,1');
        });

        Route::get('workspaces/{workspace}/portfolio', [PortfolioController::class, 'summary']);
        Route::get('workspaces/{workspace}/portfolio/intelligence/config', [PortfolioIntelligenceController::class, 'showConfig']);
        Route::put('workspaces/{workspace}/portfolio/intelligence/config', [PortfolioIntelligenceController::class, 'updateConfig'])->middleware('require.2fa');
        Route::get('workspaces/{workspace}/portfolio/intelligence/context', [PortfolioIntelligenceController::class, 'context']);
        Route::get('workspaces/{workspace}/portfolio/intelligence/ranked-opportunities', [PortfolioIntelligenceController::class, 'rankedOpportunities']);
        Route::get('workspaces/{workspace}/portfolio/intelligence/symbols/{symbol}', [PortfolioIntelligenceController::class, 'symbolScore']);
        Route::get('workspaces/{workspace}/portfolio/intelligence/allocation', [PortfolioIntelligenceController::class, 'allocation']);
        Route::get('workspaces/{workspace}/portfolio/intelligence/risk-budget', [PortfolioIntelligenceController::class, 'riskBudget']);
        Route::get('workspaces/{workspace}/portfolio/intelligence/recommendations', [PortfolioIntelligenceController::class, 'recommendations']);
        Route::post('workspaces/{workspace}/portfolio/intelligence/run', [PortfolioIntelligenceController::class, 'run'])->middleware('require.2fa');
        Route::get('workspaces/{workspace}/portfolio/intelligence/runs', [PortfolioIntelligenceController::class, 'runs']);
        Route::get('workspaces/{workspace}/portfolio/intelligence/latest', [PortfolioIntelligenceController::class, 'latest']);
        Route::get('workspaces/{workspace}/orders', [TradingController::class, 'orders']);
        Route::post('orders', [TradingController::class, 'placeOrder'])->middleware('require.2fa');
        Route::get('workspaces/{workspace}/trades', [TradingController::class, 'trades']);
        Route::get('workspaces/{workspace}/positions', [TradingController::class, 'positions']);
        Route::get('workspaces/{workspace}/ledger', [TradingController::class, 'ledger']);
        Route::get('workspaces/{workspace}/ledger/export.csv', [TradingController::class, 'exportLedger']);
        Route::get('quick-trade/metadata', [QuickTradeController::class, 'metadata']);
        Route::get('quick-trade/chart', [QuickTradeController::class, 'chart']);
        Route::get('quick-trade/candle-feed', [QuickTradeController::class, 'candleFeed']);
        Route::get('quick-trade/technical-summary', [QuickTradeController::class, 'technicalSummary']);
        Route::post('quick-trade/place', [QuickTradeController::class, 'place']);
        Route::post('quick-trade/settle-due', [QuickTradeController::class, 'settleDue']);
        Route::get('workspaces/{workspace}/quick-trades/active', [QuickTradeController::class, 'active']);
        Route::get('workspaces/{workspace}/quick-trades/history', [QuickTradeController::class, 'history']);
        Route::get('workspaces/{workspace}/robot-sessions', [RobotSessionController::class, 'index']);
        Route::post('workspaces/{workspace}/robot-sessions', [RobotSessionController::class, 'store']);
        Route::patch('robot-sessions/{robotSession}', [RobotSessionController::class, 'update']);
        Route::delete('robot-sessions/{robotSession}', [RobotSessionController::class, 'destroy']);
        Route::post('robot-sessions/{robotSession}/reset', [RobotSessionController::class, 'reset']);
        Route::post('robot-sessions/{robotSession}/reset-daily-loss', [RobotSessionController::class, 'resetDailyLoss']);
        Route::post('robot-sessions/{robotSession}/start', [RobotSessionController::class, 'start']);
        Route::post('robot-sessions/{robotSession}/pause', [RobotSessionController::class, 'pause']);
        Route::post('robot-sessions/{robotSession}/stop', [RobotSessionController::class, 'stop']);
        Route::post('robot-sessions/{robotSession}/evaluate', [RobotSessionController::class, 'evaluate']);
        Route::get('robot-sessions/{robotSession}/status', [RobotSessionController::class, 'status']);
        Route::get('robot-sessions/{robotSession}/history', [RobotSessionController::class, 'history']);
        Route::get('robot-sessions/{robotSession}/performance-summary', [RobotSessionController::class, 'performance']);
        Route::get('robot-sessions/{robotSession}/live-stats', [RobotSessionController::class, 'liveStats']);
        Route::post('robot-sessions/{robotSession}/force-close', [RobotSessionController::class, 'forceClose']);
        Route::get('robot-sessions/{robotSession}/candle-feed', [RobotSessionController::class, 'candleFeed']);

        Route::get('workspaces/{workspace}/risk-profiles', [ConfigController::class, 'riskProfiles']);
        Route::post('workspaces/{workspace}/risk-profiles', [ConfigController::class, 'storeRiskProfile']);
        Route::patch('workspaces/{workspace}/risk-profiles/{riskProfile}', [ConfigController::class, 'updateRiskProfile']);
        Route::get('workspaces/{workspace}/ai-trading/config', [AiTradingController::class, 'showConfig']);
        Route::put('workspaces/{workspace}/ai-trading/config', [AiTradingController::class, 'updateConfig'])->middleware('require.2fa');
        Route::post('workspaces/{workspace}/ai-trading/context', [AiTradingController::class, 'buildContext']);
        Route::post('workspaces/{workspace}/ai-trading/proposals', [AiTradingController::class, 'propose'])->middleware('require.2fa');
        Route::post('workspaces/{workspace}/ai-trading/cycles/run', [AiTradingController::class, 'runCycle'])->middleware('require.2fa');
        Route::get('workspaces/{workspace}/ai-trading/decisions', [AiTradingController::class, 'listDecisions']);
        Route::get('workspaces/{workspace}/ai-trading/approvals', [AiTradingController::class, 'pendingApprovals']);
        Route::post('workspaces/{workspace}/ai-trading/approvals/{decisionId}/approve', [AiTradingController::class, 'approveWorkspaceDecision'])->middleware('require.2fa');
        Route::post('workspaces/{workspace}/ai-trading/approvals/{decisionId}/reject', [AiTradingController::class, 'rejectWorkspaceDecision'])->middleware('require.2fa');
        Route::get('workspaces/{workspace}/ai-trading/cycles', [AiTradingController::class, 'listCycles']);
        Route::get('workspaces/{workspace}/ai-trading/state', [AiTradingController::class, 'state']);
        Route::get('workspaces/{workspace}/ai-trading/notifications', [AiTradingController::class, 'notifications']);
        Route::post('workspaces/{workspace}/ai-trading/pause', [AiTradingController::class, 'pause'])->middleware('require.2fa');
        Route::post('workspaces/{workspace}/ai-trading/resume', [AiTradingController::class, 'resume'])->middleware('require.2fa');
        Route::get('workspaces/{workspace}/ai-trading/anomalies', [AiTradingController::class, 'anomalies']);
        Route::get('ai-trading/decisions/{decision}', [AiTradingController::class, 'showDecision']);
        Route::get('ai-trading/decisions/{decision}/events', [AiTradingController::class, 'decisionEvents']);
        Route::post('ai-trading/decisions/{decision}/approve', [AiTradingController::class, 'approveDecision'])->middleware('require.2fa');
        Route::post('ai-trading/decisions/{decision}/reject', [AiTradingController::class, 'rejectDecision'])->middleware('require.2fa');
        Route::get('workspaces/{workspace}/configs', [ConfigController::class, 'configs']);
        Route::post('workspaces/{workspace}/configs', [ConfigController::class, 'storeConfigVersion']);
        Route::post('config/rollback/{versionId}', [ConfigController::class, 'rollback']);
        Route::get('config/global', [ConfigController::class, 'global']);
        Route::patch('config/global', [ConfigController::class, 'updateGlobal']);

        Route::get('billing/plans', [BillingController::class, 'plans']);
        Route::post('billing/subscribe', [BillingController::class, 'subscribe'])->middleware('require.2fa');
        Route::post('billing/cancel', [BillingController::class, 'cancel'])->middleware('require.2fa');
        Route::get('billing/invoices', [BillingController::class, 'invoices']);
        Route::post('wallet/topup/initiate', [BillingController::class, 'topup'])->middleware('require.2fa');
        Route::post('billing/wallet/withdraw/request', [BillingController::class, 'withdraw'])->middleware(['require.2fa','throttle:10,1']);
        Route::get('workspaces/{workspace}/wallet/accounts', [LiveWalletController::class, 'balances']);
        Route::get('workspaces/{workspace}/wallet/exchange-balance', [LiveWalletController::class, 'exchangeBalance']);
        Route::post('wallet/deposit/intent', [LiveWalletController::class, 'depositIntent'])->middleware('require.2fa');
        Route::post('wallet/deposit/initiate', [LiveWalletController::class, 'depositIntent'])->middleware('require.2fa');
        Route::post('wallet/withdrawals', [LiveWalletController::class, 'withdraw'])->middleware(['require.2fa','throttle:10,1']);
        Route::post('wallet/withdraw/request', [LiveWalletController::class, 'withdraw'])->middleware(['require.2fa','throttle:10,1']);
        Route::get('wallet/withdraw/schedules', [WithdrawalScheduleController::class, 'index']);
        Route::post('wallet/withdraw/schedules', [WithdrawalScheduleController::class, 'store'])->middleware(['require.2fa','throttle:20,1']);
        Route::patch('wallet/withdraw/schedules/{schedule}', [WithdrawalScheduleController::class, 'update'])->middleware(['require.2fa','throttle:20,1']);
        Route::delete('wallet/withdraw/schedules/{schedule}', [WithdrawalScheduleController::class, 'destroy'])->middleware(['require.2fa','throttle:20,1']);
        Route::post('wallet/withdraw/schedules/{schedule}/run-now', [WithdrawalScheduleController::class, 'runNow'])->middleware(['require.2fa','throttle:20,1']);
        Route::get('wallet/sweep/rules', [SweepController::class, 'rules']);
        Route::post('wallet/sweep/rules', [SweepController::class, 'storeRule'])->middleware(['require.2fa','throttle:20,1']);
        Route::patch('wallet/sweep/rules/{rule}', [SweepController::class, 'updateRule'])->middleware(['require.2fa','throttle:20,1']);
        Route::delete('wallet/sweep/rules/{rule}', [SweepController::class, 'destroyRule'])->middleware(['require.2fa','throttle:20,1']);
        Route::post('wallet/sweep/run', [SweepController::class, 'run'])->middleware(['require.2fa','throttle:20,1']);
        Route::get('wallet/sweep/runs', [SweepController::class, 'runs']);
        Route::post('provider-webhook/coinbase', [ProviderWebhookController::class, 'coinbase'])->middleware('throttle:30,1');
        Route::get('payouts/{id}', [PayoutController::class, 'show']);
        Route::post('payouts/{id}/retry', [PayoutController::class, 'retry'])->middleware(['require.2fa','throttle:10,1']);

        Route::get('notifications', [NotificationController::class, 'index']);
        Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('notifications/mark-read', [NotificationController::class, 'markRead']);
        Route::post('notifications/mark-all-read', [NotificationController::class, 'markAllRead']);
        Route::post('notifications', [NotificationController::class, 'store']);

        // Live promotion
        Route::get('live/promotion/status', [LivePromotionController::class, 'status']);
        Route::post('live/promotion/request', [LivePromotionController::class, 'request'])->middleware('require.2fa');
        Route::post('live/promotion/approve', [LivePromotionController::class, 'approve'])->middleware('require.2fa');

        // Venue connections
        Route::get('venues/connections', [VenueController::class, 'connections']);
        Route::post('venues/connections', [VenueController::class, 'storeConnection']);
        Route::post('venues/connections/{connection}/toggle', [VenueController::class, 'toggle']);
        Route::post('venues/connections/{connection}/test', [VenueController::class, 'test']);
        Route::post('providers/{provider}/connect', [ProviderController::class, 'connect'])->middleware('require.2fa');
        Route::get('providers/{provider}/status', [ProviderController::class, 'status']);
        Route::get('providers/{provider}/balances', [ProviderController::class, 'balances']);

        Route::post('assistant/chat', [AssistantController::class, 'chat']);
        Route::post('assistant/function-call', [AssistantController::class, 'functionCall']);

        // Settings
        Route::get('settings', [SettingsController::class, 'index']);
        Route::patch('settings', [SettingsController::class, 'update']);

        // Regime
        Route::get('regime/current', [RegimeController::class, 'current']);
        Route::get('regime/history', [RegimeController::class, 'history']);

        // Copy trading
        Route::get('copy-trading/profiles', [CopyTradingController::class, 'profiles']);
        Route::post('copy-trading/profiles', [CopyTradingController::class, 'store']);
        Route::patch('copy-trading/profiles/{profile}', [CopyTradingController::class, 'update']);
        Route::delete('copy-trading/profiles/{profile}', [CopyTradingController::class, 'destroy']);
        Route::get('copy-trading/replications', [CopyTradingController::class, 'replications']);

        // API tokens
        Route::get('api-tokens', [ApiTokenController::class, 'index']);
        Route::post('api-tokens', [ApiTokenController::class, 'store'])->middleware('require.2fa');
        Route::delete('api-tokens/{apiToken}', [ApiTokenController::class, 'destroy'])->middleware('require.2fa');
        Route::post('api-tokens/{apiToken}/rotate', [ApiTokenController::class, 'rotate'])->middleware('require.2fa');

        // Webhooks
        Route::get('signals/webhooks', [WebhookConfigController::class, 'index']);
        Route::post('signals/webhooks', [WebhookConfigController::class, 'store']);
        Route::patch('signals/webhooks/{webhook}', [WebhookConfigController::class, 'update']);
        Route::post('signals/webhooks/{webhook}/test', [WebhookConfigController::class, 'test']);
        Route::get('signals/webhook-deliveries', [WebhookConfigController::class, 'deliveries']);

        // Integrations & modules
        Route::get('integrations', [IntegrationController::class, 'index']);
        Route::post('integrations', [IntegrationController::class, 'store'])->middleware('require.2fa');
        Route::patch('integrations/{integration}', [IntegrationController::class, 'update'])->middleware('require.2fa');
        Route::post('integrations/{integration}/rotate-secrets', [IntegrationController::class, 'rotate'])->middleware('require.2fa');
        Route::post('integrations/{integration}/health', [IntegrationController::class, 'health']);

        Route::get('modules', [ModuleSettingsController::class, 'index']);
        Route::patch('modules/{moduleKey}', [ModuleSettingsController::class, 'update'])->middleware('require.2fa');

        // Profit center
        Route::get('profit/arbitrage/opportunities', [ProfitCenterController::class, 'arbitrage']);
        Route::post('profit/arbitrage/run-once', [ProfitCenterController::class, 'arbitrageRun']);
        Route::get('profit/yield/positions', [ProfitCenterController::class, 'yieldPositions']);
        Route::post('profit/yield/subscribe', [ProfitCenterController::class, 'yieldSubscribe']);
        Route::get('profit/defi/positions', [ProfitCenterController::class, 'defiPositions']);
        Route::post('profit/defi/simulate', [ProfitCenterController::class, 'defiSimulate']);
        Route::get('analytics/overview', [AnalyticsController::class, 'overview']);
        Route::get('analytics/export.csv', [AnalyticsController::class, 'export']);
        Route::get('workspaces/{workspace}/live/orders', [LiveExecutionController::class, 'index']);
        Route::get('workspaces/{workspace}/live/fills', [LiveExecutionController::class, 'fills']);
        Route::get('workspaces/{workspace}/live/execution/events', [LiveExecutionController::class, 'events']);
        Route::get('workspaces/{workspace}/live/execution/anomalies', [LiveExecutionController::class, 'anomalies']);
        Route::get('workspaces/{workspace}/live/execution/health', [LiveExecutionController::class, 'health']);
        Route::post('workspaces/{workspace}/live/execution/sync', [LiveExecutionController::class, 'syncWorkspace'])->middleware('require.2fa');
        Route::get('workspaces/{workspace}/futures/config', [FuturesController::class, 'showConfig']);
        Route::put('workspaces/{workspace}/futures/config', [FuturesController::class, 'updateConfig'])->middleware('require.2fa');
        Route::get('workspaces/{workspace}/futures/readiness', [FuturesController::class, 'readiness']);
        Route::get('workspaces/{workspace}/futures/anomalies', [FuturesController::class, 'anomalies']);
        Route::get('workspaces/{workspace}/futures/orders', [FuturesController::class, 'orders']);
        Route::get('workspaces/{workspace}/futures/positions', [FuturesController::class, 'positions']);
        Route::post('workspaces/{workspace}/futures/evaluate', [FuturesController::class, 'evaluate'])->middleware('require.2fa');
        Route::post('workspaces/{workspace}/futures/orders', [FuturesController::class, 'storeOrder'])->middleware('require.2fa');
        Route::post('workspaces/{workspace}/futures/sync', [FuturesController::class, 'sync'])->middleware('require.2fa');
        Route::get('workspaces/{workspace}/exit-policy', [ExitAutomationController::class, 'showPolicy']);
        Route::put('workspaces/{workspace}/exit-policy', [ExitAutomationController::class, 'updatePolicy'])->middleware('require.2fa');
        Route::get('workspaces/{workspace}/managed-positions', [ExitAutomationController::class, 'managedPositions']);
        Route::get('workspaces/{workspace}/exit-events', [ExitAutomationController::class, 'events']);
        Route::get('live/orders/{order}', [LiveExecutionController::class, 'show']);
        Route::post('live/orders/{order}/reconcile', [LiveExecutionController::class, 'reconcile'])->middleware('require.2fa');
        Route::post('live/orders', [LiveOrderController::class, 'store'])->middleware('require.2fa');
        Route::delete('live/orders/{order}', [LiveOrderController::class, 'destroy'])->middleware('require.2fa');
        Route::get('live/positions/{position}/exit-plan', [ExitAutomationController::class, 'showPlan']);
        Route::post('live/positions/{position}/exit/evaluate', [ExitAutomationController::class, 'evaluate'])->middleware('require.2fa');
        Route::post('live/positions/{position}/exit/arm', [ExitAutomationController::class, 'arm'])->middleware('require.2fa');
        Route::post('live/positions/{position}/exit/pause', [ExitAutomationController::class, 'pause'])->middleware('require.2fa');
        Route::post('live/positions/{position}/exit/resume', [ExitAutomationController::class, 'resume'])->middleware('require.2fa');
        Route::post('live/positions/{position}/exit/flatten', [ExitAutomationController::class, 'flatten'])->middleware('require.2fa');
        Route::get('workspaces/{workspace}/bots', [BotController::class, 'index']);
        Route::post('workspaces/{workspace}/bots', [BotController::class, 'store'])->middleware('require.2fa');
        Route::patch('bots/{bot}', [BotController::class, 'update'])->middleware('require.2fa');
        Route::delete('bots/{bot}', [BotController::class, 'destroy'])->middleware('require.2fa');
        Route::post('bots/{bot}/start', [BotController::class, 'start'])->middleware('require.2fa');
        Route::post('bots/{bot}/pause', [BotController::class, 'pause'])->middleware('require.2fa');
        Route::post('bots/{bot}/stop', [BotController::class, 'stop'])->middleware('require.2fa');
        Route::post('bots/{bot}/emergency-stop', [BotController::class, 'emergencyStop'])->middleware('require.2fa');
        Route::post('bots/{bot}/close-position', [BotController::class, 'closePositionNow'])->middleware('require.2fa');
        Route::get('bots/{bot}/monitor', [BotController::class, 'monitor']);
        Route::get('bots/{bot}/logs', [BotController::class, 'logs']);
        Route::get('bots/{bot}/ai/recommendations', [BotController::class, 'aiRecommendations']);
        Route::post('bots/{bot}/ai/apply-recommendation', [BotController::class, 'applyAiRecommendation'])->middleware('require.2fa');
        Route::post('exchange/connections/{connection}/user-stream/start', [BinanceUserStreamController::class, 'start'])->middleware('require.2fa');
        Route::post('exchange/connections/{connection}/user-stream/keepalive', [BinanceUserStreamController::class, 'keepAlive']);
        Route::post('exchange/connections/{connection}/user-stream/stop', [BinanceUserStreamController::class, 'stop'])->middleware('require.2fa');
        // Smart routing
        Route::get('routing/policy', [RoutingController::class, 'show']);
        Route::patch('routing/policy', [RoutingController::class, 'update'])->middleware('require.2fa');
        // Guardrails
        Route::get('guardrails', [GuardrailsController::class, 'show']);
        Route::patch('guardrails', [GuardrailsController::class, 'update'])->middleware('require.2fa');
        // Portfolio targets / rebalance (config only)
        Route::get('portfolio/targets', [PortfolioTargetsController::class, 'show']);
        Route::patch('portfolio/targets', [PortfolioTargetsController::class, 'update'])->middleware('require.2fa');
        // Arbitrage opportunities listing
        Route::get('arbitrage/opportunities', [ArbitrageOpportunityController::class, 'index']);
        // Assistant actions
        Route::get('assistant/actions', [AssistantActionController::class, 'index']);
        Route::post('assistant/actions/propose', [AssistantActionController::class, 'propose']);
        Route::post('assistant/actions/{action}/approve', [AssistantActionController::class, 'approve'])->middleware('require.2fa');
        Route::post('assistant/actions/{action}/execute', [AssistantActionController::class, 'execute'])->middleware('require.2fa');

        Route::get('ops/health', [OpsController::class, 'health']);
        Route::get('ops/audit-logs', [OpsController::class, 'auditLogs']);
        Route::get('jobs/dashboard', [JobsController::class, 'dashboard']);
        Route::post('jobs/restart-workers', [JobsController::class, 'restartWorkers'])->middleware(['require.2fa','throttle:10,1']);
        Route::post('jobs/failed/retry', [JobsController::class, 'retryFailed'])->middleware(['require.2fa','throttle:20,1']);
        Route::post('jobs/failed/forget', [JobsController::class, 'forgetFailed'])->middleware(['require.2fa','throttle:20,1']);
        Route::post('jobs/pending/clear', [JobsController::class, 'clearPending'])->middleware(['require.2fa','throttle:10,1']);
        Route::post('jobs/pending/prune-stale', [JobsController::class, 'pruneStalePending'])->middleware(['require.2fa','throttle:10,1']);
        Route::post('ops/kill-switch', [OpsController::class, 'killSwitch'])->middleware(['require.2fa','throttle:5,1']);
        Route::post('ops/provider-breaker', [OpsController::class, 'providerBreaker'])->middleware(['require.2fa','throttle:10,1']);
        Route::get('ops/scheduler-runs', [OpsController::class, 'schedulerRuns']);
        Route::post('ops/poll-orders', [OpsController::class, 'pollOrders'])->middleware('throttle:10,1');
        Route::get('ops/provider-health', [OpsController::class, 'providerHealth']);
        Route::get('workspaces/{workspace}/ops/readiness', [OpsController::class, 'workspaceReadiness']);
        Route::post('workspaces/{workspace}/ops/readiness/run', [OpsController::class, 'runWorkspaceReadiness'])->middleware('require.2fa');
        Route::get('workspaces/{workspace}/ops/deployment-readiness', [OpsController::class, 'deploymentReadiness']);
        Route::get('workspaces/{workspace}/ops/health', [OpsController::class, 'workspaceHealth']);
        Route::get('workspaces/{workspace}/ops/readiness/history', [OpsController::class, 'readinessHistory']);
        Route::get('workspaces/{workspace}/ops/trends', [OpsController::class, 'trends']);
        Route::get('workspaces/{workspace}/ops/drift-detections', [OpsController::class, 'driftDetections']);
        Route::get('workspaces/{workspace}/ops/maintenance-confidence', [OpsController::class, 'maintenanceConfidence']);
        Route::get('workspaces/{workspace}/ops/drill-scenarios', [OpsController::class, 'drillScenarios']);
        Route::get('workspaces/{workspace}/ops/drills', [OpsController::class, 'drills']);
        Route::post('workspaces/{workspace}/ops/drills/run', [OpsController::class, 'runDrill'])->middleware('require.2fa');
        Route::get('workspaces/{workspace}/ops/chaos-scenarios', [OpsController::class, 'chaosScenarios']);
        Route::get('workspaces/{workspace}/ops/chaos-simulations', [OpsController::class, 'chaosSimulations']);
        Route::post('workspaces/{workspace}/ops/chaos-simulations/run', [OpsController::class, 'runChaosSimulation'])->middleware('require.2fa');
        Route::get('workspaces/{workspace}/ops/releases', [OpsController::class, 'releases']);
        Route::post('workspaces/{workspace}/ops/releases', [OpsController::class, 'createRelease'])->middleware('require.2fa');
        Route::post('workspaces/{workspace}/ops/releases/{releaseId}/verify', [OpsController::class, 'verifyRelease'])->middleware('require.2fa');
        Route::get('workspaces/{workspace}/ops/rollbacks', [OpsController::class, 'rollbacks']);
        Route::post('workspaces/{workspace}/ops/rollbacks', [OpsController::class, 'createRollback'])->middleware('require.2fa');
        Route::get('workspaces/{workspace}/ops/post-incident-reviews', [OpsController::class, 'postIncidentReviews']);
        Route::post('workspaces/{workspace}/ops/post-incident-reviews', [OpsController::class, 'recordPostIncidentReview'])->middleware('require.2fa');
        Route::get('workspaces/{workspace}/ops/incidents', [OpsController::class, 'incidents']);
        Route::get('workspaces/{workspace}/ops/incidents/{incident}', [OpsController::class, 'incident']);
        Route::post('workspaces/{workspace}/ops/incidents/{incident}/acknowledge', [OpsController::class, 'acknowledgeIncident'])->middleware('require.2fa');
        Route::post('workspaces/{workspace}/ops/incidents/{incident}/resolve', [OpsController::class, 'resolveIncident'])->middleware('require.2fa');
        Route::get('workspaces/{workspace}/ops/runbooks', [OpsController::class, 'runbooks']);
        Route::get('workspaces/{workspace}/ops/runbooks/{key}', [OpsController::class, 'runbook']);
        Route::post('workspaces/{workspace}/ops/actions', [OpsController::class, 'action'])->middleware('require.2fa');
        Route::get('workspaces/{workspace}/arbitrage', [ArbitrageController::class, 'opportunities']);
        Route::post('workspaces/{workspace}/arbitrage/execute', [ArbitrageController::class, 'execute'])->middleware('require.2fa');
        Route::get('workspaces/{workspace}/staking', [StakingController::class, 'index']);
        Route::post('workspaces/{workspace}/staking/simulate', [StakingController::class, 'simulate']);
        Route::post('workspaces/{workspace}/staking/start', [StakingController::class, 'start'])->middleware('require.2fa');
        Route::get('stream/prices', [StreamController::class, 'prices']);

        // ---- Ratio Bot -------------------------------------------------------
        Route::get('workspaces/{workspace}/ratio-bots', [RatioBotController::class, 'index']);
        Route::post('workspaces/{workspace}/ratio-bots', [RatioBotController::class, 'store'])->middleware('require.2fa');
        Route::get('ratio-bots/{ratioBot}', [RatioBotController::class, 'show']);
        Route::put('ratio-bots/{ratioBot}', [RatioBotController::class, 'update'])->middleware('require.2fa');
        Route::delete('ratio-bots/{ratioBot}', [RatioBotController::class, 'destroy'])->middleware('require.2fa');
        Route::post('ratio-bots/{ratioBot}/start', [RatioBotController::class, 'start'])->middleware('require.2fa');
        Route::post('ratio-bots/{ratioBot}/pause', [RatioBotController::class, 'pause'])->middleware('require.2fa');
        Route::post('ratio-bots/{ratioBot}/resume', [RatioBotController::class, 'resume'])->middleware('require.2fa');
        Route::post('ratio-bots/{ratioBot}/stop', [RatioBotController::class, 'stop'])->middleware('require.2fa');
        Route::post('ratio-bots/{ratioBot}/anchor-buy', [RatioBotController::class, 'anchorBuy'])->middleware('require.2fa');
        Route::post('ratio-bots/{ratioBot}/anchor-sell', [RatioBotController::class, 'anchorSell'])->middleware('require.2fa');
        Route::get('ratio-bots/{ratioBot}/state', [RatioBotController::class, 'state']);
        Route::get('ratio-bots/{ratioBot}/status', [RatioBotController::class, 'status']);
        Route::get('ratio-bots/{ratioBot}/ratio', [RatioBotController::class, 'currentRatio']);
        Route::post('ratio-bots/{ratioBot}/evaluate', [RatioBotController::class, 'evaluate'])->middleware('require.2fa');
        Route::post('ratio-bots/{ratioBot}/kill-switch', [RatioBotController::class, 'killSwitch'])->middleware('require.2fa');
        Route::get('ratio-bots/{ratioBot}/decisions', [RatioBotController::class, 'decisions']);
        Route::get('ratio-bots/{ratioBot}/events', [RatioBotController::class, 'events']);
        Route::get('ratio-bots/{ratioBot}/last-signal', [RatioBotController::class, 'lastSignal']);
        Route::get('ratio-bots/{ratioBot}/last-order', [RatioBotController::class, 'lastOrder']);
        Route::get('ratio-bots/{ratioBot}/position', [RatioBotController::class, 'currentPosition']);
        Route::get('ratio-bots/{ratioBot}/pnl-summary', [RatioBotController::class, 'pnlSummary']);
        Route::get('ratio-bots/{ratioBot}/feed-health', [RatioBotController::class, 'feedHealth']);
        Route::get('ratio-bots/{ratioBot}/candles', [RatioBotController::class, 'candles']);
        Route::get('ratio-bots/{ratioBot}/snapshot', [RatioBotController::class, 'snapshot']);

        // ---- Fast2X ---------------------------------------------------------
        Route::get('workspaces/{workspace}/fast2x/status', [Fast2XController::class, 'status']);
        Route::post('workspaces/{workspace}/fast2x/start', [Fast2XController::class, 'start'])->middleware('require.2fa');
        Route::post('workspaces/{workspace}/fast2x/pause', [Fast2XController::class, 'pause'])->middleware('require.2fa');
        Route::post('workspaces/{workspace}/fast2x/stop', [Fast2XController::class, 'stop'])->middleware('require.2fa');
        Route::post('workspaces/{workspace}/fast2x/emergency-stop', [Fast2XController::class, 'emergencyStop'])->middleware('require.2fa');
        Route::post('workspaces/{workspace}/fast2x/reset-paper', [Fast2XController::class, 'resetPaper'])->middleware('require.2fa');
        Route::get('workspaces/{workspace}/fast2x/config', [Fast2XController::class, 'config']);
        Route::put('workspaces/{workspace}/fast2x/config', [Fast2XController::class, 'updateConfig'])->middleware('require.2fa');
        Route::get('workspaces/{workspace}/fast2x/env-config', [Fast2XController::class, 'envConfig'])->middleware('require.2fa');
        Route::put('workspaces/{workspace}/fast2x/env-config', [Fast2XController::class, 'updateEnvConfig'])->middleware('require.2fa');
        Route::post('workspaces/{workspace}/fast2x/test-connection', [Fast2XController::class, 'testConnection'])->middleware('require.2fa');
        Route::get('workspaces/{workspace}/fast2x/trades', [Fast2XController::class, 'trades']);
        Route::get('workspaces/{workspace}/fast2x/logs', [Fast2XController::class, 'logs']);
        Route::get('workspaces/{workspace}/fast2x/live-price', [Fast2XController::class, 'livePrice']);
        Route::get('workspaces/{workspace}/fast2x/pnl', [Fast2XController::class, 'pnl']);
        Route::get('workspaces/{workspace}/fast2x/chart', [Fast2XController::class, 'chart']);

        // ---- Future Trade Bot ----------------------------------------------
        Route::get('workspaces/{workspace}/future-trade/status', [FutureTradeBotController::class, 'status']);
        Route::get('workspaces/{workspace}/audit/flow-overview', [FutureTradeBotController::class, 'auditOverview']);
        Route::get('workspaces/{workspace}/future-trade/dashboard', [FutureTradeBotController::class, 'dashboard']);
        Route::get('workspaces/{workspace}/future-trade/webhook/last', [FutureTradeBotController::class, 'lastWebhookPayload']);
        Route::get('workspaces/{workspace}/future-trade/binance-health', [FutureTradeBotController::class, 'binanceHealth']);
        Route::get('workspaces/{workspace}/future-trade/runtime-health', [FutureTradeBotController::class, 'runtimeHealth']);
        Route::get('workspaces/{workspace}/future-trade/live-status', [FutureTradeBotController::class, 'liveStatus']);
        Route::get('workspaces/{workspace}/future-trade/scanner-snapshot', [FutureTradeBotController::class, 'scannerSnapshot']);
        Route::get('workspaces/{workspace}/future-trade/chart', [FutureTradeBotController::class, 'chart']);
        Route::get('workspaces/{workspace}/future-trade/live-feed', [FutureTradeBotController::class, 'liveFeedStatus']);
        Route::get('workspaces/{workspace}/future-trade/user-stream', [FutureTradeUserStreamController::class, 'snapshot']);
        Route::post('workspaces/{workspace}/future-trade/user-stream/restart', [FutureTradeUserStreamController::class, 'restart'])->middleware('require.2fa');
        Route::post('workspaces/{workspace}/future-trade/live-feed/start', [FutureTradeBotController::class, 'startLiveFeed'])->middleware('require.2fa');
        Route::post('workspaces/{workspace}/future-trade/live-feed/stop', [FutureTradeBotController::class, 'stopLiveFeed'])->middleware('require.2fa');
        Route::post('workspaces/{workspace}/future-trade/live-feed/restart', [FutureTradeBotController::class, 'restartLiveFeed'])->middleware('require.2fa');
        Route::post('workspaces/{workspace}/future-trade/runtime/recover', [FutureTradeBotController::class, 'recoverRuntime'])->middleware(['require.2fa','throttle:10,1']);
        Route::post('workspaces/{workspace}/future-trade/start', [FutureTradeBotController::class, 'start'])->middleware('require.2fa');
        Route::post('workspaces/{workspace}/future-trade/stop', [FutureTradeBotController::class, 'stop'])->middleware('require.2fa');
        Route::post('workspaces/{workspace}/future-trade/emergency-stop', [FutureTradeBotController::class, 'emergencyStop'])->middleware('require.2fa');
        Route::post('workspaces/{workspace}/future-trade/reset-paper', [FutureTradeBotController::class, 'resetPaper'])->middleware('require.2fa');
        Route::post('workspaces/{workspace}/future-trade/reset-daily-loss', [FutureTradeBotController::class, 'resetDailyLoss'])->middleware('require.2fa');
        Route::post('workspaces/{workspace}/future-trade/recover', [FutureTradeBotController::class, 'recover'])->middleware('require.2fa');
        Route::post('workspaces/{workspace}/future-trade/close-position', [FutureTradeBotController::class, 'closePosition'])->middleware(['require.2fa','throttle:20,1']);
        Route::get('workspaces/{workspace}/future-trade/config', [FutureTradeBotController::class, 'config']);
        Route::put('workspaces/{workspace}/future-trade/config', [FutureTradeBotController::class, 'updateConfig'])->middleware('require.2fa');
        Route::post('workspaces/{workspace}/future-trade/generate-tuning-plan', [FutureTradeBotController::class, 'generateTuningPlan']);
        Route::get('workspaces/{workspace}/future-trade/env-config', [FutureTradeBotController::class, 'envConfig'])->middleware('require.2fa');
        Route::put('workspaces/{workspace}/future-trade/env-config', [FutureTradeBotController::class, 'updateEnvConfig'])->middleware('require.2fa');
        Route::post('workspaces/{workspace}/future-trade/test-connection', [FutureTradeBotController::class, 'testConnection'])->middleware('require.2fa');
        Route::get('workspaces/{workspace}/future-trade/trades', [FutureTradeBotController::class, 'trades']);
        Route::get('workspaces/{workspace}/future-trade/logs', [FutureTradeBotController::class, 'logs']);
        Route::post('workspaces/{workspace}/future-trade/import-script', [FutureTradeBotController::class, 'importScript'])->middleware('require.2fa');
        Route::post('workspaces/{workspace}/future-trade/import-trades', [FutureTradeBotController::class, 'importTrades'])->middleware('require.2fa');
        Route::post('workspaces/{workspace}/future-trade/clear-history', [FutureTradeBotController::class, 'clearHistory'])->middleware('require.2fa');

        // Backtest
        Route::post('workspaces/{workspace}/future-trade/backtest', [FutureTradeBotController::class, 'runBacktest']);
        Route::get('workspaces/{workspace}/future-trade/backtest', [FutureTradeBotController::class, 'backtestList']);
        Route::get('workspaces/{workspace}/future-trade/backtest/{runId}', [FutureTradeBotController::class, 'backtestStatus']);
        Route::get('workspaces/{workspace}/future-trade/backtest/{runId}/trades', [FutureTradeBotController::class, 'backtestTrades']);

        Route::match(['get', 'post', 'put', 'patch', 'delete'], 'table/{table}', [TableController::class, 'handle']);
    });
});
