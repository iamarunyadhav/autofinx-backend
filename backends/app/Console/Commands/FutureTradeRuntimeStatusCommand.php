<?php

namespace App\Console\Commands;

use App\Models\FutureTradeBotConfig;
use App\Models\FutureTradeBotState;
use App\Models\FutureTradeLogicalTrade;
use App\Models\MarketFeedStatus;
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
        $asJson      = (bool) $this->option('json');

        $status = [
            'generated_at'  => date('c'),
            'redis'         => $this->redisStatus(),
            'database'      => $this->dbStatus(),
            'live_feed'     => $this->liveFeedStatus(),
            'user_stream'   => $this->userStreamStatus($workspaceId),
            'queues'        => $this->queueStatus(),
            'positions'     => $this->positionStatus($workspaceId),
            'configs'       => $this->configStatus($workspaceId),
            'last_webhook'  => $this->lastWebhookStatus($workspaceId),
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

    private function dbStatus(): array
    {
        try {
            DB::select('SELECT 1');
            return ['ok' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function liveFeedStatus(): array
    {
        $path = storage_path('framework/autofinx-live-feed-heartbeat.json');
        if (! file_exists($path)) {
            return [
                'heartbeat_file' => false,
                'stale'          => true,
                'age_seconds'    => null,
                'detail'         => 'Heartbeat file not found. Is live-feed supervisor process running?',
            ];
        }

        $raw = @file_get_contents($path);
        $data = $raw !== false ? @json_decode($raw, true) : null;
        $epoch = is_array($data) ? (int) ($data['last_message_epoch'] ?? 0) : 0;
        $age   = $epoch > 0 ? (time() - $epoch) : null;
        $stale = $age === null || $age > 20;

        return [
            'heartbeat_file'   => true,
            'stale'            => $stale,
            'age_seconds'      => $age,
            'last_message_at'  => $data['last_message_at'] ?? null,
            'symbol'           => $data['symbol'] ?? null,
            'stream'           => $data['stream'] ?? null,
            'reconnect_count'  => $data['reconnect_count'] ?? null,
            'message_count'    => $data['message_count'] ?? null,
            'connected_at'     => $data['connected_at'] ?? null,
            'market_type'      => $data['market_type'] ?? null,
        ];
    }

    private function userStreamStatus(string $workspaceId): array
    {
        $query = FutureTradeBotConfig::query()
            ->where('enable_live_trading', true)
            ->when($workspaceId !== '', fn ($q) => $q->where('workspace_id', $workspaceId));

        $result = [];
        foreach ($query->get() as $config) {
            $cacheKey = 'autofinx_ft:user_stream:' . $config->id . ':state';
            $state = Cache::get($cacheKey);
            $heartbeatAt = is_array($state) ? ($state['last_heartbeat_at'] ?? null) : null;
            $age = $heartbeatAt ? max(0, time() - strtotime((string) $heartbeatAt)) : null;

            $result[] = [
                'workspace_id'    => $config->workspace_id,
                'config_id'       => $config->id,
                'status'          => is_array($state) ? ($state['status'] ?? 'UNKNOWN') : 'NOT_CONFIGURED',
                'heartbeat_age_s' => $age,
                'fresh'           => $age !== null && $age <= 30,
            ];
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
                $failed  = DB::table('failed_jobs')->where('queue', $queue)->count();
                $oldest  = DB::table('jobs')->where('queue', $queue)->min('created_at');
                $ageS    = $oldest ? (time() - strtotime((string) $oldest)) : null;
                $result[$queue] = [
                    'pending'         => $pending,
                    'failed'          => $failed,
                    'oldest_age_s'    => $ageS,
                ];
            } catch (Throwable) {
                $result[$queue] = ['error' => 'unavailable'];
            }
        }

        return $result;
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
                'open_count'   => $query->count(),
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
                'workspace_id'       => $config->workspace_id,
                'slot_index'         => $config->slot_index,
                'enabled'            => $config->enabled,
                'execution_mode'     => $config->executionMode(),
                'live_trading'       => $config->enable_live_trading,
                'state'              => $config->state?->state,
                'status'             => $config->state?->status,
                'current_symbol'     => $config->state?->current_symbol,
                'last_scan_at'       => $config->state?->last_scan_at,
                'signal_source'      => $settings['signalSource'] ?? 'mirror',
                'symbols'            => $settings['symbols'] ?? [],
            ];
        }

        return $result;
    }

    private function lastWebhookStatus(string $workspaceId): array
    {
        try {
            $cacheKey = 'future_trade_last_pine_webhook_payload' . ($workspaceId !== '' ? ':' . $workspaceId : '');
            $payload  = Cache::get($cacheKey);
            if (! is_array($payload)) {
                return ['received_at' => null, 'age_seconds' => null];
            }

            $receivedAt = $payload['receivedAt'] ?? $payload['received_at'] ?? null;
            $age = $receivedAt ? max(0, time() - strtotime((string) $receivedAt)) : null;

            return [
                'received_at'  => $receivedAt,
                'age_seconds'  => $age,
                'symbol'       => $payload['symbol'] ?? null,
                'signal'       => $payload['signal'] ?? $payload['action'] ?? null,
                'source'       => $payload['source'] ?? null,
            ];
        } catch (Throwable) {
            return ['received_at' => null, 'age_seconds' => null];
        }
    }

    private function renderHuman(array $status): void
    {
        $ok  = '<fg=green>OK</>';
        $err = '<fg=red>FAIL</>';
        $warn = '<fg=yellow>WARN</>';

        $this->newLine();
        $this->line('<options=bold>══ AutoFinX Future Trade — Runtime Status ══</>');
        $this->line('Generated: ' . $status['generated_at']);
        $this->newLine();

        // Redis / DB
        $this->line('<options=bold>[ Infrastructure ]</>');
        $redis = $status['redis'];
        $db    = $status['database'];
        $this->line('  Redis   : ' . ($redis['ok'] ? $ok : $err . ' ' . ($redis['error'] ?? '')));
        $this->line('  Database: ' . ($db['ok'] ? $ok : $err . ' ' . ($db['error'] ?? '')));
        $this->newLine();

        // Live Feed
        $this->line('<options=bold>[ Live Feed (market:ws-consume) ]</>');
        $feed = $status['live_feed'];
        if (! $feed['heartbeat_file']) {
            $this->line('  Status    : ' . $err . ' — no heartbeat file (is live-feed supervisor process running?)');
        } else {
            $age = $feed['age_seconds'];
            $label = $age === null ? $err : ($age <= 10 ? $ok : ($age <= 20 ? $warn : $err));
            $this->line('  Status    : ' . $label);
            $this->line('  Age       : ' . ($age !== null ? $age . ' s' : 'unknown'));
            $this->line('  Last msg  : ' . ($feed['last_message_at'] ?? 'n/a'));
            $this->line('  Symbol    : ' . ($feed['symbol'] ?? 'n/a') . '  stream=' . ($feed['stream'] ?? 'n/a'));
            $this->line('  Reconnects: ' . ($feed['reconnect_count'] ?? 'n/a'));
            $this->line('  Msg count : ' . ($feed['message_count'] ?? 'n/a'));
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
                '  [%s] workspace=%s slot=%s status=%s age=%ss',
                $label,
                $s['workspace_id'],
                $s['config_id'],
                $s['status'],
                $s['heartbeat_age_s'] ?? '?'
            ));
        }
        $this->newLine();

        // Queues (only show non-zero or failed)
        $this->line('<options=bold>[ Queues ]</>');
        foreach ($status['queues'] as $q => $info) {
            if (isset($info['error'])) {
                $this->line("  {$q}: " . $err . ' ' . $info['error']);
                continue;
            }
            $pending = (int) ($info['pending'] ?? 0);
            $failed  = (int) ($info['failed'] ?? 0);
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
            $this->line('  ' . $err . ' ' . $pos['error']);
        } else {
            $this->line('  Count  : ' . ($pos['open_count'] ?? 0));
            $this->line('  Symbols: ' . implode(', ', $pos['open_symbols'] ?? []));
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
            $this->line('  Received : ' . $wh['received_at'] . ' (' . ($wh['age_seconds'] ?? '?') . ' s ago)');
            $this->line('  Symbol   : ' . ($wh['symbol'] ?? 'n/a') . '  signal=' . ($wh['signal'] ?? 'n/a'));
        }
        $this->newLine();
    }
}
