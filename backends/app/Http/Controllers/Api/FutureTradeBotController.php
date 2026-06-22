<?php

namespace App\Http\Controllers\Api;

use App\Domain\FutureTrade\FutureTradeBacktestEngine;
use App\Domain\FutureTrade\FutureTradeBotService;
use App\Domain\FutureTrade\FutureTradeRuntimeConnectivityService;
use App\Domain\Market\FutureTradeLiveFeedManager;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessPineWebhookSignalJob;
use App\Models\FutureTradeBacktestRun;
use App\Models\FutureTradeBotConfig;
use App\Models\FutureTradeOrderAudit;
use App\Models\FutureTradeWebhookAudit;
use App\Models\RobotSession;
use App\Models\RobotTradeAudit;
use App\Models\SchedulerRun;
use App\Models\WorkspaceMember;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class FutureTradeBotController extends Controller
{
    public function __construct(
        private readonly FutureTradeBotService $bot,
        private readonly FutureTradeLiveFeedManager $liveFeedManager,
        private readonly FutureTradeBacktestEngine $backtestEngine,
    ) {}

    public function status(string $workspace): JsonResponse
    {
        return $this->liveJson($this->bot->statusPayload($this->bot->getOrCreateConfig($workspace)));
    }

    public function dashboard(string $workspace): JsonResponse
    {
        return $this->liveJson($this->bot->dashboardPayload($this->bot->getOrCreateConfig($workspace)));
    }

    public function liveStatus(string $workspace): JsonResponse
    {
        return $this->liveJson($this->bot->liveStatusLightPayload($this->bot->getOrCreateConfig($workspace)));
    }

    public function scannerSnapshot(string $workspace): JsonResponse
    {
        return $this->liveJson($this->bot->scannerSnapshotPayload($this->bot->getOrCreateConfig($workspace)));
    }

    public function auditOverview(string $workspace): JsonResponse
    {
        $config = $this->bot->getOrCreateConfig($workspace);
        $latestWebhook = FutureTradeWebhookAudit::query()->where('workspace_id', $workspace)->latest('received_at')->first();
        $latestOrder = FutureTradeOrderAudit::query()->where('workspace_id', $workspace)->latest('order_request_at')->first();
        $latestRobot = RobotTradeAudit::query()->where('workspace_id', $workspace)->latest()->first();
        $robotSession = RobotSession::query()->where('workspace_id', $workspace)->latest('updated_at')->first();
        $diagnostics = $this->bot->diagnosticsPayload($config->fresh('state'), false);
        $runtime = app(FutureTradeRuntimeConnectivityService::class)->snapshot($config, $this->liveFeedManager);

        $queueRows = collect(['execution', 'critical', 'reconcile', 'market', 'default'])->mapWithKeys(function (string $queue) {
            try {
                return [$queue => [
                    'pending' => DB::getSchemaBuilder()->hasTable('jobs') ? DB::table('jobs')->where('queue', $queue)->count() : 0,
                    'failed' => DB::getSchemaBuilder()->hasTable('failed_jobs') ? DB::table('failed_jobs')->where('queue', $queue)->count() : 0,
                ]];
            } catch (Throwable) {
                return [$queue => ['pending' => null, 'failed' => null]];
            }
        })->all();

        $timeline = collect()
            ->merge(FutureTradeWebhookAudit::query()->where('workspace_id', $workspace)->latest('received_at')->limit(12)->get()->map(fn ($row) => [
                'at' => optional($row->received_at)->toIso8601String(),
                'type' => 'webhook',
                'title' => trim(($row->normalized_signal ?: $row->signal ?: 'WEBHOOK').' '.($row->symbol ?: '')),
                'state' => $row->validation_status === 'rejected' ? 'red' : ($row->decision === 'EXECUTE' ? 'green' : ($row->decision === 'WAIT' ? 'yellow' : 'gray')),
                'reason' => $row->reject_reason ?: $row->decision_reason,
                'latency_ms' => $row->total_latency_ms ?: $row->latency_from_bar_close_ms,
            ]))
            ->merge(FutureTradeOrderAudit::query()->where('workspace_id', $workspace)->latest('order_request_at')->limit(12)->get()->map(fn ($row) => [
                'at' => optional($row->order_request_at)->toIso8601String(),
                'type' => 'order',
                'title' => trim(($row->side ?: 'ORDER').' '.($row->binance_symbol ?: '')),
                'state' => $row->execution_status === 'FAILED' ? 'red' : ($row->execution_status === 'SUCCESS' ? 'green' : 'yellow'),
                'reason' => $row->error_message ?: $row->execution_status,
                'latency_ms' => null,
            ]))
            ->merge(RobotTradeAudit::query()->where('workspace_id', $workspace)->latest()->limit(12)->get()->map(fn ($row) => [
                'at' => optional($row->created_at)->toIso8601String(),
                'type' => 'robot',
                'title' => trim(($row->robot_signal ?: 'WAIT').' '.($row->symbol ?: '')),
                'state' => $row->noisy_candle ? 'yellow' : (in_array($row->robot_signal, ['BUY', 'SELL', 'CLOSE'], true) ? 'green' : 'gray'),
                'reason' => $row->decision_reason,
                'latency_ms' => null,
            ]))
            ->sortByDesc('at')
            ->values()
            ->take(30)
            ->all();

        $flows = [
            'webhook' => $this->webhookFlow($latestWebhook),
            'binance_brain' => $this->brainFlow($config, $diagnostics),
            'robot' => $this->robotFlow($robotSession, $latestRobot),
            'order_transport' => $this->orderTransportFlow($latestOrder, $runtime),
            'server' => $this->serverFlow($diagnostics, $queueRows, $runtime),
        ];
        $report = $this->auditReport($flows, $diagnostics, $queueRows, $latestWebhook, $latestOrder, $latestRobot, $robotSession, $runtime);

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'workspace_id' => $workspace,
            'flows' => $flows,
            'runtime' => $runtime,
            'report' => $report,
            'metrics' => [
                'webhooks_24h' => FutureTradeWebhookAudit::query()->where('workspace_id', $workspace)->where('received_at', '>=', now()->subDay())->count(),
                'webhook_rejects_24h' => FutureTradeWebhookAudit::query()->where('workspace_id', $workspace)->where('received_at', '>=', now()->subDay())->where('validation_status', 'rejected')->count(),
                'orders_24h' => FutureTradeOrderAudit::query()->where('workspace_id', $workspace)->where('order_request_at', '>=', now()->subDay())->count(),
                'order_failures_24h' => FutureTradeOrderAudit::query()->where('workspace_id', $workspace)->where('order_request_at', '>=', now()->subDay())->where('execution_status', 'FAILED')->count(),
                'robot_decisions_24h' => RobotTradeAudit::query()->where('workspace_id', $workspace)->where('created_at', '>=', now()->subDay())->count(),
            ],
            'latest' => [
                'webhook' => $latestWebhook,
                'order' => $latestOrder,
                'robot' => $latestRobot,
                'robot_session' => $robotSession,
            ],
            'queues' => $queueRows,
            'scheduler' => [
                'latest_runs' => SchedulerRun::query()->latest('started_at')->limit(8)->get(),
            ],
            'timeline' => $timeline,
            'actions' => [
                'recover_future_trade' => "/api/v1/workspaces/{$workspace}/future-trade/runtime/recover",
                'restart_live_feed' => "/api/v1/workspaces/{$workspace}/future-trade/live-feed/restart",
                'restart_workers' => '/api/v1/jobs/restart-workers',
                'retry_failed_jobs' => '/api/v1/jobs/failed/retry',
                'reconcile_binance' => 'php artisan future-trade:reconcile-binance --workspace='.$workspace,
            ],
        ]);
    }

    public function tradingViewWebhook(Request $request): JsonResponse
    {
        $rawBody = (string) $request->getContent();
        $decoded = $rawBody !== '' ? json_decode($rawBody, true) : null;
        $payload = is_array($decoded) ? $decoded : $request->all();
        $workspace = (string) (
            $payload['workspace_id']
            ?? $payload['workspace']
            ?? $request->header('X-Workspace-Id')
            ?? FutureTradeBotConfig::query()->value('workspace_id')
            ?? ''
        );

        if ($workspace === '') {
            return response()->json([
                'accepted' => false,
                'executed' => false,
                'reason' => 'workspace_required',
            ], 422);
        }

        $payload['source'] = 'pine_webhook';
        $request->merge($payload);
        $request->attributes->set('force_future_trade_source', 'pine_webhook');

        return $this->pineWebhook($request, $workspace);
    }

    private function webhookFlow(?FutureTradeWebhookAudit $latest): array
    {
        return [
            'title' => 'TradingView Webhook',
            'enabled' => true,
            'nodes' => [
                $this->flowNode('tradingview_alert', 'TradingView alert', $latest ? 'green' : 'gray', $latest?->signal, optional($latest?->received_at)->toIso8601String(), null, 'soft', $latest ? 'Alert reached backend.' : 'Create a TradingView alert and send one test payload.'),
                $this->flowNode('backend_received', 'Backend received', $latest ? 'green' : 'gray', $latest?->request_ip, optional($latest?->received_at)->toIso8601String(), $latest?->latency_from_bar_close_ms, 'soft', 'Check token, URL, internet, and Laravel logs if this stays empty.'),
                $this->flowNode('validation', 'Validation', ! $latest ? 'gray' : ($latest->validation_status === 'rejected' ? 'red' : 'green'), $latest?->reject_reason ?: 'valid', null, null, $latest?->validation_status === 'rejected' ? 'hard' : 'none', $latest?->reject_reason ? 'Fix payload/token/symbol/timeframe/bar_confirmed and resend alert.' : 'Payload is accepted.'),
                $this->flowNode('decision', 'Decision', ! $latest ? 'gray' : ($latest->decision === 'EXECUTE' ? 'green' : ($latest->decision === 'BLOCKED' ? 'red' : 'yellow')), $latest?->decision_reason, null, $latest?->total_latency_ms, $latest?->decision === 'BLOCKED' ? 'hard' : 'soft', 'Review duplicate, cooldown, risk, existing position, and live-mode gates.'),
            ],
        ];
    }

    private function brainFlow(FutureTradeBotConfig $config, array $diagnostics): array
    {
        $settings = $config->mergedSettings();
        $mirrorLive = (bool) ($settings['primeMirrorLiveEnabled'] ?? config('future_trade.live.prime_mirror_enabled', false));
        $source = (string) ($settings['signalSource'] ?? 'webhook');
        $blockers = (array) ($diagnostics['botBlockers'] ?? []);

        return [
            'title' => 'Binance Brain / Mirror',
            'enabled' => in_array($source, ['mirror', 'binance', 'webhook_fallback'], true),
            'nodes' => [
                $this->flowNode('market_data', 'Market data', data_get($diagnostics, 'tradeEligibility.decision') === 'blocked' ? 'red' : 'green', data_get($diagnostics, 'tradeEligibility.summary'), null, null, data_get($diagnostics, 'tradeEligibility.decision') === 'blocked' ? 'hard' : 'none', 'Restart live feed or run diagnostics if market data is stale.'),
                $this->flowNode('scanner', 'Scanner decision', $blockers === [] ? 'green' : 'yellow', implode(', ', $blockers) ?: 'scanner ready', null, null, $blockers === [] ? 'none' : 'soft', 'Scanner blockers are diagnostic in webhook authority mode unless mirror live is enabled.'),
                $this->flowNode('mirror_live_gate', 'Mirror live gate', $mirrorLive ? 'green' : 'yellow', $mirrorLive ? 'enabled' : 'prime_mirror_live_disabled_pending_tv_csv_validation', null, null, $mirrorLive ? 'none' : 'soft', 'Keep mirror live disabled until TradingView CSV validation passes.'),
                $this->flowNode('execution_gate', 'Execution gate', $source === 'webhook' ? 'yellow' : 'green', 'signalSource='.$source, null, null, $source === 'webhook' ? 'soft' : 'none', 'Webhook mode executes TradingView alerts; scanner stays visible for comparison.'),
            ],
        ];
    }

    private function robotFlow(?RobotSession $session, ?RobotTradeAudit $latest): array
    {
        $status = strtoupper((string) ($session?->status ?? 'OFF'));

        return [
            'title' => 'Robot Candle Trade',
            'enabled' => $session !== null,
            'nodes' => [
                $this->flowNode('robot_status', 'Robot status', $status === 'RUNNING' ? 'green' : ($status === 'STOPPED' ? 'red' : 'yellow'), $status, optional($session?->updated_at)->toIso8601String(), null, $status === 'STOPPED' ? 'hard' : 'soft', 'Restart robot only after checking stop reason and PnL limit.'),
                $this->flowNode('candle_direction', 'Candle direction', $latest ? ($latest->noisy_candle ? 'yellow' : 'green') : 'gray', $latest?->candle_direction ?: 'no candle audit yet', optional($latest?->created_at)->toIso8601String(), null, $latest?->noisy_candle ? 'soft' : 'none', 'Noisy candles should WAIT; clear candles can open/close based on confidence.'),
                $this->flowNode('robot_signal', 'Robot signal', ! $latest ? 'gray' : (in_array($latest->robot_signal, ['BUY', 'SELL', 'CLOSE'], true) ? 'green' : 'yellow'), $latest?->decision_reason ?: $latest?->robot_signal, null, null, in_array((string) $latest?->robot_signal, ['WAIT', 'HOLD'], true) ? 'soft' : 'none', 'WAIT is safe when confidence, cooldown, spread, or candle quality fails.'),
                $this->flowNode('loss_guard', 'Loss guard', str_contains((string) ($session?->last_error ?? ''), 'LOSS') ? 'red' : 'green', $session?->last_error ?: 'within limits', null, null, str_contains((string) ($session?->last_error ?? ''), 'LOSS') ? 'hard' : 'none', 'If total loss hit, robot closes positions and blocks new entries.'),
            ],
        ];
    }

    private function orderTransportFlow(?FutureTradeOrderAudit $latestOrder, array $runtime): array
    {
        $requested = (string) data_get($runtime, 'order_router.requested_transport', 'rest');
        $effective = (string) data_get($runtime, 'order_router.effective_transport', 'rest');
        $wsEnabled = (bool) data_get($runtime, 'order_router.websocket_enabled', false);
        $latestLatency = data_get($runtime, 'latency.latest.latency_ms');
        $latestStatus = (string) data_get($runtime, 'latency.latest.status', '');
        $userFresh = (bool) data_get($runtime, 'user_stream.fresh', false);

        return [
            'title' => 'Order Transport',
            'enabled' => true,
            'nodes' => [
                $this->flowNode('transport_switch', 'Transport Switch', $effective === 'websocket' ? 'green' : 'yellow', "requested={$requested}, effective={$effective}", null, null, $effective === 'websocket' ? 'none' : 'soft', 'Use the UI switch to move Future Trade orders between REST and Binance WS API.'),
                $this->flowNode('ws_session', 'WS API Session', $effective === 'websocket' && $wsEnabled ? 'green' : 'gray', $wsEnabled ? 'WS order endpoint enabled.' : 'WS order endpoint disabled; REST fallback active.', null, null, $wsEnabled ? 'none' : 'soft', 'Validate testnet endpoint, ping/pong, timeout, and reconnect behavior.'),
                $this->flowNode('signed_order', 'Signed Order', $latestOrder ? 'green' : 'gray', $latestOrder?->request_payload ? 'latest order payload audited' : 'no order sent yet', optional($latestOrder?->order_request_at)->toIso8601String(), is_numeric($latestLatency) ? (int) $latestLatency : null, 'none', 'Every order includes timestamp, recvWindow, apiKey, and signature before sending.'),
                $this->flowNode('binance_ack', 'Binance ACK', ! $latestOrder ? 'gray' : ($latestOrder->execution_status === 'FAILED' ? 'red' : 'green'), $latestOrder?->error_message ?: ($latestOrder?->execution_status ?: 'waiting for first ACK'), null, is_numeric($latestLatency) ? (int) $latestLatency : null, $latestOrder?->execution_status === 'FAILED' ? 'hard' : 'none', 'If ACK fails, inspect Binance error code and transport response payload.'),
                $this->flowNode('user_stream_confirm', 'User Stream Confirm', $userFresh ? 'green' : 'yellow', 'fresh=' . ($userFresh ? 'yes' : 'no') . ', status=' . data_get($runtime, 'user_stream.status', 'unknown'), data_get($runtime, 'user_stream.lastHeartbeatAt'), null, $userFresh ? 'none' : 'soft', 'Order ACK is not final truth; user data stream should confirm fills/position updates.'),
            ],
        ];
    }

    private function serverFlow(array $diagnostics, array $queues, array $runtime): array
    {
        $queueFailed = collect($queues)->sum(fn ($row) => (int) ($row['failed'] ?? 0));
        $queuePending = collect($queues)->sum(fn ($row) => (int) ($row['pending'] ?? 0));
        $marketState = (string) data_get($runtime, 'market_stream.state', 'waiting');
        $userState = (string) data_get($runtime, 'user_stream.state', 'waiting');
        $routerTransport = (string) data_get($runtime, 'order_router.effective_transport', 'rest');
        $latencyState = (string) data_get($runtime, 'latency.state', 'waiting');

        return [
            'title' => 'Server Runtime',
            'enabled' => true,
            'nodes' => [
                $this->flowNode('market_stream', 'Market WS', $marketState === 'healthy' ? 'green' : ($marketState === 'warning' ? 'yellow' : 'red'), data_get($runtime, 'market_stream.message', 'market stream status unavailable'), data_get($runtime, 'market_stream.last_tick_at'), null, $marketState === 'blocked' ? 'hard' : ($marketState === 'warning' ? 'soft' : 'none'), 'Restart live feed or verify Binance websocket connectivity.'),
                $this->flowNode('user_stream', 'User Stream', $userState === 'healthy' ? 'green' : ($userState === 'warning' ? 'yellow' : 'gray'), 'status='.data_get($runtime, 'user_stream.status', 'unknown').', fresh='.(data_get($runtime, 'user_stream.fresh') ? 'yes' : 'no'), data_get($runtime, 'user_stream.lastHeartbeatAt'), null, $userState === 'warning' ? 'soft' : 'none', 'Keep listenKey alive and reconnect user-data stream if it becomes stale.'),
                $this->flowNode('order_router', 'Order Router', $routerTransport === 'websocket' ? 'green' : 'yellow', data_get($runtime, 'order_router.message', 'order router ready'), null, data_get($runtime, 'latency.latest.latency_ms'), $routerTransport === 'websocket' ? 'none' : 'soft', 'REST is safe; enable WS order transport only after testnet validation.'),
                $this->flowNode('latency_engine', 'Latency Engine', $latencyState === 'healthy' ? 'green' : ($latencyState === 'warning' ? 'yellow' : ($latencyState === 'blocked' ? 'red' : 'gray')), data_get($runtime, 'latency.summary', 'No latency samples yet.'), data_get($runtime, 'latency.latest.finished_at'), data_get($runtime, 'latency.latest.latency_ms'), $latencyState === 'blocked' ? 'hard' : ($latencyState === 'warning' ? 'soft' : 'none'), 'Check order-router, queue, network, and Binance ACK times.'),
                $this->flowNode('queue', 'Queue workers', $queueFailed > 0 ? 'red' : ($queuePending > 50 ? 'yellow' : 'green'), "pending {$queuePending}, failed {$queueFailed}", null, null, $queueFailed > 0 ? 'hard' : ($queuePending > 50 ? 'soft' : 'none'), 'Retry failed jobs, restart workers, or scale queue workers.'),
                $this->flowNode('scheduler', 'Scheduler / cron', 'green', 'scheduler audit available', null, null, 'none', 'If commands stop updating, verify cron schedule:run and supervisor.'),
            ],
        ];
    }

    private function flowNode(string $key, string $label, string $state, ?string $detail = null, ?string $at = null, ?int $latencyMs = null, string $blockerType = 'none', ?string $nextAction = null): array
    {
        $retryable = in_array($key, ['backend_received', 'market_data', 'queue', 'scheduler', 'server', 'wallet'], true);

        return compact('key', 'label', 'state', 'detail', 'at', 'latencyMs', 'blockerType', 'nextAction', 'retryable');
    }

    private function auditReport(array $flows, array $diagnostics, array $queues, ?FutureTradeWebhookAudit $latestWebhook, ?FutureTradeOrderAudit $latestOrder, ?RobotTradeAudit $latestRobot, ?RobotSession $robotSession, array $runtime): array
    {
        $nodes = collect($flows)->flatMap(fn (array $flow, string $flowKey) => collect($flow['nodes'] ?? [])->map(fn (array $node) => [
            ...$node,
            'flow' => $flowKey,
        ]));
        $hard = $nodes->where('state', 'red')->values();
        $warnings = $nodes->where('state', 'yellow')->values();
        $queueFailed = collect($queues)->sum(fn ($row) => (int) ($row['failed'] ?? 0));
        $queuePending = collect($queues)->sum(fn ($row) => (int) ($row['pending'] ?? 0));

        $summary = $hard->isNotEmpty()
            ? 'Hard blocker found. Trade flow may stop at the red node until it is fixed.'
            : ($warnings->isNotEmpty()
                ? 'Flow is running with soft warnings. Trades may still work depending on the active method.'
                : 'All visible audit lights are healthy.');

        return [
            'summary' => $summary,
            'health' => [
                'state' => $hard->isNotEmpty() ? 'blocked' : ($warnings->isNotEmpty() ? 'warning' : 'healthy'),
                'hard_blocker_count' => $hard->count(),
                'soft_warning_count' => $warnings->count(),
                'queue_pending' => $queuePending,
                'queue_failed' => $queueFailed,
            ],
            'hard_blockers' => $hard->all(),
            'soft_warnings' => $warnings->all(),
            'failover' => [
                ['label' => 'TradingView primary', 'state' => $latestWebhook ? 'ready' : 'waiting', 'detail' => $latestWebhook ? 'Webhook audit row exists.' : 'No webhook received yet.'],
                ['label' => 'Webhook fallback', 'state' => 'available', 'detail' => 'Backend scanner stays diagnostic while webhook authority is active.'],
                ['label' => 'Market websocket', 'state' => (string) data_get($runtime, 'market_stream.state', 'waiting'), 'detail' => (string) data_get($runtime, 'market_stream.message', 'Market stream status unavailable.')],
                ['label' => 'User data stream', 'state' => (string) data_get($runtime, 'user_stream.state', 'waiting'), 'detail' => 'Fresh='.(data_get($runtime, 'user_stream.fresh') ? 'yes' : 'no').', status='.(string) data_get($runtime, 'user_stream.status', 'unknown')],
                ['label' => 'Order transport', 'state' => (string) data_get($runtime, 'order_router.effective_transport', 'rest'), 'detail' => (string) data_get($runtime, 'order_router.message', 'Order router status unavailable.')],
                ['label' => 'Queue recovery', 'state' => $queueFailed > 0 ? 'needs_retry' : 'ready', 'detail' => $queueFailed > 0 ? 'Retry failed jobs and restart workers.' : 'No failed jobs in tracked queues.'],
                ['label' => 'Binance reconcile', 'state' => 'available', 'detail' => 'Run reconcile when Binance position/order status looks different from local DB.'],
                ['label' => 'Robot loss guard', 'state' => str_contains((string) ($robotSession?->last_error ?? ''), 'LOSS') ? 'stopped' : 'armed', 'detail' => $robotSession?->last_error ?: 'Total and daily loss guards are active.'],
            ],
            'operator_actions' => [
                'red_node' => 'Fix the red node first; it is a hard stop for that flow.',
                'yellow_node' => 'Yellow is a warning or diagnostic gate; verify before enabling live execution.',
                'failed_order' => $latestOrder?->execution_status === 'FAILED' ? ($latestOrder->error_message ?: 'Check Binance error payload.') : 'No latest failed order.',
                'latest_robot' => $latestRobot?->decision_reason ?: 'No robot decision audit yet.',
                'diagnostics' => data_get($diagnostics, 'summary'),
                'latency' => data_get($runtime, 'latency.summary'),
            ],
            'raw_refs' => [
                'latest_webhook_id' => $latestWebhook?->id,
                'latest_order_id' => $latestOrder?->id,
                'latest_robot_audit_id' => $latestRobot?->id,
                'robot_session_id' => $robotSession?->id,
            ],
        ];
    }

    public function pineWebhook(Request $request, string $workspace): JsonResponse
    {
        // Primary slot 0 always exists — used for token validation, raw audit
        // logging, and as fallback when no symbol-specific slot is available.
        $primary = $this->bot->getOrCreateConfig($workspace);
        $configuredToken = trim((string) config('future_trade.pine_webhook_token', ''));
        $rawBody = (string) $request->getContent();
        $decoded = $rawBody !== '' ? json_decode($rawBody, true) : null;
        $payload = is_array($decoded) ? $decoded : $request->all();
        if ($request->attributes->get('force_future_trade_source')) {
            $payload['source'] = (string) $request->attributes->get('force_future_trade_source');
        }
        $providedTokens = array_filter(array_map(
            static fn ($token): string => trim((string) $token),
            [
                $request->bearerToken(),
                $request->header('X-Webhook-Token'),
                $payload['token'] ?? null,
                $request->query('token'),
            ]
        ));

        $tokenMatched = $configuredToken !== '' && collect($providedTokens)
            ->contains(static fn (string $token): bool => hash_equals($configuredToken, $token));

        $headersSummary = [];
        foreach (['content-type', 'user-agent', 'x-forwarded-for', 'cf-connecting-ip'] as $header) {
            if ($request->headers->has($header)) {
                $headersSummary[$header] = $request->headers->get($header);
            }
        }
        // Raw payload always recorded against slot 0 for a single source of
        // truth audit trail, regardless of which slot ultimately executes.
        try {
            $this->bot->recordRawPineWebhookRequest($primary, $payload, [
                'rawBody' => $rawBody,
                'remoteIp' => $request->ip(),
                'headersSummary' => $headersSummary,
                'parseStatus' => $rawBody === '' ? 'empty' : (json_last_error() === JSON_ERROR_NONE ? 'json' : 'invalid_json'),
                'tokenStatus' => $tokenMatched ? 'valid' : 'invalid_or_missing',
            ]);
        } catch (Throwable $exception) {
            Log::error('PINE_WEBHOOK_RAW_AUDIT_FAILED', [
                'workspace' => $workspace,
                'remoteIp' => $request->ip(),
                'message' => $exception->getMessage(),
            ]);
        }

        if (! $tokenMatched) {
            return response()->json([
                'accepted' => false,
                'executed' => false,
                'reason' => 'invalid_or_missing_webhook_token',
            ], 401);
        }

        try {
            // Slot routing: find the slot that owns the incoming symbol, or
            // assign the first FLAT slot. If every slot is busy with a
            // different symbol, reject with a clear reason.
            $rawSymbol = (string) ($payload['symbol'] ?? $payload['ticker'] ?? '');
            $symbol = $this->bot->normalizeIncomingSymbol($rawSymbol);
            $targetConfig = $symbol !== ''
                ? $this->bot->findSlotForSymbol($workspace, $symbol)
                : null;

            $rawSignal = strtoupper(str_replace(['-', ' '], '_', trim((string) ($payload['signal'] ?? $payload['action'] ?? $payload['side'] ?? 'WAIT'))));
            $isCloseSignal = in_array($rawSignal, [
                'CLOSE',
                'EXIT',
                'FLAT',
                'CLOSE_LONG',
                'CLOSE_SHORT',
                'EXIT_LONG',
                'EXIT_SHORT',
                'FLAT_LONG',
                'FLAT_SHORT',
            ], true);

            if ($symbol !== '' && $targetConfig === null && $isCloseSignal) {
                $payload['_slot_routing'] = 'no_symbol_slot_close_log_only';
                $targetConfig = $primary;
            }

            if ($symbol !== '' && $targetConfig === null) {
                $activeCount = $this->bot->activePositionCount($workspace);

                return response()->json([
                    'accepted' => false,
                    'executed' => false,
                    'eligible' => false,
                    'source' => 'pine_webhook',
                    'execution' => 'rejected',
                    'reason' => 'max_concurrent_positions_reached',
                    'symbol' => $symbol,
                    'activePositions' => $activeCount,
                ], 422);
            }

            // Dispatch execution to the queue so TradingView gets a response
            // in < 100ms and never times out due to Binance API latency.
            // All safety guards (daily loss, cooldown, idempotency) run inside
            // the job — nothing is bypassed.
            $resolvedConfig = $targetConfig ?? $primary;
            ProcessPineWebhookSignalJob::dispatch(
                (string) $resolvedConfig->id,
                $payload,
                $workspace,
                $targetConfig !== null ? (int) $targetConfig->slot_index : null,
            );

            return response()->json([
                'accepted' => true,
                'executed' => false,
                'execution' => 'queued',
                'source' => 'pine_webhook',
                'symbol' => $symbol ?: null,
                'slotIndex' => $targetConfig !== null ? (int) $targetConfig->slot_index : 0,
                'queuedAt' => now()->toIso8601String(),
            ], 200);
        } catch (Throwable $exception) {
            Log::error('PINE_WEBHOOK_INTERNAL_ERROR', [
                'workspace' => $workspace,
                'symbol' => $request->input('symbol') ?? $request->input('ticker'),
                'signal' => $request->input('signal') ?? $request->input('action') ?? $request->input('side'),
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'accepted' => false,
                'executed' => false,
                'eligible' => false,
                'source' => 'pine_webhook',
                'execution' => 'failed',
                'reason' => 'webhook_internal_error',
            ]);
        }
    }

    public function lastWebhookPayload(string $workspace): JsonResponse
    {
        return $this->liveJson($this->bot->lastWebhookPayload($this->bot->getOrCreateConfig($workspace)));
    }

    public function binanceHealth(string $workspace): JsonResponse
    {
        return $this->liveJson($this->bot->binanceHealthPayload($this->bot->getOrCreateConfig($workspace)));
    }

    public function runtimeHealth(string $workspace): JsonResponse
    {
        return $this->liveJson($this->bot->runtimeHealthPayload($this->bot->getOrCreateConfig($workspace)));
    }

    public function chart(Request $request, string $workspace): JsonResponse
    {
        return $this->liveJson($this->bot->liveChartPayload(
            $this->bot->getOrCreateConfig($workspace),
            $request->query('symbol') ? (string) $request->query('symbol') : null,
            $request->query('timeframe') ? (string) $request->query('timeframe') : null,
            max(60, min(500, (int) $request->query('limit', 180))),
        ));
    }

    public function liveFeedStatus(string $workspace): JsonResponse
    {
        return $this->liveJson($this->liveFeedManager->status($workspace));
    }

    public function startLiveFeed(string $workspace): JsonResponse
    {
        return response()->json($this->liveFeedManager->start());
    }

    public function stopLiveFeed(string $workspace): JsonResponse
    {
        return response()->json($this->liveFeedManager->stop());
    }

    public function restartLiveFeed(string $workspace): JsonResponse
    {
        return response()->json($this->liveFeedManager->restart());
    }

    public function recoverRuntime(string $workspace): JsonResponse
    {
        $feed = $this->liveFeedManager->restart();
        $exitCode = Artisan::call('future-trade:tick', [
            '--workspace' => $workspace,
            '--duration' => 8,
            '--sleep-ms' => 1000,
        ]);

        return response()->json([
            'recovered' => $exitCode === 0,
            'message' => $exitCode === 0
                ? 'Future Trade runtime recovery ran: live feed restart requested and scanner tick refreshed.'
                : 'Future Trade runtime recovery attempted, but scanner tick returned a non-zero exit code.',
            'feed' => $feed,
            'tickExitCode' => $exitCode,
        ]);
    }

    public function start(string $workspace): JsonResponse
    {
        return response()->json(['state' => $this->bot->start($this->bot->getOrCreateConfig($workspace))]);
    }

    public function stop(string $workspace): JsonResponse
    {
        return response()->json(['state' => $this->bot->stop($this->bot->getOrCreateConfig($workspace))]);
    }

    public function emergencyStop(string $workspace): JsonResponse
    {
        return response()->json(['state' => $this->bot->emergencyStop($this->bot->getOrCreateConfig($workspace))]);
    }

    public function resetPaper(string $workspace): JsonResponse
    {
        return response()->json(['state' => $this->bot->resetPaper($this->bot->getOrCreateConfig($workspace))]);
    }

    public function resetDailyLoss(string $workspace): JsonResponse
    {
        return response()->json(['state' => $this->bot->resetDailyLoss($this->bot->getOrCreateConfig($workspace))]);
    }

    public function clearHistory(string $workspace): JsonResponse
    {
        return response()->json($this->bot->clearHistory($this->bot->getOrCreateConfig($workspace)));
    }

    public function importScript(Request $request, string $workspace): JsonResponse
    {
        $data = $request->validate([
            'name' => 'nullable|string|max:255',
            'source' => 'nullable|string|max:50',
            'script' => 'required|string|max:120000',
        ]);

        return response()->json($this->bot->importResearchScript($this->bot->getOrCreateConfig($workspace), $data));
    }

    public function importTrades(Request $request, string $workspace): JsonResponse
    {
        $data = $request->validate([
            'name' => 'nullable|string|max:255',
            'format' => 'required|string|in:csv,json',
            'payload' => 'required|string|max:300000',
        ]);

        return response()->json($this->bot->importResearchTrades($this->bot->getOrCreateConfig($workspace), $data));
    }

    public function recover(Request $request, string $workspace): JsonResponse
    {
        if (! $this->authorizeRecovery($request, $workspace)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'action' => 'nullable|string|in:auto,sync_state,cancel_reduce_only',
        ]);

        try {
            return response()->json(
                $this->bot->recoverRuntime(
                    $this->bot->getOrCreateConfig($workspace),
                    (string) ($data['action'] ?? 'auto'),
                )
            );
        } catch (\Throwable $e) {
            return response()->json([
                'resolved' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function closePosition(Request $request, string $workspace): JsonResponse
    {
        if (! $this->authorizeRecovery($request, $workspace)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'symbol' => 'nullable|string|max:32',
        ]);

        try {
            return response()->json(
                $this->bot->closeOpenPosition(
                    $this->bot->getOrCreateConfig($workspace),
                    isset($data['symbol']) ? (string) $data['symbol'] : null,
                )
            );
        } catch (\Throwable $e) {
            return response()->json([
                'closed' => false,
                'submitted' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    private function authorizeRecovery(Request $request, string $workspace): bool
    {
        $user = $request->user();
        $role = $user
            ? WorkspaceMember::query()
                ->where('workspace_id', $workspace)
                ->where('user_id', $user->id)
                ->value('role')
            : null;
        $allowed = in_array((string) $role, ['owner', 'admin', 'trader', 'member'], true);

        Log::info($allowed ? '[FUTURE TRADE RECOVERY AUTHORIZED]' : '[FUTURE TRADE RECOVERY DENIED]', [
            'workspace_id' => $workspace,
            'user_id' => $user?->id,
            'role' => $role,
        ]);

        return $allowed;
    }

    public function config(string $workspace): JsonResponse
    {
        return response()->json($this->bot->configPayload($this->bot->getOrCreateConfig($workspace)));
    }

    public function updateConfig(Request $request, string $workspace): JsonResponse
    {
        $data = $request->validate([
            'settings' => 'nullable|array',
            'algorithm_notes' => 'nullable|string|max:20000',
        ]);

        return response()->json($this->bot->updateConfig($this->bot->getOrCreateConfig($workspace), $data));
    }

    public function generateTuningPlan(string $workspace): JsonResponse
    {
        return response()->json($this->bot->generateTuningPlanPreview($this->bot->getOrCreateConfig($workspace)));
    }

    public function envConfig(string $workspace): JsonResponse
    {
        return response()->json($this->envPayload($this->bot->getOrCreateConfig($workspace)));
    }

    public function updateEnvConfig(Request $request, string $workspace): JsonResponse
    {
        $data = $request->validate([
            'mode' => 'required|string|in:paper,testnet,live',
            'testnet_enabled' => 'required|boolean',
            'live_enabled' => 'required|boolean',
            'force_testnet' => 'required|boolean',
            'live_api_url' => 'required|url|max:255',
            'testnet_api_url' => 'required|url|max:255',
            'live_api_key' => 'nullable|string|max:512',
            'live_api_secret' => 'nullable|string|max:512',
            'testnet_api_key' => 'nullable|string|max:512',
            'testnet_api_secret' => 'nullable|string|max:512',
            'replace_live_secret' => 'nullable|boolean',
            'replace_testnet_secret' => 'nullable|boolean',
        ]);

        $mode = (string) $data['mode'];
        $botConfig = $this->bot->getOrCreateConfig($workspace);
        $settings = $botConfig->mergedSettings();

        $payload = [
            'settings' => [
                'envMode' => $mode,
                'envTestnetEnabled' => (bool) $data['testnet_enabled'],
                'envLiveEnabled' => (bool) $data['live_enabled'],
                'envForceTestnet' => (bool) $data['force_testnet'],
                'liveApiUrl' => (string) $data['live_api_url'],
                'testnetApiUrl' => (string) $data['testnet_api_url'],
                'liveApiKey' => array_key_exists('live_api_key', $data)
                    ? (string) ($data['live_api_key'] ?? '')
                    : (string) ($settings['liveApiKey'] ?? ''),
                'liveApiSecret' => (bool) ($data['replace_live_secret'] ?? false)
                    ? (string) ($data['live_api_secret'] ?? '')
                    : (string) ($settings['liveApiSecret'] ?? ''),
                'testnetApiKey' => array_key_exists('testnet_api_key', $data)
                    ? (string) ($data['testnet_api_key'] ?? '')
                    : (string) ($settings['testnetApiKey'] ?? ''),
                'testnetApiSecret' => (bool) ($data['replace_testnet_secret'] ?? false)
                    ? (string) ($data['testnet_api_secret'] ?? '')
                    : (string) ($settings['testnetApiSecret'] ?? ''),
                'liveTrading' => $mode !== 'paper',
                'paperTrading' => $mode === 'paper',
                'forceTestnet' => $mode !== 'live',
            ],
        ];

        $savedConfig = $this->bot->updateConfig($botConfig, $payload);

        return response()->json($this->envPayload($savedConfig));
    }

    public function testConnection(string $workspace): JsonResponse
    {
        try {
            return response()->json($this->bot->testConnection($this->bot->getOrCreateConfig($workspace)));
        } catch (\Throwable $e) {
            return response()->json([
                'connected' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function trades(Request $request, string $workspace): JsonResponse
    {
        $query = $this->bot->getOrCreateConfig($workspace)->trades()->orderByDesc('created_at');
        if (! filter_var($request->query('all', false), FILTER_VALIDATE_BOOL)) {
            $query->limit(max(1, min(1000, (int) $request->query('limit', 100))));
        }

        return response()->json($query->get());
    }

    public function logs(Request $request, string $workspace): JsonResponse
    {
        try {
            $limit = max(1, min(1000, (int) $request->query('limit', 300)));
            $logs = $this->bot->getOrCreateConfig($workspace)
                ->logs()
                ->orderByDesc('occurred_at')
                ->limit($limit)
                ->get();

            return response()->json($logs);
        } catch (\Throwable $e) {
            Log::error('[FUTURE TRADE LOGS FAILED]', [
                'workspace_id' => $workspace,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Future Trade logs are temporarily unavailable.',
                'error' => app()->hasDebugModeEnabled() ? $e->getMessage() : null,
            ], 500);
        }
    }

    private function envPayload($config): array
    {
        $settings = $config->mergedSettings();
        $mask = fn (string $value) => $value === '' ? '' : substr($value, 0, 4).str_repeat('*', max(4, strlen($value) - 8)).substr($value, -4);

        return [
            'mode' => (string) ($settings['envMode'] ?? 'paper'),
            'testnet_enabled' => (bool) ($settings['envTestnetEnabled'] ?? false),
            'live_enabled' => (bool) ($settings['envLiveEnabled'] ?? false),
            'force_testnet' => (bool) ($settings['envForceTestnet'] ?? true),
            'live_api_url' => (string) ($settings['liveApiUrl'] ?? 'https://fapi.binance.com'),
            'testnet_api_url' => (string) ($settings['testnetApiUrl'] ?? 'https://testnet.binancefuture.com'),
            'live_api_key' => (string) ($settings['liveApiKey'] ?? ''),
            'live_api_secret_masked' => $mask((string) ($settings['liveApiSecret'] ?? '')),
            'testnet_api_key' => (string) ($settings['testnetApiKey'] ?? ''),
            'testnet_api_secret_masked' => $mask((string) ($settings['testnetApiSecret'] ?? '')),
            'live_configured' => (string) ($settings['liveApiKey'] ?? '') !== '' && (string) ($settings['liveApiSecret'] ?? '') !== '',
            'testnet_configured' => (string) ($settings['testnetApiKey'] ?? '') !== '' && (string) ($settings['testnetApiSecret'] ?? '') !== '',
        ];
    }

    // -------------------------------------------------------------------------
    // Backtest
    // -------------------------------------------------------------------------

    public function runBacktest(Request $request, string $workspace): JsonResponse
    {
        $data = $request->validate([
            'from' => 'nullable|date|before:today',
            'to' => 'nullable|date|before_or_equal:today',
            'capital' => 'nullable|numeric|min:10|max:1000000',
        ]);

        $config = $this->bot->getOrCreateConfig($workspace);
        $from = isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : now()->subDays(30)->startOfDay();
        $to = isset($data['to']) ? Carbon::parse($data['to'])->endOfDay() : now()->subDay()->endOfDay();
        $capital = (float) ($data['capital'] ?? 1000);

        $run = $this->backtestEngine->run($config, $from, $to, $capital);

        return response()->json($this->backtestRunPayload($run), $run->status === 'failed' ? 422 : 200);
    }

    public function backtestList(string $workspace): JsonResponse
    {
        $runs = FutureTradeBacktestRun::where('workspace_id', $workspace)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn ($r) => $this->backtestRunPayload($r));

        return response()->json(['runs' => $runs]);
    }

    public function backtestStatus(string $workspace, string $runId): JsonResponse
    {
        $run = FutureTradeBacktestRun::where('workspace_id', $workspace)->findOrFail($runId);

        return response()->json($this->backtestRunPayload($run));
    }

    public function backtestTrades(string $workspace, string $runId): JsonResponse
    {
        $run = FutureTradeBacktestRun::where('workspace_id', $workspace)->findOrFail($runId);
        $trades = $run->trades()->orderBy('entry_time')->get();

        return response()->json(['trades' => $trades]);
    }

    private function backtestRunPayload(FutureTradeBacktestRun $run): array
    {
        return [
            'id' => $run->id,
            'status' => $run->status,
            'from_date' => $run->from_date?->toDateString(),
            'to_date' => $run->to_date?->toDateString(),
            'symbols' => $run->symbols,
            'initial_capital_usdt' => $run->initial_capital_usdt,
            'total_trades' => $run->total_trades,
            'wins' => $run->wins,
            'losses' => $run->losses,
            'win_rate' => $run->win_rate,
            'total_pnl_usdt' => $run->total_pnl_usdt,
            'total_pnl_percent' => $run->total_pnl_percent,
            'max_drawdown_usdt' => $run->max_drawdown_usdt,
            'max_drawdown_percent' => $run->max_drawdown_percent,
            'profit_factor' => $run->profit_factor,
            'sharpe_ratio' => $run->sharpe_ratio,
            'avg_trade_duration_minutes' => $run->avg_trade_duration_minutes,
            'by_symbol' => $run->by_symbol,
            'by_exit_reason' => $run->by_exit_reason,
            'error_message' => $run->error_message,
            'started_at' => $run->started_at?->toIso8601String(),
            'completed_at' => $run->completed_at?->toIso8601String(),
            'created_at' => $run->created_at?->toIso8601String(),
        ];
    }

    private function liveJson(array $payload): JsonResponse
    {
        return response()
            ->json($payload)
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }
}
