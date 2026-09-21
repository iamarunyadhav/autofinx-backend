<?php

namespace App\Console\Commands;

use App\Domain\Exchange\BinanceRestCircuitBreaker;
use App\Domain\FutureTrade\FutureTradeAccountSyncService;
use App\Domain\FutureTrade\FutureTradeUserStreamService;
use App\Models\FutureTradeBotConfig;
use App\Models\FutureTradeBotLog;
use App\Models\FutureTradeLogicalTrade;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class FutureTradeRuntimeStatusCommand extends Command
{
    protected $signature = 'future-trade:runtime-status
        {--json : Output as JSON}
        {--workspace= : Limit to a single workspace}';

    protected $description = 'Show a live snapshot of the AutoFinX Future Trade runtime: supervisor processes, feed freshness, queue depths, Redis/DB health.';

    public function handle(): int
    {
        $workspaceId = trim((string) $this->option('workspace'));
        $asJson = (bool) $this->option('json');

        $status = [
            'generated_at' => date('c'),
            'runtime' => $this->runtimeOwnershipStatus(),
            'scheduler' => $this->schedulerStatus(),
            'redis' => $this->redisStatus(),
            'database' => $this->dbStatus(),
            'binance_rest' => app(BinanceRestCircuitBreaker::class)->status(),
            'live_feed' => $this->liveFeedStatus($workspaceId),
            'user_stream' => $this->userStreamStatus($workspaceId),
            'account_sync' => $this->accountSyncStatus($workspaceId),
            'unexpected_processes' => $this->unexpectedProcessStatus(),
            'queues' => $this->queueStatus(),
            'positions' => $this->positionStatus($workspaceId),
            'configs' => $this->configStatus($workspaceId),
            'last_webhook' => $this->lastWebhookStatus($workspaceId),
            'last_webhook_event' => $this->lastWebhookEventStatus($workspaceId),
        ];

        if ($asJson) {
            $this->line(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->renderHuman($status);

        return self::SUCCESS;
    }

    private function redisStatus(): array
    {
        try {
            $pong = Redis::ping();

            return ['ok' => true, 'response' => $pong];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function runtimeOwnershipStatus(): array
    {
        return [
            'owner' => (string) config('autofinx.runtime.owner', 'supervisor'),
            'supervisor' => (bool) config('autofinx.runtime.supervisor', false),
        ];
    }

    private function schedulerStatus(): array
    {
        return [
            'enabled' => (bool) config('autofinx.scheduler.enabled', true),
            'market_consumers' => (bool) config('autofinx.scheduler.market_consumers', false),
            'binance_rest' => (bool) config('autofinx.scheduler.binance_rest', false),
            'metals' => (bool) config('autofinx.scheduler.metals', false),
            'future_trade_runtime_commands' => (bool) config('future_trade.schedule.runtime_commands', false),
            'future_trade_reconcile' => (bool) config('future_trade.schedule.reconcile', false),
            'future_trade_diagnostics' => (bool) config('future_trade.schedule.diagnostics', false),
            'future_trade_audit_missed_signals' => (bool) config('future_trade.schedule.audit_missed_signals', false),
        ];
    }

    private function dbStatus(): array
    {
        try {
            DB::select('SELECT 1');

            return ['ok' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function liveFeedStatus(string $workspaceId = ''): array
    {
        $path = storage_path('framework/autofinx-market-ws-heartbeat-futures.json');
        if (! file_exists($path)) {
            return [
                'heartbeat_file' => false,
                'stale' => true,
                'age_seconds' => null,
                'detail' => 'Heartbeat file not found. Is live-feed supervisor process running?',
            ];
        }

        $raw = @file_get_contents($path);
        $data = $raw !== false ? @json_decode($raw, true) : null;

        // Aggregate (top-level "whichever symbol ticked most recently across
        // every stream this daemon subscribes to") fields — kept exactly as
        // before, but no longer the sole signal once a specific futures
        // symbol is actually required (see requiredSymbols below). A fresh
        // unrelated symbol must never mask a genuinely stale required one,
        // and a stale unrelated symbol must never flag a healthy required
        // one either.
        $epoch = is_array($data) ? (int) ($data['last_message_epoch'] ?? 0) : 0;
        $age = $epoch > 0 ? (time() - $epoch) : null;
        $aggregateStale = $age === null || $age > 20;

        $perSymbolData = is_array($data) && is_array($data['per_symbol'] ?? null) ? $data['per_symbol'] : [];
        $requiredSymbols = $this->requiredFuturesSymbols($workspaceId);
        $perSymbolStatus = [];
        foreach ($requiredSymbols as $symbol) {
            $entry = $perSymbolData[$symbol] ?? null;
            $entryEpoch = is_array($entry) ? (int) ($entry['last_message_epoch'] ?? 0) : 0;
            $entryAge = $entryEpoch > 0 ? max(0, time() - $entryEpoch) : null;
            $perSymbolStatus[$symbol] = [
                'age_seconds' => $entryAge,
                'stale' => $entryAge === null || $entryAge > 20,
                'last_message_at' => is_array($entry) ? ($entry['last_message_at'] ?? null) : null,
            ];
        }

        $stale = $perSymbolStatus !== []
            ? collect($perSymbolStatus)->contains(static fn (array $status): bool => $status['stale'])
            : $aggregateStale;

        return [
            'heartbeat_file' => true,
            'stale' => $stale,
            'age_seconds' => $age,
            'last_message_at' => $data['last_message_at'] ?? null,
            'symbol' => $data['symbol'] ?? null,
            'stream' => $data['stream'] ?? null,
            'reconnect_count' => $data['reconnect_count'] ?? null,
            'message_count' => $data['message_count'] ?? null,
            'connected_at' => $data['connected_at'] ?? null,
            'market_type' => $data['market_type'] ?? null,
            'required_symbols' => $requiredSymbols,
            'per_symbol' => $perSymbolStatus,
        ];
    }

    /**
     * Same "which symbols does an enabled Future Trade bot actually need"
     * logic RealtimeMarketFeedService::selectedSymbols()/
     * touchWorkspaceFeedStatuses() already use — mergedSettings() folds in
     * config('future_trade.defaults.symbols') and per-bot presets, so a bot
     * with no explicit settings->symbols override still targets the default
     * list.
     *
     * @return string[]
     */
    private function requiredFuturesSymbols(string $workspaceId): array
    {
        return FutureTradeBotConfig::query()
            ->with('state')
            ->where('enabled', true)
            ->when($workspaceId !== '', fn ($q) => $q->where('workspace_id', $workspaceId))
            ->get()
            ->flatMap(function (FutureTradeBotConfig $config): array {
                $settings = $config->mergedSettings();
                $symbols = array_map(
                    static fn ($value) => strtoupper(trim((string) $value)),
                    (array) ($settings['symbols'] ?? config('future_trade.defaults.symbols', []))
                );
                $currentSymbol = strtoupper(trim((string) ($config->state?->current_symbol ?? '')));
                if ($currentSymbol !== '') {
                    $symbols[] = $currentSymbol;
                }

                return array_values(array_filter($symbols));
            })
            ->unique()
            ->values()
            ->all();
    }

    private function userStreamStatus(string $workspaceId): array
    {
        $query = FutureTradeBotConfig::query()
            ->where('enable_live_trading', true)
            ->where('slot_index', 0)
            ->when($workspaceId !== '', fn ($q) => $q->where('workspace_id', $workspaceId));

        $result = [];
        $service = app(FutureTradeUserStreamService::class);
        foreach ($query->get() as $config) {
            $snapshot = $service->snapshot($config);

            $result[] = [
                'workspace_id' => $config->workspace_id,
                'config_id' => $config->id,
                'slot_index' => $config->slot_index,
                'status' => (string) ($snapshot['status'] ?? 'UNKNOWN'),
                'configured' => (bool) ($snapshot['configured'] ?? false),
                'credential_source' => (string) ($snapshot['credentialSource'] ?? 'none'),
                'credential_reason' => $snapshot['credentialReason'] ?? null,
                'integration_id' => $snapshot['integrationId'] ?? null,
                'heartbeat_age_s' => $snapshot['heartbeatAgeSeconds'] ?? null,
                'fresh' => (bool) ($snapshot['fresh'] ?? false),
            ];
        }

        return $result;
    }

    private function accountSyncStatus(string $workspaceId): array
    {
        $workspaces = FutureTradeBotConfig::query()
            ->where('enable_live_trading', true)
            ->when($workspaceId !== '', fn ($q) => $q->where('workspace_id', $workspaceId))
            ->pluck('workspace_id')
            ->unique()
            ->values();

        $sync = app(FutureTradeAccountSyncService::class);
        $result = [];
        foreach ($workspaces as $id) {
            $state = $sync->snapshot((string) $id);
            $lastSyncAt = $state['last_sync_at'] ?? null;
            $state['age_seconds'] = $lastSyncAt ? max(0, time() - strtotime((string) $lastSyncAt)) : null;
            $result[] = $state;
        }

        return $result;
    }

    private function queueStatus(): array
    {
        $queues = [
            'critical', 'execution', 'reconcile', 'monitoring',
            'market', 'signals', 'strategies', 'ratio_bot',
            'ops', 'monitoring_safe', 'analytics', 'notifications', 'default',
        ];

        $result = [];
        foreach ($queues as $queue) {
            try {
                $pending = DB::table('jobs')->where('queue', $queue)->count();
                $failed = DB::table('failed_jobs')->where('queue', $queue)->count();
                $oldest = DB::table('jobs')->where('queue', $queue)->min('created_at');
                $ageS = $oldest ? $this->jobTimestampAgeSeconds($oldest) : null;
                $result[$queue] = [
                    'pending' => $pending,
                    'failed' => $failed,
                    'oldest_age_s' => $ageS,
                ];
            } catch (Throwable) {
                $result[$queue] = ['error' => 'unavailable'];
            }
        }

        return $result;
    }

    private function jobTimestampAgeSeconds(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return max(0, time() - (int) $value);
        }

        try {
            return max(0, now()->diffInSeconds(Carbon::parse((string) $value), true));
        } catch (Throwable) {
            return null;
        }
    }

    private function positionStatus(string $workspaceId): array
    {
        try {
            $query = FutureTradeLogicalTrade::query()
                ->whereIn('status', ['OPEN', 'CLOSING'])
                ->when($workspaceId !== '', function ($q) use ($workspaceId) {
                    $q->whereHas('config', fn ($c) => $c->where('workspace_id', $workspaceId));
                });

            return [
                'open_count' => $query->count(),
                'open_symbols' => $query->pluck('symbol')->unique()->values()->all(),
            ];
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function configStatus(string $workspaceId): array
    {
        $query = FutureTradeBotConfig::query()
            ->with('state')
            ->when($workspaceId !== '', fn ($q) => $q->where('workspace_id', $workspaceId));

        $result = [];
        foreach ($query->get() as $config) {
            $settings = $config->mergedSettings();
            $result[] = [
                'workspace_id' => $config->workspace_id,
                'slot_index' => $config->slot_index,
                'enabled' => $config->enabled,
                'execution_mode' => $config->executionMode(),
                'live_trading' => $config->enable_live_trading,
                'state' => $config->state?->state,
                'status' => $config->state?->status,
                'current_symbol' => $config->state?->current_symbol,
                'last_scan_at' => $config->state?->last_scan_at,
                'signal_source' => $settings['signalSource'] ?? 'mirror',
                'symbols' => $settings['symbols'] ?? [],
            ];
        }

        return $result;
    }

    private function lastWebhookStatus(string $workspaceId): array
    {
        try {
            $cacheKey = 'future_trade_last_pine_webhook_payload'.($workspaceId !== '' ? ':'.$workspaceId : '');
            $payload = Cache::get($cacheKey);
            if (! is_array($payload)) {
                return ['received_at' => null, 'age_seconds' => null];
            }

            $receivedAt = $payload['receivedAt'] ?? $payload['received_at'] ?? null;
            $age = $receivedAt ? max(0, time() - strtotime((string) $receivedAt)) : null;

            return [
                'received_at' => $receivedAt,
                'age_seconds' => $age,
                'symbol' => $payload['symbol'] ?? null,
                'signal' => $payload['signal'] ?? $payload['action'] ?? null,
                'source' => $payload['source'] ?? null,
            ];
        } catch (Throwable) {
            return ['received_at' => null, 'age_seconds' => null];
        }
    }

    private function lastWebhookEventStatus(string $workspaceId): array
    {
        try {
            $configIds = FutureTradeBotConfig::query()
                ->when($workspaceId !== '', fn ($q) => $q->where('workspace_id', $workspaceId))
                ->pluck('id');

            if ($configIds->isEmpty()) {
                return ['event' => null, 'reason' => null];
            }

            $row = FutureTradeBotLog::query()
                ->whereIn('future_trade_bot_config_id', $configIds)
                ->whereIn('event', [
                    'WEBHOOK_RAW_RECEIVED',
                    'PINE_WEBHOOK_SIGNAL',
                    'PINE_WEBHOOK_WAIT',
                    'PINE_WEBHOOK_CLOSE',
                    'PINE_WEBHOOK_REJECTED',
                    'DUPLICATE_WEBHOOK_IGNORED',
                    'TRADE_BLOCKED',
                    'SIGNAL_SKIPPED',
                    'BUY_EXECUTED',
                    'SELL_EXECUTED',
                    'LIVE_BUY_EXECUTED',
                    'LIVE_SELL_EXECUTED',
                ])
                ->latest('occurred_at')
                ->first();

            if (! $row) {
                return ['event' => null, 'reason' => null];
            }

            $context = is_array($row->context) ? $row->context : [];

            return [
                'event' => $row->event,
                'reason' => $row->reason,
                'symbol' => $row->symbol,
                'signal' => $row->signal,
                'occurred_at' => $row->occurred_at?->toIso8601String(),
                'source' => $context['source'] ?? null,
                'signal_source' => $context['signalSource'] ?? $context['signal_source'] ?? null,
                'slot_routing' => $context['slotRouting'] ?? null,
                'execution' => $context['execution'] ?? null,
                'payload_profile' => $context['payloadProfile'] ?? ($context['payload']['profile'] ?? null),
                'payload_timeframe' => $context['timeframe'] ?? ($context['payload']['timeframe'] ?? null),
                'active_position_symbol' => $context['activePositionSymbol'] ?? null,
                'active_position_side' => $context['activePositionSide'] ?? null,
                'webhook_timing' => $context['webhookTiming'] ?? null,
            ];
        } catch (Throwable $e) {
            return ['event' => null, 'reason' => null, 'error' => $e->getMessage()];
        }
    }

    private function unexpectedProcessStatus(): array
    {
        if (PHP_OS_FAMILY === 'Windows' || ! function_exists('shell_exec')) {
            return [
                'checked' => false,
                'unexpected_legacy_worker_detected' => false,
                'matches' => [],
                'detail' => 'Process scan is unavailable on this runtime.',
            ];
        }

        // Keep the full command line. Without -ww, ps truncates long
        // Supervisor commands before the managed-process allowlist arguments
        // (for example Ratio Bot's --market-type/--store-ohlc) are visible.
        $output = (string) shell_exec('ps -ww -eo pid=,args= 2>/dev/null');
        $badPatterns = [
            'artisan queue:work --queue=critical,execution,reconcile,monitoring,market',
            'artisan market:ws-consume',
            'artisan metals:poll-prices',
            'artisan future-trade:reconcile',
            'artisan future-trade:diagnostics',
        ];
        // Ratio Bot owns a Supervisor-managed spot consumer with this exact
        // command shape. It must remain running alongside Future Trade's
        // separate live-feed process; only unknown/legacy consumers should
        // be reported here.
        $allowedManagedPatterns = [
            'artisan market:ws-consume --market-type=spot --store-ohlc',
        ];

        $matches = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $isAllowedManagedProcess = false;
            foreach ($allowedManagedPatterns as $pattern) {
                if (str_contains($line, $pattern)) {
                    $isAllowedManagedProcess = true;
                    break;
                }
            }

            if ($isAllowedManagedProcess) {
                continue;
            }

            foreach ($badPatterns as $pattern) {
                if (str_contains($line, $pattern)) {
                    $matches[] = trim($line);
                    break;
                }
            }
        }

        return [
            'checked' => true,
            'unexpected_legacy_worker_detected' => $matches !== [],
            'matches' => array_values(array_unique($matches)),
        ];
    }

    private function renderHuman(array $status): void
    {
        $ok = '<fg=green>OK</>';
        $err = '<fg=red>FAIL</>';
        $warn = '<fg=yellow>WARN</>';

        $this->newLine();
        $this->line('<options=bold>══ AutoFinX Future Trade — Runtime Status ══</>');
        $this->line('Generated: '.$status['generated_at']);
        $this->newLine();

        $this->line('<options=bold>[ Runtime Ownership ]</>');
        $runtime = $status['runtime'];
        $scheduler = $status['scheduler'];
        $this->line('  Runtime owner      : '.($runtime['owner'] ?? 'unknown'));
        $this->line('  Supervisor mode    : '.(($runtime['supervisor'] ?? false) ? $ok : $warn));
        $this->line('  Scheduler enabled  : '.(($scheduler['enabled'] ?? false) ? $ok : $warn));
        $this->line('  Market consumers   : '.(($scheduler['market_consumers'] ?? false) ? $warn : $ok.' disabled'));
        $this->line('  Binance REST sched : '.(($scheduler['binance_rest'] ?? false) ? $warn : $ok.' disabled'));
        $this->line('  FT reconcile sched : '.(($scheduler['future_trade_reconcile'] ?? false) ? $warn : $ok.' disabled'));
        $this->line('  FT diagnostics     : '.(($scheduler['future_trade_diagnostics'] ?? false) ? $warn : $ok.' disabled'));
        $this->newLine();

        // Redis / DB
        $this->line('<options=bold>[ Infrastructure ]</>');
        $redis = $status['redis'];
        $db = $status['database'];
        $this->line('  Redis   : '.($redis['ok'] ? $ok : $err.' '.($redis['error'] ?? '')));
        $this->line('  Database: '.($db['ok'] ? $ok : $err.' '.($db['error'] ?? '')));
        $rest = $status['binance_rest'];
        $this->line('  Binance REST circuit: '.(($rest['open'] ?? false) ? $err.' open '.($rest['remaining_seconds'] ?? '?').'s' : $ok));
        if (! empty($rest['last_error'])) {
            $this->line('  Binance REST last error: '.$rest['last_error']);
        }
        $this->newLine();

        // Live Feed
        $this->line('<options=bold>[ Live Feed (market:ws-consume) ]</>');
        $feed = $status['live_feed'];
        if (! $feed['heartbeat_file']) {
            $this->line('  Status    : '.$err.' — no heartbeat file (is live-feed supervisor process running?)');
        } else {
            $age = $feed['age_seconds'];
            $label = $age === null ? $err : ($age <= 10 ? $ok : ($age <= 20 ? $warn : $err));
            $this->line('  Status    : '.$label);
            $this->line('  Age       : '.($age !== null ? $age.' s' : 'unknown'));
            $this->line('  Last msg  : '.($feed['last_message_at'] ?? 'n/a'));
            $this->line('  Symbol    : '.($feed['symbol'] ?? 'n/a').'  stream='.($feed['stream'] ?? 'n/a'));
            $this->line('  Reconnects: '.($feed['reconnect_count'] ?? 'n/a'));
            $this->line('  Msg count : '.($feed['message_count'] ?? 'n/a'));
        }
        $this->newLine();

        $this->line('<options=bold>[ Unexpected Processes ]</>');
        $processes = $status['unexpected_processes'];
        if (! ($processes['checked'] ?? false)) {
            $this->line('  '.$warn.' '.($processes['detail'] ?? 'not checked'));
        } elseif ($processes['unexpected_legacy_worker_detected'] ?? false) {
            $this->line('  '.$err.' unexpected legacy/runtime process detected');
            foreach ((array) ($processes['matches'] ?? []) as $match) {
                $this->line('  - '.$match);
            }
        } else {
            $this->line('  '.$ok.' no unexpected legacy runtime processes detected');
        }
        $this->newLine();

        // User Streams
        $this->line('<options=bold>[ User Streams ]</>');
        $streams = $status['user_stream'];
        if (empty($streams)) {
            $this->line('  No live-enabled configs found.');
        }
        foreach ($streams as $s) {
            $label = ($s['status'] === 'CONNECTED' && $s['fresh']) ? $ok : $warn;
            $this->line(sprintf(
                '  [%s] workspace=%s slot=%s status=%s configured=%s source=%s age=%ss',
                $label,
                $s['workspace_id'],
                $s['config_id'],
                $s['status'],
                ($s['configured'] ?? false) ? 'yes' : 'no',
                $s['credential_source'] ?? 'none',
                $s['heartbeat_age_s'] ?? '?'
            ));
            if (! empty($s['credential_reason'])) {
                $this->line('       reason='.$s['credential_reason']);
            }
        }
        $this->newLine();

        $this->line('<options=bold>[ Account Sync ]</>');
        $accountSync = $status['account_sync'] ?? [];
        if (empty($accountSync)) {
            $this->line('  No live-enabled workspace account sync state found.');
        }
        foreach ($accountSync as $row) {
            $age = $row['age_seconds'] ?? null;
            $rowStatus = (string) ($row['status'] ?? 'UNKNOWN');
            $label = (($row['ok'] ?? false) && $age !== null && $age <= 900) ? $ok : ($rowStatus === 'UNKNOWN' ? $warn : $err);
            $this->line(sprintf(
                '  [%s] workspace=%s status=%s source=%s age=%ss wallet=%s available=%s unrealized=%s positions=%s',
                $label,
                $row['workspace_id'] ?? 'n/a',
                $rowStatus,
                $row['credential_source'] ?? 'n/a',
                $age ?? '?',
                $row['wallet_balance'] ?? '-',
                $row['available_balance'] ?? '-',
                $row['unrealized_pnl'] ?? '-',
                $row['open_position_count'] ?? '-'
            ));
            if (! empty($row['reason'])) {
                $this->line('       reason='.$row['reason']);
            }
        }
        $this->newLine();

        // Queues (only show non-zero or failed)
        $this->line('<options=bold>[ Queues ]</>');
        foreach ($status['queues'] as $q => $info) {
            if (isset($info['error'])) {
                $this->line("  {$q}: ".$err.' '.$info['error']);

                continue;
            }
            $pending = (int) ($info['pending'] ?? 0);
            $failed = (int) ($info['failed'] ?? 0);
            if ($pending > 0 || $failed > 0) {
                $label = $failed > 0 ? $err : ($pending > 50 ? $warn : $ok);
                $this->line(sprintf('  %s %-20s pending=%-4d failed=%-4d oldest=%ss',
                    $label, $q, $pending, $failed, $info['oldest_age_s'] ?? '?'));
            }
        }
        $this->line('  (queues with 0 pending / 0 failed are hidden)');
        $this->newLine();

        // Open Positions
        $this->line('<options=bold>[ Open Positions ]</>');
        $pos = $status['positions'];
        if (isset($pos['error'])) {
            $this->line('  '.$err.' '.$pos['error']);
        } else {
            $this->line('  Count  : '.($pos['open_count'] ?? 0));
            $this->line('  Symbols: '.implode(', ', $pos['open_symbols'] ?? []));
        }
        $this->newLine();

        // Configs
        $this->line('<options=bold>[ Bot Configs ]</>');
        foreach ($status['configs'] as $cfg) {
            $enabledLabel = $cfg['enabled'] ? $ok : $warn;
            $this->line(sprintf(
                '  [%s] workspace=%-36s slot=%d mode=%-8s source=%-12s state=%s sym=%s',
                $enabledLabel,
                $cfg['workspace_id'],
                $cfg['slot_index'],
                $cfg['execution_mode'],
                $cfg['signal_source'],
                $cfg['state'] ?? 'n/a',
                implode(',', (array) ($cfg['symbols'] ?? []))
            ));
        }
        $this->newLine();

        // Last Webhook
        $this->line('<options=bold>[ Last TradingView Webhook ]</>');
        $wh = $status['last_webhook'];
        if ($wh['received_at'] === null) {
            $this->line('  No recent webhook recorded.');
        } else {
            $this->line('  Received : '.$wh['received_at'].' ('.($wh['age_seconds'] ?? '?').' s ago)');
            $this->line('  Symbol   : '.($wh['symbol'] ?? 'n/a').'  signal='.($wh['signal'] ?? 'n/a'));
        }
        $event = $status['last_webhook_event'] ?? [];
        if (($event['event'] ?? null) !== null) {
            $this->line('  Last event: '.($event['event'] ?? 'n/a').' at '.($event['occurred_at'] ?? 'n/a'));
            $this->line('  Reason    : '.($event['reason'] ?? 'n/a'));
            $this->line('  Event sig : '.($event['symbol'] ?? 'n/a').' '.($event['signal'] ?? 'n/a'));
            if (($event['payload_profile'] ?? null) !== null || ($event['signal_source'] ?? null) !== null) {
                $this->line('  Debug     : profile='.($event['payload_profile'] ?? 'n/a').' source='.($event['signal_source'] ?? 'n/a'));
            }
        }
        $this->newLine();
    }
}
