<?php

namespace App\Domain\Market;

use App\Domain\Trading\RuntimeSafetySettingsService;
use App\Models\FutureTradeBotConfig;
use App\Models\MarketFeedStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use RuntimeException;

class FutureTradeLiveFeedManager
{
    private const STREAMS = 'trade,kline_1m,kline_5m,kline_15m,kline_1h,kline_4h,kline_1d';

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
        $heartbeat = $this->loadHeartbeat();

        $lastTickAt = $latestFeed?->last_tick_at;
        $feedAgeSeconds = $this->ageSecondsSince($lastTickAt);
        $latestLiveFeed = $this->freshestLiveFeedSnapshot($desiredSymbols);
        $latestPrice = $this->freshestCachedPrice($desiredSymbols);
        $priceAgeSeconds = $latestLiveFeed['age_seconds'] ?? $latestPrice['age_seconds'] ?? null;
        $ageSeconds = $priceAgeSeconds !== null && $feedAgeSeconds !== null
            ? min((int) $priceAgeSeconds, (int) $feedAgeSeconds)
            : ($priceAgeSeconds ?? $feedAgeSeconds);
        $lastTickAt = ($latestLiveFeed['received_at_ms'] ?? null)
            ? Carbon::createFromTimestampMs((int) $latestLiveFeed['received_at_ms'])
            : (($latestPrice['timestamp_ms'] ?? null)
                ? Carbon::createFromTimestampMs((int) $latestPrice['timestamp_ms'])
                : $lastTickAt);
        $lastTickPrice = isset($latestLiveFeed['price'])
            ? (float) $latestLiveFeed['price']
            : (isset($latestPrice['price'])
            ? (float) $latestPrice['price']
            : ($latestFeed?->last_price !== null ? (float) $latestFeed->last_price : null));
        $maxAgeSeconds = app(RuntimeSafetySettingsService::class)->liveFeedMaxAgeSeconds($workspaceId);
        $isFresh = $ageSeconds !== null && $ageSeconds <= $maxAgeSeconds;
        $runningSymbols = array_values(array_filter(array_map(
            static fn ($symbol) => strtoupper(trim((string) $symbol)),
            (array) ($meta['symbols'] ?? [])
        )));
        sort($runningSymbols);
        $expectedSymbols = $desiredSymbols;
        sort($expectedSymbols);
        $symbolSetInSync = $runningSymbols === $expectedSymbols;

        $mode = $ageSeconds !== null && $ageSeconds <= 10
            ? 'TRUE_WEBSOCKET_CACHE_LIVE'
            : ($isFresh && ! $running
                ? 'TRUE_WEBSOCKET_CACHE_LIVE_MANUAL'
                : ($running ? 'WEBSOCKET_CONNECTED_AWAITING_FRESH_TICK' : ($ageSeconds !== null && $ageSeconds <= 60 ? 'REST_FALLBACK' : 'STALE')));
        $manualFreshTick = ! $running && $isFresh;

