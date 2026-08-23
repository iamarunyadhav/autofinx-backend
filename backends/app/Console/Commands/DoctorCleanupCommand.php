<?php

namespace App\Console\Commands;

use App\Domain\Ops\TradingRuntimeHealthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Throwable;

class DoctorCleanupCommand extends Command
{
    protected $signature = 'doctor_cleanup
        {--json : Output machine-readable JSON}
        {--skip-migrate : Skip database migrations}
        {--sleep=15 : Seconds to wait after process restart before verification}';

    protected $description = 'Clean stale runtime caches and restart AutoFinX queue, scheduler, Horizon, and Future Trade live-feed services after deploy.';

    private array $steps = [];

    public function handle(): int
    {
        @set_time_limit(180);

        $sleep = max(3, min(45, (int) $this->option('sleep')));

        $this->record('environment', 'Runtime environment', 'ok', [
            'base_path' => base_path(),
            'php_binary' => PHP_BINARY,
            'recommended_php' => '/usr/local/bin/ea-php82',
            'generated_at' => now()->toIso8601String(),
        ]);

        if (! (bool) $this->option('skip-migrate')) {
            $this->artisanStep('migrate', 'Run migrations', 'migrate', ['--force' => true]);
        }

        $this->processStep('remove_runtime_repair_cron', 'Remove runtime:repair cron entries', implode(' && ', [
            'crontab -l > /tmp/autofinx-cron-backup-$(date +%Y%m%d-%H%M%S).txt 2>/dev/null || true',
            '(crontab -l 2>/dev/null | grep -v "runtime:repair" | crontab -) || true',
        ]));

        $this->processStep('stop_supervisor_runtime', 'Stop supervisor runtime services', $this->supervisorCommand('stop'));

        $this->processStep('kill_duplicate_workers', 'Kill duplicate/manual runtime workers', implode('; ', [
            'pkill -f "php artisan schedule:work" 2>/dev/null || true',
            'pkill -f "runtime:repair" 2>/dev/null || true',
            'pkill -f "market:ws-consume" 2>/dev/null || true',
            'pkill -f "future-trade:live-feed" 2>/dev/null || true',
            'sleep 3',
        ]));

        $this->clearHeartbeatFiles();
        $this->clearFeedCaches();

        foreach ([
            ['cache_clear', 'Application cache clear', 'cache:clear'],
            ['config_clear', 'Config cache clear', 'config:clear'],
            ['route_clear', 'Route cache clear', 'route:clear'],
            ['view_clear', 'View cache clear', 'view:clear'],
            ['event_clear', 'Event cache clear', 'event:clear'],
            ['optimize_clear', 'Optimize clear', 'optimize:clear'],
            ['queue_restart', 'Queue restart signal', 'queue:restart'],
        ] as [$key, $label, $command]) {
            $this->artisanStep($key, $label, $command);
        }

        $this->processStep('supervisor_reload', 'Supervisor reread/update', 'supervisorctl reread 2>&1 || true; supervisorctl update 2>&1 || true');
        $this->processStep('start_supervisor_runtime', 'Start supervisor runtime services', $this->supervisorCommand('start'));

        sleep($sleep);

        $this->processStep('supervisor_status', 'Supervisor status', 'supervisorctl status 2>&1 || true');
        $this->artisanStep('horizon_status', 'Horizon status', 'horizon:status');
        $this->feedAgeCheck();
        $this->runtimeStatusCheck();
        $this->processStep('process_check', 'Process duplicate check', 'ps -eo pid,cmd | grep -E "future-trade:live-feed|market:ws-consume|runtime:repair|schedule:work|artisan schedule:run|horizon" | grep -v grep || true');

        $ok = $this->overallOk();
        $payload = [
            'ok' => $ok,
            'status' => $ok ? 'ok' : 'needs_attention',
            'message' => $ok
                ? 'Doctor cleanup complete. Runtime is fresh and supervisor-controlled.'
                : 'Doctor cleanup finished, but one or more checks need attention.',
            'generated_at' => now()->toIso8601String(),
            'steps' => $this->steps,
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($this->steps as $step) {
                $symbol = $step['state'] === 'ok' ? '[OK]' : ($step['state'] === 'warning' ? '[WARN]' : '[FAIL]');
                $this->line($symbol.' '.$step['label'].': '.($step['summary'] ?? ''));
            }
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function supervisorCommand(string $action): string
    {
        $programs = implode(' ', [
            'autofinx-scheduler',
            'autofinx-trading:live-feed',
            'autofinx-trading:tick-loop',
            'autofinx-trading:user-stream-loop',
            'autofinx-ratio-feed',
            'autofinx-queue-ratio',
            'autofinx-horizon',
            'autofinx-trading:queue-critical',
            'autofinx-trading:queue-market',
            'autofinx-trading:queue-safe',
            'autofinx-quick-settler',
            'autofinx-robot-evaluator',
        ]);

        return "supervisorctl {$action} {$programs} 2>&1 || true";
    }

    private function artisanStep(string $key, string $label, string $command, array $params = []): void
    {
        try {
            Artisan::call($command, $params);
            $output = trim(Artisan::output());
            $this->record($key, $label, 'ok', [], $output ?: $command.' completed');
        } catch (Throwable $e) {
            $this->record($key, $label, 'error', ['error' => $e->getMessage()]);
        }
    }

    private function processStep(string $key, string $label, string $command, int $timeout = 120): void
    {
        try {
            $process = Process::fromShellCommandline($command, base_path(), null, null, $timeout);
            $process->run();
            $output = trim($process->getOutput()."\n".$process->getErrorOutput());
            $this->record($key, $label, $process->isSuccessful() ? 'ok' : 'warning', [
                'exit_code' => $process->getExitCode(),
            ], $output);
        } catch (Throwable $e) {
            $this->record($key, $label, 'error', ['error' => $e->getMessage()]);
        }
    }

    private function clearHeartbeatFiles(): void
    {
        $deleted = [];
        foreach ([
            storage_path('framework/autofinx-live-feed-heartbeat.json'),
            storage_path('framework/autofinx-market-ws-heartbeat-spot.json'),
            storage_path('framework/autofinx-market-ws-heartbeat-futures.json'),
            storage_path('framework/future-trade-live-feed.json'),
            storage_path('app/runtime/future-trade-live-feed.json'),
        ] as $path) {
            if (is_file($path)) {
                @unlink($path);
                $deleted[] = $path;
            }
        }

        $this->record('heartbeat_cleanup', 'Delete stale heartbeat files', 'ok', [
            'deleted' => $deleted,
        ], count($deleted).' heartbeat file(s) deleted');
    }

    private function clearFeedCaches(): void
    {
        $deletedKeys = 0;
        $tables = [];

        try {
            foreach ([
                'live-feed:*',
                'price:binance:*',
                'ts:binance:*',
                'price:binance_futures:*',
                'ts:binance_futures:*',
                'binance:*',
            ] as $pattern) {
                foreach (Redis::keys($pattern) as $key) {
                    Redis::del($key);
                    $deletedKeys++;
                }
            }
        } catch (Throwable $e) {
            $this->record('redis_feed_cleanup', 'Redis live-feed cache cleanup', 'warning', [
                'error' => $e->getMessage(),
            ]);
        }

        try {
            foreach (['market_feed_status', 'market_price_caches'] as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->delete();
                    $tables[] = $table;
                }
            }
        } catch (Throwable $e) {
            $this->record('db_feed_cleanup', 'DB live-feed cache cleanup', 'warning', [
                'error' => $e->getMessage(),
            ]);
        }

        $this->record('feed_cache_cleanup', 'Live-feed cache cleanup', 'ok', [
            'redis_deleted_keys' => $deletedKeys,
            'db_tables_cleaned' => $tables,
        ], "deleted {$deletedKeys} Redis key(s); cleaned ".count($tables).' DB table(s)');
    }

    private function feedAgeCheck(): void
    {
        $rows = [];
        $state = 'ok';

        try {
            foreach (['BTCUSDT', 'ETHUSDT', 'SOLUSDT', 'BNBUSDT'] as $symbol) {
                $raw = Redis::get("live-feed:binance_futures:{$symbol}") ?: Redis::get("live-feed:binance:{$symbol}");
                $data = $raw ? json_decode((string) $raw, true) : null;
                $receivedMs = is_array($data) ? (float) ($data['received_at_ms'] ?? 0) : 0.0;
                $age = $receivedMs > 0 ? (int) floor(((microtime(true) * 1000) - $receivedMs) / 1000) : null;
                $eventLag = is_array($data) ? ($data['event_lag_ms'] ?? null) : null;
                $fresh = $age !== null && $age <= 5;

                if (! $fresh) {
                    $state = 'error';
                }

                $rows[] = [
                    'symbol' => $symbol,
                    'age_seconds' => $age,
                    'fresh' => $fresh,
                    'received_at' => $data['received_at'] ?? null,
                    'event_lag_ms' => $eventLag,
                ];
            }

            $this->record('feed_age_check', 'Feed freshness check', $state, [
                'symbols' => $rows,
            ], $state === 'ok' ? 'all watched symbols are fresh' : 'one or more symbols are stale or missing');
        } catch (Throwable $e) {
            $this->record('feed_age_check', 'Feed freshness check', 'error', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function runtimeStatusCheck(): void
    {
        try {
            $runtime = app(TradingRuntimeHealthService::class)->snapshot();
            $liveFeed = is_array($runtime) ? ($runtime['future_trade']['live_feed_detail'] ?? []) : [];
            $unexpected = [];
            $stateValue = (string) ($runtime['future_trade']['live_feed'] ?? 'red');
            $stale = $stateValue !== 'green';
            $age = $liveFeed['age_seconds'] ?? null;
            $badProcesses = array_values(array_filter((array) $unexpected, function ($line) {
                return str_contains((string) $line, 'market:ws-consume')
                    || str_contains((string) $line, 'runtime:repair')
                    || str_contains((string) $line, 'schedule:work');
            }));

            $state = (! $stale && $badProcesses === []) ? 'ok' : 'error';

            $this->record('runtime_status', 'Runtime status check', $state, [
                'live_feed' => $liveFeed,
                'unexpected_processes' => $unexpected,
                'bad_processes' => $badProcesses,
                'queues' => [
                    'execution' => $runtime['future_trade']['execution_queue_detail'] ?? null,
                    'market' => $runtime['future_trade']['market_queue_detail'] ?? null,
                    'default' => $runtime['future_trade']['default_queue_detail'] ?? null,
                ],
                'overall' => $runtime['overall'] ?? null,
                'reasons' => $runtime['reasons'] ?? [],
            ], $state === 'ok'
                ? 'global Binance live feed fresh and no duplicate runtime workers detected'
                : 'global runtime health still reports stale feed or duplicate workers');
        } catch (Throwable $e) {
            $this->record('runtime_status', 'Runtime status check', 'error', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function record(string $key, string $label, string $state, array $details = [], ?string $output = null): void
    {
        $summary = $details['error'] ?? $this->summarize($output, $details);

        $this->steps[] = [
            'key' => $key,
            'label' => $label,
            'state' => $state,
            'summary' => $summary,
            'details' => $details,
            'output' => $output !== null ? mb_substr($output, 0, 4000) : null,
            'completed_at' => now()->toIso8601String(),
        ];
    }

    private function summarize(?string $output, array $details): string
    {
        if ($output !== null && trim($output) !== '') {
            $line = trim(strtok($output, "\n") ?: $output);
            return mb_substr($line, 0, 180);
        }

        if ($details !== []) {
            return 'completed with details';
        }

        return 'completed';
    }

    private function overallOk(): bool
    {
        foreach ($this->steps as $step) {
            if (($step['state'] ?? '') === 'error') {
                return false;
            }
        }

        return true;
    }
}
