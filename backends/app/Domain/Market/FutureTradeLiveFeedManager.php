<?php

namespace App\Domain\Market;

use App\Models\FutureTradeBotConfig;
use App\Models\MarketFeedStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use RuntimeException;

class FutureTradeLiveFeedManager
{
    private const STREAMS = 'trade,kline_1m,kline_5m,kline_15m,kline_1h,kline_4h';

    public function status(?string $workspaceId = null): array
    {
        $meta = $this->loadMeta();
        $desiredSymbols = $this->desiredSymbols();
        $pid = (int) ($meta['pid'] ?? 0);
        $running = $pid > 0 && $this->isProcessRunning($pid);

        if (! $running && $meta !== null) {
            $this->clearMeta();
            $meta = null;
            $pid = 0;
        }

        $latestFeed = MarketFeedStatus::query()
            ->when($workspaceId, fn ($query) => $query->where('workspace_id', $workspaceId))
            ->where('market_type', 'futures')
            ->whereNotNull('last_tick_at')
            ->orderByDesc('last_tick_at')
            ->first();

        $lastTickAt = $latestFeed?->last_tick_at;
        $ageSeconds = $lastTickAt ? now()->diffInSeconds($lastTickAt) : null;
        $isFresh = $ageSeconds !== null && $ageSeconds <= 12;
        $runningSymbols = array_values(array_filter(array_map(
            static fn ($symbol) => strtoupper(trim((string) $symbol)),
            (array) ($meta['symbols'] ?? [])
        )));
        sort($runningSymbols);
        $expectedSymbols = $desiredSymbols;
        sort($expectedSymbols);
        $symbolSetInSync = $runningSymbols === $expectedSymbols;

        $mode = $running && $isFresh
            ? 'TRUE_WEBSOCKET_CACHE_LIVE'
            : ($running ? 'WEBSOCKET_CONNECTED_AWAITING_FRESH_TICK' : 'REST_FALLBACK');

        return [
            'running' => $running,
            'startSupported' => $this->canSpawnDaemon(),
            'pid' => $pid > 0 ? $pid : null,
            'mode' => $mode,
            'dataSource' => $running ? 'BINANCE_FUTURES_WEBSOCKET' : 'BINANCE_FUTURES_REST_FALLBACK',
            'startedAt' => $meta['started_at'] ?? null,
            'command' => $meta['command'] ?? $this->commandLine($desiredSymbols),
            'manualCommand' => $this->manualStartCommand($desiredSymbols),
            'logPath' => $meta['log_path'] ?? $this->outLogPath(),
            'errorLogPath' => $meta['error_log_path'] ?? $this->errLogPath(),
            'workspaceScope' => 'global_shared',
            'lastTickAt' => $lastTickAt?->toIso8601String(),
            'ageSeconds' => $ageSeconds,
            'isFresh' => $isFresh,
            'desiredSymbols' => $desiredSymbols,
            'runningSymbols' => $runningSymbols,
            'symbolSetInSync' => $symbolSetInSync,
            'restartRecommended' => $running && ! $symbolSetInSync,
            'message' => $running
                ? ($symbolSetInSync
                    ? ($isFresh ? 'Shared futures websocket feed is live.' : 'Daemon is running, waiting for a fresh futures tick.')
                    : 'Daemon is running, but symbol list changed. Restart recommended.')
                : ($this->canSpawnDaemon()
                    ? 'Daemon is offline. UI is using REST fallback.'
                    : 'Daemon is offline. shell_exec is disabled on this server, so start it manually from terminal.'),
        ];
    }

    public function start(): array
    {
        $status = $this->status();
        if ($status['running']) {
            return $status;
        }
        if (! $this->canSpawnDaemon()) {
            $status['message'] = 'shell_exec is disabled on this server. Start the daemon manually using the provided command.';
            $status['manualRequired'] = true;

            return $status;
        }

        $symbols = $this->desiredSymbols();
        $meta = $this->spawn($symbols);
        $this->storeMeta($meta);

        sleep(1);

        return $this->status();
    }

    public function stop(): array
    {
        $meta = $this->loadMeta();
        if ($meta === null) {
            return $this->status();
        }

        $pid = (int) ($meta['pid'] ?? 0);
        if ($pid > 0) {
            $this->terminate($pid);
        }

        $this->clearMeta();

        return $this->status();
    }

    public function restart(): array
    {
        $this->stop();

        return $this->start();
    }

    /**
     * @return string[]
     */
    private function desiredSymbols(): array
    {
        $symbols = FutureTradeBotConfig::query()
            ->with('state')
            ->get()
            ->flatMap(function (FutureTradeBotConfig $config): array {
                $settings = $config->mergedSettings();
                $configured = array_map(
                    static fn ($symbol) => strtoupper(trim((string) $symbol)),
                    (array) ($settings['symbols'] ?? config('future_trade.defaults.symbols', []))
                );
                $currentSymbol = strtoupper(trim((string) ($config->state?->current_symbol ?? '')));
                if ($currentSymbol !== '') {
                    $configured[] = $currentSymbol;
                }

                return array_values(array_filter($configured));
            })
            ->unique()
            ->values()
            ->all();

        return $symbols !== [] ? $symbols : ['BTCUSDT', 'ETHUSDT'];
    }

