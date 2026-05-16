<?php

namespace App\Http\Controllers\Api;

use App\Domain\FutureTrade\FutureTradeBacktestEngine;
use App\Domain\FutureTrade\FutureTradeBotService;
use App\Domain\Market\FutureTradeLiveFeedManager;
use App\Http\Controllers\Controller;
use App\Models\FutureTradeBacktestRun;
use App\Models\WorkspaceMember;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FutureTradeBotController extends Controller
{
    public function __construct(
        private readonly FutureTradeBotService $bot,
        private readonly FutureTradeLiveFeedManager $liveFeedManager,
        private readonly FutureTradeBacktestEngine $backtestEngine,
    ) {}

    public function status(string $workspace): JsonResponse
    {
        return response()->json($this->bot->statusPayload($this->bot->getOrCreateConfig($workspace)));
    }

    public function dashboard(string $workspace): JsonResponse
    {
        return response()->json($this->bot->dashboardPayload($this->bot->getOrCreateConfig($workspace)));
    }

    public function liveStatus(string $workspace): JsonResponse
    {
        return response()->json($this->bot->liveStatusLightPayload($this->bot->getOrCreateConfig($workspace)));
    }

    public function scannerSnapshot(string $workspace): JsonResponse
    {
        return response()->json($this->bot->scannerSnapshotPayload($this->bot->getOrCreateConfig($workspace)));
    }

    public function chart(Request $request, string $workspace): JsonResponse
    {
        return response()->json($this->bot->liveChartPayload(
            $this->bot->getOrCreateConfig($workspace),
            $request->query('symbol') ? (string) $request->query('symbol') : null,
            $request->query('timeframe') ? (string) $request->query('timeframe') : null,
            max(60, min(500, (int) $request->query('limit', 180))),
        ));
    }

    public function liveFeedStatus(string $workspace): JsonResponse
    {
        return response()->json($this->liveFeedManager->status($workspace));
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
        $query = $this->bot->getOrCreateConfig($workspace)->logs()->orderByDesc('occurred_at');
        if (! filter_var($request->query('all', false), FILTER_VALIDATE_BOOL)) {
            $query->limit(max(1, min(1000, (int) $request->query('limit', 100))));
        }

        return response()->json($query->get());
    }

    private function envPayload($config): array
    {
        $settings = $config->mergedSettings();
        $mask = fn (string $value) => $value === '' ? '' : substr($value, 0, 4) . str_repeat('*', max(4, strlen($value) - 8)) . substr($value, -4);

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
            'from'    => 'nullable|date|before:today',
            'to'      => 'nullable|date|before_or_equal:today',
            'capital' => 'nullable|numeric|min:10|max:1000000',
        ]);

        $config  = $this->bot->getOrCreateConfig($workspace);
        $from    = isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : now()->subDays(30)->startOfDay();
        $to      = isset($data['to'])   ? Carbon::parse($data['to'])->endOfDay()     : now()->subDay()->endOfDay();
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
            'id'                      => $run->id,
            'status'                  => $run->status,
            'from_date'               => $run->from_date?->toDateString(),
            'to_date'                 => $run->to_date?->toDateString(),
            'symbols'                 => $run->symbols,
            'initial_capital_usdt'    => $run->initial_capital_usdt,
            'total_trades'            => $run->total_trades,
            'wins'                    => $run->wins,
            'losses'                  => $run->losses,
            'win_rate'                => $run->win_rate,
            'total_pnl_usdt'          => $run->total_pnl_usdt,
            'total_pnl_percent'       => $run->total_pnl_percent,
            'max_drawdown_usdt'       => $run->max_drawdown_usdt,
            'max_drawdown_percent'    => $run->max_drawdown_percent,
            'profit_factor'           => $run->profit_factor,
            'sharpe_ratio'            => $run->sharpe_ratio,
            'avg_trade_duration_minutes' => $run->avg_trade_duration_minutes,
            'by_symbol'               => $run->by_symbol,
            'by_exit_reason'          => $run->by_exit_reason,
            'error_message'           => $run->error_message,
            'started_at'              => $run->started_at?->toIso8601String(),
            'completed_at'            => $run->completed_at?->toIso8601String(),
            'created_at'              => $run->created_at?->toIso8601String(),
        ];
    }
}
