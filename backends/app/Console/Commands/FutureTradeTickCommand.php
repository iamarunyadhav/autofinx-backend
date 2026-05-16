<?php

namespace App\Console\Commands;

use App\Domain\FutureTrade\FutureTradeBotService;
use App\Models\FutureTradeBotConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FutureTradeTickCommand extends Command
{
    protected $signature = 'future-trade:tick
        {--duration=55 : Bounded loop duration in seconds}
        {--sleep-ms=1000 : Sleep between loop iterations}
        {--workspace= : Optional workspace id}
        {--no-execute : Refresh scanner/state without opening new positions}';

    protected $description = 'Shared-host-safe bounded Future Trade scanner loop for cPanel cron.';

    public function handle(FutureTradeBotService $bot): int
    {
        $duration = max(1, min(58, (int) $this->option('duration')));
        $sleepMs = max(500, min(5000, (int) $this->option('sleep-ms')));
        $workspaceId = trim((string) $this->option('workspace'));
        $allowExecution = ! (bool) $this->option('no-execute');
        $lock = Cache::lock('future-trade:tick-loop' . ($workspaceId !== '' ? ':' . $workspaceId : ''), $duration + 10);

        if (! $lock->get()) {
            $this->warn('[FT TICK SKIPPED] another loop is active');
            Log::info('[FT TICK SKIPPED]', ['workspace_id' => $workspaceId ?: null]);

            return self::SUCCESS;
        }

        $started = microtime(true);
        $loopCount = 0;
        Log::info('[FT TICK STARTED]', [
            'workspace_id' => $workspaceId ?: null,
            'duration' => $duration,
            'sleepMs' => $sleepMs,
            'allowExecution' => $allowExecution,
        ]);

        // Sync slots once per tick run (not every loop iteration) to avoid unnecessary DB overhead
        if ($workspaceId !== '') {
            $bot->syncSlots($bot->getOrCreateConfig($workspaceId));
        } else {
            FutureTradeBotConfig::query()->where('slot_index', 0)->get()
                ->each(fn ($p) => $bot->syncSlots($p));
        }

        try {
            while ((microtime(true) - $started) < $duration) {
                $loopStarted = microtime(true);
                $loopCount++;
                Log::info('[FT TICK LOOP]', ['loopCount' => $loopCount]);

                $configs = $workspaceId !== ''
                    ? FutureTradeBotConfig::query()->with('state')->where('workspace_id', $workspaceId)->orderBy('slot_index')->get()
                    : FutureTradeBotConfig::query()->with('state')->orderBy('slot_index')->get();

                foreach ($configs as $config) {
                    $config = $config->fresh('state');
                    if (! $config || ! $config->enabled) {
                        Log::info('[FT TICK WORKSPACE DISABLED]', [
                            'workspace_id' => $config?->workspace_id ?? $workspaceId ?: null,
                            'enabled' => (bool) ($config?->enabled ?? false),
                        ]);
                        continue;
                    }

                    try {
                        $before = $config->state?->last_scan_at;
                        $result = $bot->scan($config, $allowExecution);
                        $status = $bot->liveStatusLightPayload($config->fresh('state'));
                        Log::info('[FT TICK SCAN]', [
                            'workspace_id' => $config->workspace_id,
                            'enabled' => true,
                            'symbol' => $status['currentSymbol'] ?? null,
                            'tickAgeSeconds' => $status['tickAgeSeconds'] ?? null,
                            'scanAgeSeconds' => $status['scanAgeSeconds'] ?? null,
                            'currentSignal' => $status['currentSignal'] ?? null,
                            'confidence' => $status['currentConfidence'] ?? null,
                            'activeMinimum' => $status['activeMinimum'] ?? null,
                            'changedScanAt' => optional($before)->toIso8601String() !== ($status['lastScanAt'] ?? null),
                            'signalCount' => count((array) ($result['signals'] ?? [])),
                            'durationMs' => (int) round((microtime(true) - $loopStarted) * 1000),
                            'loopCount' => $loopCount,
                        ]);
                    } catch (\Throwable $e) {
                        $this->error('[FT TICK ERROR] ' . $config->workspace_id . ': ' . $e->getMessage());
                        Log::warning('[FT TICK ERROR]', [
                            'workspace_id' => $config->workspace_id,
                            'message' => $e->getMessage(),
                        ]);
                    }
                }

                $elapsed = microtime(true) - $started;
                if (($elapsed + ($sleepMs / 1000)) >= $duration) {
                    break;
                }

                usleep($sleepMs * 1000);
            }
        } finally {
            optional($lock)->release();
        }

        Log::info('[FT TICK FINISHED]', [
            'workspace_id' => $workspaceId ?: null,
            'durationMs' => (int) round((microtime(true) - $started) * 1000),
            'loopCount' => $loopCount,
        ]);

        return self::SUCCESS;
    }
}