    /**
     * @param  string[]  $symbols
     * @return array<string, mixed>
     */
    private function spawn(array $symbols): array
    {
        $this->ensureRuntimePaths();

        $command = $this->commandLine($symbols);
        $pid = PHP_OS_FAMILY === 'Windows'
            ? $this->spawnWindows($symbols)
            : $this->spawnUnix($symbols);

        if ($pid <= 0) {
            throw new RuntimeException('Unable to start Future Trade live feed daemon.');
        }

        return [
            'pid' => $pid,
            'command' => $command,
            'symbols' => $symbols,
            'started_at' => now()->toIso8601String(),
            'log_path' => $this->outLogPath(),
            'error_log_path' => $this->errLogPath(),
        ];
    }

    /**
     * @param  string[]  $symbols
     */
    private function spawnWindows(array $symbols): int
    {
        $php = str_replace("'", "''", $this->phpBinary());
        $artisan = str_replace("'", "''", base_path('artisan'));
        $outLog = str_replace("'", "''", $this->outLogPath());
        $errLog = str_replace("'", "''", $this->errLogPath());

        $args = [
            $artisan,
            'future-trade:live-feed',
            '--symbols=' . implode(',', $symbols),
            '--streams=' . self::STREAMS,
            '--reconnect-sleep=2',
        ];
        $argList = implode(',', array_map(
            static fn (string $arg) => "'" . str_replace("'", "''", $arg) . "'",
            $args
        ));

        $script = "\$p = Start-Process -FilePath '{$php}' -ArgumentList {$argList} -WorkingDirectory '" . str_replace("'", "''", base_path()) . "' -WindowStyle Hidden -RedirectStandardOutput '{$outLog}' -RedirectStandardError '{$errLog}' -PassThru; \$p.Id";
        $command = 'powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -Command "' . str_replace('"', '\"', $script) . '"';
        $output = $this->runShellCommand($command);

        return (int) trim((string) $output);
    }

    /**
     * @param  string[]  $symbols
     */
    private function spawnUnix(array $symbols): int
    {
        $php = escapeshellarg($this->phpBinary());
        $artisan = escapeshellarg(base_path('artisan'));
        $symbolsArg = escapeshellarg(implode(',', $symbols));
        $outLog = escapeshellarg($this->outLogPath());
        $errLog = escapeshellarg($this->errLogPath());
        $cwd = escapeshellarg(base_path());

        $command = "cd {$cwd} && nohup {$php} {$artisan} future-trade:live-feed --symbols={$symbolsArg} --streams=" . escapeshellarg(self::STREAMS) . " --reconnect-sleep=2 >> {$outLog} 2>> {$errLog} & echo $!";
        $output = $this->runShellCommand("bash -lc " . escapeshellarg($command));

        return (int) trim((string) $output);
    }

    private function terminate(int $pid): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->runShellCommand('taskkill /PID ' . $pid . ' /T /F');

            return;
        }

        if (function_exists('posix_kill')) {
            @posix_kill($pid, SIGTERM);
            usleep(500000);
            if ($this->isProcessRunning($pid)) {
                @posix_kill($pid, SIGKILL);
            }

            return;
        }

        $this->runShellCommand('kill -TERM ' . $pid);
    }

    private function isProcessRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $output = $this->runShellCommand('tasklist /FI "PID eq ' . $pid . '" /FO CSV /NH');

            return is_string($output) && str_contains($output, '"' . $pid . '"');
        }

        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        $output = $this->runShellCommand('ps -p ' . $pid . ' -o pid=');

        return trim((string) $output) !== '';
    }

    private function canSpawnDaemon(): bool
    {
        return \function_exists('shell_exec');
    }

    private function runShellCommand(string $command): ?string
    {
        if (! \function_exists('shell_exec')) {
            return null;
        }

        return \shell_exec($command);
    }

    private function ensureRuntimePaths(): void
    {
        File::ensureDirectoryExists(dirname($this->metaPath()));
        File::ensureDirectoryExists(dirname($this->outLogPath()));
    }

    private function metaPath(): string
    {
        return storage_path('app/runtime/future-trade-live-feed.json');
    }

    private function outLogPath(): string
    {
        return storage_path('logs/future-trade-live-feed.log');
    }

    private function errLogPath(): string
    {
        return storage_path('logs/future-trade-live-feed.error.log');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadMeta(): ?array
    {
        if (! File::exists($this->metaPath())) {
            return null;
        }

        $decoded = json_decode((string) File::get($this->metaPath()), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function storeMeta(array $meta): void
    {
        $this->ensureRuntimePaths();
        File::put($this->metaPath(), json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function clearMeta(): void
    {
        if (File::exists($this->metaPath())) {
            File::delete($this->metaPath());
        }
    }

    /**
     * @param  string[]  $symbols
     */
    private function commandLine(array $symbols): string
    {
        return trim(sprintf(
            '%s %s future-trade:live-feed --symbols=%s --streams=%s --reconnect-sleep=2',
            $this->phpBinary(),
            base_path('artisan'),
            implode(',', $symbols),
            self::STREAMS
        ));
    }

    /**
     * @param  string[]  $symbols
     */
    private function manualStartCommand(array $symbols): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return $this->commandLine($symbols);
        }

        return trim(sprintf(
            'cd %s && nohup %s %s future-trade:live-feed --symbols=%s --streams=%s --reconnect-sleep=2 >> %s 2>> %s &',
            base_path(),
            $this->phpBinary(),
            base_path('artisan'),
            implode(',', $symbols),
            self::STREAMS,
            $this->outLogPath(),
            $this->errLogPath()
        ));
    }

    private function phpBinary(): string
    {
        return PHP_BINARY ?: 'php';
    }
}