        return [
            'server_now' => now(config('app.timezone'))->toIso8601String(),
            'running' => $running,
            'startSupported' => $this->canSpawnDaemon(),
            'shellExecAvailable' => $this->canSpawnDaemon(),
            'pid' => $pid > 0 ? $pid : null,
            'mode' => $mode,
            'feedMode' => $mode,
            'feedFresh' => $isFresh,
            'feedAgeSeconds' => $ageSeconds,
            'maxAgeSeconds' => $maxAgeSeconds,
            'lastTickPrice' => $lastTickPrice,
            'source' => (string) ($latestLiveFeed['source'] ?? $latestPrice['source'] ?? $latestFeed?->source ?? ($isFresh ? 'websocket_cache' : 'none')),
            'daemonPidKnown' => $running,
            'manualDaemonDetectedByFreshTicks' => $manualFreshTick,
            'dataSource' => $isFresh ? 'BINANCE_FUTURES_WEBSOCKET_CACHE' : ($running ? 'BINANCE_FUTURES_WEBSOCKET' : 'BINANCE_FUTURES_REST_FALLBACK'),
            'startedAt' => $meta['started_at'] ?? null,
            'command' => $meta['command'] ?? $this->commandLine($desiredSymbols),
            'manualCommand' => $this->manualStartCommand($desiredSymbols),
            'runtimeScheduled' => (bool) config('future_trade.schedule_runtime_commands', false),
            'schedulerCommand' => $this->schedulerCommand(),
            'tickCommand' => $this->tickCommand(),
            'logPath' => $meta['log_path'] ?? $this->outLogPath(),
            'errorLogPath' => $meta['error_log_path'] ?? $this->errLogPath(),
            'workspaceScope' => 'global_shared',
            'lastTickAt' => $this->isoTimestamp($lastTickAt),
            'ageSeconds' => $ageSeconds,
            'last_message_at' => $heartbeat['last_message_at'] ?? $this->isoTimestamp($lastTickAt),
            'last_message_epoch' => $heartbeat['last_message_epoch'] ?? null,
            'per_symbol' => $this->decoratePerSymbolHeartbeat((array) ($heartbeat['per_symbol'] ?? []), $desiredSymbols),
            'last_exchange_event_time' => $heartbeat['last_exchange_event_time'] ?? null,
            'exchange_to_backend_latency_ms' => $latestLiveFeed['event_lag_ms'] ?? $heartbeat['exchange_to_backend_latency_ms'] ?? null,
            'webhook_signal_latency_ms' => null,
            'isFresh' => $isFresh,
            'desiredSymbols' => $desiredSymbols,
            'runningSymbols' => $runningSymbols,
            'symbolSetInSync' => $symbolSetInSync,
            'restartRecommended' => $running && (! $symbolSetInSync || ! $isFresh),
            'reason' => $isFresh
                ? null
                : sprintf('Live feed is stale: age %ss, max %ss.', $ageSeconds ?? 'unknown', $maxAgeSeconds),
            'autoResolve' => [
                'action' => 'restart_live_feed',
                'recommended' => $running && (! $symbolSetInSync || ! $isFresh),
                'reason' => ! $symbolSetInSync
                    ? 'symbol_set_mismatch'
                    : (! $isFresh ? 'stale_live_feed' : null),
            ],
            'message' => $running
                ? ($symbolSetInSync
                    ? ($isFresh ? 'Shared futures websocket feed is live.' : 'Daemon is running, waiting for a fresh futures tick.')
                    : 'Daemon is running, but symbol list changed. Restart recommended.')
                : ($manualFreshTick
                    ? 'Live feed active by fresh tick detection.'
                    : ($this->canSpawnDaemon()
                    ? 'Daemon is offline. UI is using REST fallback.'
                    : 'Daemon control is unavailable because shell_exec is disabled. Use cron/heartbeat/manual process control.')),
        ];
    }

    public function start(): array
    {
        $status = $this->status();
        if ($status['running']) {
            return $status;
        }
        if ($this->supervisorProgramExists()) {
            $this->runShellCommand('supervisorctl start autofinx-trading:live-feed 2>&1 || true');
            sleep(2);

            return $this->status();
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
        if ($this->supervisorProgramExists()) {
            $this->runShellCommand('supervisorctl restart autofinx-trading:live-feed 2>&1 || true');
            sleep(2);

            return $this->status();
        }

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
                $primeDefaultSymbols = $this->usesPrimeDefaultSymbols($settings, $configured);
                $configured = $this->normalizePrimeDefaultSymbols($settings, $configured);
                $currentSymbol = strtoupper(trim((string) ($config->state?->current_symbol ?? '')));
                if ($currentSymbol !== '' && (! $primeDefaultSymbols || in_array($currentSymbol, $configured, true))) {
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
     * Existing configs can still contain the old broad experiment basket. When
     * Prime v6.1.7 is the active crypto profile, the live-feed daemon should
     * follow the validated default symbols unless the operator picked a custom
     * set.
     *
     * @param  string[]  $symbols
     * @return string[]
     */
    private function normalizePrimeDefaultSymbols(array $settings, array $symbols): array
    {
        if (! $this->usesPrimeDefaultSymbols($settings, $symbols)) {
            return array_values(array_filter($symbols));
        }

        return ['ETHUSDT', 'BTCUSDT', 'SOLUSDT'];
    }

    /**
     * @param  string[]  $symbols
     */
    private function usesPrimeDefaultSymbols(array $settings, array $symbols): bool
    {
        $profile = strtolower(trim((string) ($settings['signalProfile'] ?? config('future_trade.default_signal_profile', ''))));
        $market = strtolower(trim((string) ($settings['marketProfile'] ?? 'crypto')));
        if ($profile !== 'autofinx_prime_v6_1_7_crypto' || $market !== 'crypto') {
            return false;
        }

        $legacyDefault = ['BTCUSDT', 'ETHUSDT', 'SOLUSDT', 'BNBUSDT', 'XRPUSDT', 'AVAXUSDT'];
        $primeDefault = ['ETHUSDT', 'BTCUSDT', 'SOLUSDT'];
        $sortedSymbols = array_values(array_unique(array_filter($symbols)));
        $sortedLegacy = $legacyDefault;
        $sortedPrime = $primeDefault;
        sort($sortedSymbols);
        sort($sortedLegacy);
        sort($sortedPrime);

        return $sortedSymbols === [] || $sortedSymbols === $sortedLegacy || $sortedSymbols === $sortedPrime;
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

        $shellCmd = "nohup {$php} {$artisan} future-trade:live-feed --symbols={$symbolsArg} --streams=" . escapeshellarg(self::STREAMS) . " --reconnect-sleep=2 >> {$outLog} 2>> {$errLog} & echo \$!";

        // Preferred: shell_exec with login shell (full PATH / env).
        if (\function_exists('shell_exec')) {
            $output = $this->runShellCommand("bash -lc " . escapeshellarg("cd {$cwd} && {$shellCmd}"));

            return (int) trim((string) $output);
        }

        // Fallback 1: exec (available on some cPanel configs when shell_exec is disabled).
        if (\function_exists('exec')) {
            $result = [];
            \exec("bash -c " . escapeshellarg($shellCmd), $result);

            return (int) trim(implode('', $result));
        }

        // Fallback 2: proc_open — spawn /bin/bash with the background command.
        // bash runs 'nohup ... &', prints the daemon PID, then exits immediately,
        // so proc_close() returns at once and the daemon lives on as an orphan.
        if (\function_exists('proc_open')) {
            return $this->spawnUnixViaProc($shellCmd);
        }

        return 0;
    }

    private function spawnUnixViaProc(string $shellCmd): int
    {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];
        $pipes = [];
        $process = \proc_open(['/bin/bash', '-c', $shellCmd], $descriptors, $pipes, base_path());

        if (! \is_resource($process)) {
            return 0;
        }

        $pidStr = \fgets($pipes[1]);
        \fclose($pipes[1]);
        \proc_close($process); // bash exits right after echo $!, so this doesn't block

        return (int) trim((string) $pidStr);
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
        return \function_exists('shell_exec')
            || \function_exists('exec')
            || (PHP_OS_FAMILY !== 'Windows' && \function_exists('proc_open'));
    }

    private function supervisorProgramExists(): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return false;
        }
        if (! \function_exists('shell_exec') && ! \function_exists('exec')) {
            return false;
        }

        $output = $this->runShellCommand('command -v supervisorctl >/dev/null 2>&1 && supervisorctl status autofinx-trading:live-feed 2>/dev/null || true');

        return is_string($output) && str_contains($output, 'autofinx-trading:live-feed');
    }

    private function runShellCommand(string $command): ?string
    {
        if (\function_exists('shell_exec')) {
            return \shell_exec($command);
        }

        if (\function_exists('exec')) {
            $output = [];
            \exec($command, $output);

            return $output !== [] ? implode("\n", $output) : null;
        }

        return null;
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

    private function schedulerCommand(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return trim(sprintf('%s %s schedule:run', $this->phpBinary(), base_path('artisan')));
        }

        return trim(sprintf('cd %s && %s %s schedule:run >> /dev/null 2>&1', base_path(), $this->phpBinary(), base_path('artisan')));
    }

    private function tickCommand(): string
    {
        return trim(sprintf(
            '%s %s future-trade:tick --duration=55 --sleep-ms=1000',
            $this->phpBinary(),
            base_path('artisan')
        ));
    }

    private function phpBinary(): string
    {
        $configured = trim((string) config('future_trade.php_binary', ''));
        if ($configured !== '') {
            return $configured;
        }

        $binary = PHP_BINARY ?: 'php';
        $basename = strtolower(basename(str_replace('\\', '/', $binary)));
        if (! in_array($basename, ['php-fpm', 'php-fpm.exe', 'php-cgi', 'php-cgi.exe'], true)) {
            return $binary;
        }

        $candidate = str_replace('/usr/sbin/php-fpm', '/usr/bin/php', $binary);
        if ($candidate !== $binary && is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }

        foreach (['/opt/cpanel/ea-php84/root/usr/bin/php', '/opt/cpanel/ea-php83/root/usr/bin/php', '/usr/local/bin/php', '/usr/bin/php'] as $path) {
            if (is_file($path) && is_executable($path)) {
                return $path;
            }
        }

        return 'php';
    }

    private function ageSecondsSince(mixed $timestamp): ?int
    {
        if ($timestamp === null || $timestamp === '') {
            return null;
        }

        try {
            $time = $timestamp instanceof \DateTimeInterface
                ? Carbon::instance($timestamp)
                : Carbon::parse((string) $timestamp, config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }

        return max(0, now(config('app.timezone'))->getTimestamp() - $time->getTimestamp());
    }

    /**
     * Redis is the realtime truth; MarketFeedStatus is throttled for DB safety.
     *
     * @param  string[]  $symbols
     */
    private function freshestCachedPrice(array $symbols): ?array
    {
        $cache = app(MarketPriceCacheService::class);
        $freshest = null;

        foreach ($symbols as $symbol) {
            $latest = $cache->getLatestPrice('binance_futures', (string) $symbol);
            if (! is_array($latest) || (float) ($latest['price'] ?? 0) <= 0) {
                continue;
            }

            $timestampMs = (int) ($latest['timestamp_ms'] ?? 0);
            if ($timestampMs <= 0) {
                continue;
            }

            $ageSeconds = max(0, (int) floor((now(config('app.timezone'))->getTimestampMs() - $timestampMs) / 1000));
            $latest['age_seconds'] = $ageSeconds;

            if ($freshest === null || $ageSeconds < (int) ($freshest['age_seconds'] ?? PHP_INT_MAX)) {
                $freshest = $latest;
            }
        }

        return $freshest;
    }

    /**
     * Redis live-feed snapshots measure when this backend received the Binance
     * event. That is the right freshness source for execution guards; exchange
     * event timestamps are kept as latency diagnostics only.
     *
     * @param  string[]  $symbols
     */
    private function freshestLiveFeedSnapshot(array $symbols): ?array
    {
        return app(MarketPriceCacheService::class)
            ->freshestLiveFeedSnapshot('binance_futures', $symbols, 120);
    }

    private function loadHeartbeat(): array
    {
        $path = storage_path('framework/autofinx-live-feed-heartbeat.json');
        if (! is_file($path)) {
            return [];
        }

        $raw = @file_get_contents($path);
        $decoded = $raw !== false ? @json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, array<string, mixed>>  $heartbeat
     * @param  string[]  $symbols
     */
    private function decoratePerSymbolHeartbeat(array $heartbeat, array $symbols): array
    {
        $now = time();
        $result = [];

        foreach ($symbols as $symbol) {
            $symbol = strtoupper((string) $symbol);
            $row = (array) ($heartbeat[$symbol] ?? []);
            $epoch = (int) ($row['last_message_epoch'] ?? 0);
            $row['age_seconds'] = $epoch > 0 ? max(0, $now - $epoch) : null;
            $result[$symbol] = $row;
        }

        return $result;
    }

    private function isoTimestamp(mixed $timestamp): ?string
    {
        if ($timestamp === null || $timestamp === '') {
            return null;
        }

        try {
            $time = $timestamp instanceof \DateTimeInterface
                ? Carbon::instance($timestamp)
                : Carbon::parse((string) $timestamp, config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }

        return $time->timezone(config('app.timezone'))->toIso8601String();
    }
}
