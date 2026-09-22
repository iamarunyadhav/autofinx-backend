<?php

namespace App\Console\Commands;

use App\Domain\Market\BinanceFuturesStreamRouter;
use App\Domain\Market\RealtimeMarketFeedService;
use Illuminate\Console\Command;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Orchestrates the two Future Trade futures public-market-data transport
 * lanes as separate, concurrently-running `market:ws-consume` child
 * processes -- one per BinanceFuturesStreamRouter lane.
 *
 * 2026-09-22 root cause (verified via raw SimpleWebSocketClient diagnostics
 * against real production Binance frames): the previous single combined
 * connection subscribed depth + kline streams together on Binance's futures
 * ROOT combined-stream endpoint (wss://fstream.binance.com/stream), which
 * only reliably delivers depthUpdate frames in production -- kline frames
 * never arrived on it, so candles went stale for hours while quotes stayed
 * fresh. Splitting the same streams onto two dedicated endpoints
 * (.../public/stream for depth, .../market/stream for kline) delivered both
 * correctly. This command previously called `market:ws-consume`
 * synchronously in-process (Illuminate\Console\Command::call()); it now
 * starts one real child OS process per lane via Illuminate's Process
 * component so both connections run truly concurrently, supervises them,
 * restarts only a failed lane with bounded backoff, and stops both cleanly
 * when the parent stops. Each child reuses market:ws-consume's existing
 * websocket ingest logic unchanged -- nothing about RealtimeMarketFeedService::ingest()
 * or the candle/quote persistence path changed.
 */
class FutureTradeLiveFeedCommand extends Command
{
    protected $signature = 'future-trade:live-feed
        {--symbols= : Comma-separated futures symbols. Defaults to all configured Future Trade symbols}
        {--streams=depth5@500ms,kline_1m,kline_5m : Binance streams to subscribe to, across both transport lanes}
        {--reconnect-sleep=2 : Reconnect delay in seconds, forwarded to each lane child}
        {--max-runtime=0 : Max runtime in seconds (0 = infinite)}
        {--testnet : Use Binance futures testnet websocket}';

    protected $description = 'Orchestrate the Future Trade futures public-market-data transport lanes (public=depth, market=kline) as separate concurrent market:ws-consume child processes.';

    /** @var array<string, InvokedProcess> */
    private array $children = [];

    private bool $shouldStop = false;

    public function handle(): int
    {
        $heartbeatDir = storage_path('framework');
        if (! is_dir($heartbeatDir) || ! is_writable($heartbeatDir)) {
            $message = "Live-feed heartbeat directory is not writable: {$heartbeatDir}";
            $this->error($message);
            Log::error('[FT LIVE FEED PREFLIGHT FAILED]', [
                'reason' => 'heartbeat_directory_not_writable',
                'path' => $heartbeatDir,
            ]);

            return self::FAILURE;
        }

        $symbols = $this->resolveSymbols();
        if ($symbols === []) {
            $symbols = ['BTCUSDT', 'ETHUSDT'];
        }

        $streamsOption = (string) $this->option('streams');
        $streams = array_values(array_filter(array_map(
            static fn (string $stream) => trim($stream),
            explode(',', $streamsOption)
        )));

        try {
            $lanes = (new BinanceFuturesStreamRouter)->classify($streams);
        } catch (Throwable $exception) {
            $this->error('Cannot start Future Trade live-feed: '.$exception->getMessage());
            Log::error('[FT LIVE FEED PREFLIGHT FAILED]', [
                'reason' => 'stream_routing_failed',
                'streams' => $streams,
                'error' => $exception->getMessage(),
            ]);

            return self::FAILURE;
        }

        $activeLanes = array_values(array_keys(array_filter(
            $lanes,
            static fn (array $laneStreams): bool => $laneStreams !== []
        )));

        if ($activeLanes === []) {
            $this->error('No routable streams configured (--streams='.$streamsOption.'); nothing to start.');

            return self::FAILURE;
        }

        $maxRuntime = max(0, (int) $this->option('max-runtime'));
        $reconnectSleep = (string) $this->option('reconnect-sleep');
        $testnet = (bool) $this->option('testnet');
        // Default operation must retain symbol discovery. Each lane child's
        // own market:ws-consume then independently rechecks the combined
        // Future Trade, Robot Trade, open Quick Trade, and UI-requested set
        // every 60s and reconnects only when that set changed -- both lanes
        // therefore converge to the same current symbol set on their own,
        // with no orchestrator coordination required. An explicit CLI
        // --symbols remains deliberately pinned (and shared by both lanes)
        // for diagnostics.
        $symbolsPinned = trim((string) $this->option('symbols')) !== '';

        Log::info('[FT LIVE FEED START]', [
            'symbols' => $symbols,
            'lanes' => $lanes,
            'maxRuntime' => $maxRuntime,
            'testnet' => $testnet,
        ]);

        $startedAt = time();
        $lockKey = 'autofinx:runtime:future-trade-live-feed:'.md5(implode(',', $symbols)).':'.md5($streamsOption);
        $lockTtl = $maxRuntime > 0 ? $maxRuntime + 60 : 86400;
        $lock = Cache::lock($lockKey, $lockTtl);

        if (! $lock->get()) {
            if ($this->anyLaneHeartbeatFresh($activeLanes)) {
                $this->warn('Another fresh Future Trade live-feed orchestrator is active; standing by until its heartbeats go stale.');
                $this->waitUntilAllLaneHeartbeatsStale($activeLanes, $startedAt, $maxRuntime);
                $this->warn('Future Trade live-feed heartbeats are stale; force releasing stale lock and taking over.');
                Cache::lock($lockKey, 1)->forceRelease();
                $lock = Cache::lock($lockKey, $lockTtl);
            } else {
                $this->warn('Stale Future Trade live-feed lock detected without a fresh heartbeat; force releasing lock and retrying.');
                Cache::lock($lockKey, 1)->forceRelease();
                $lock = Cache::lock($lockKey, $lockTtl);
            }

            if (! $lock->get()) {
                $this->warn('Another Future Trade live-feed daemon is already running for this symbol/stream scope.');
                Log::warning('[FT LIVE FEED LOCKED]', [
                    'symbols' => $symbols,
                    'streams' => $streams,
                    'heartbeat_fresh' => $this->anyLaneHeartbeatFresh($activeLanes),
                ]);

                return self::SUCCESS;
            }
        }

        $this->registerSignalHandlers();

        try {
            foreach ($activeLanes as $lane) {
                // A previous crash-restarted orchestrator can leave a stale
                // heartbeat file behind for a lane; a genuinely fresh one
                // belongs to a live process, but we only reach this point
                // once we own the singleton lock above, so any leftover file
                // here is safe to clear before this run's first tick.
                if (! $this->laneHeartbeatFresh($lane)) {
                    @unlink(MarketWsConsumerCommand::heartbeatPathFor('futures', $lane));
                }
                $this->startLane($lane, $symbols, $lanes[$lane], $reconnectSleep, $maxRuntime, $testnet, $symbolsPinned);
            }

            $exitCode = $this->supervise($activeLanes, $symbols, $lanes, $reconnectSleep, $maxRuntime, $testnet, $symbolsPinned, $startedAt);

            Log::info('[FT LIVE FEED EXIT]', [
                'exitCode' => $exitCode,
                'symbols' => $symbols,
                'lanes' => array_keys($lanes),
            ]);

            return $exitCode;
        } catch (Throwable $exception) {
            Log::error('[FT LIVE FEED FAILED]', [
                'class' => $exception::class,
                'message' => $exception->getMessage(),
                'symbols' => $symbols,
                'streams' => $streams,
            ]);
            $this->error('Future Trade live-feed failed: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            $this->stopAllChildren();
            optional($lock)->release();
        }
    }

    /**
     * @param  string[]  $symbols
     * @param  string[]  $laneStreams
     */
    private function startLane(
        string $lane,
        array $symbols,
        array $laneStreams,
        string $reconnectSleep,
        int $maxRuntime,
        bool $testnet,
        bool $symbolsPinned,
    ): void {
        $command = [
            $this->phpBinary(),
            base_path('artisan'),
            'market:ws-consume',
            '--market-type=futures',
            '--lane='.$lane,
            '--streams='.implode(',', $laneStreams),
            '--reconnect-sleep='.$reconnectSleep,
            '--max-runtime='.$maxRuntime,
            '--no-dispatch',
        ];

        if ($testnet) {
            $command[] = '--testnet';
        }

        if ($symbolsPinned) {
            $command[] = '--symbols='.implode(',', $symbols);
        }

        $this->line(sprintf('[FT LIVE FEED] starting lane=%s streams=%s', $lane, implode(',', $laneStreams)));
        Log::info('[FT LIVE FEED LANE START]', [
            'lane' => $lane,
            'streams' => $laneStreams,
            'symbols_pinned' => $symbolsPinned,
        ]);

        $this->children[$lane] = Process::command($command)
            ->forever()
            ->start(null, function (string $type, string $buffer) use ($lane): void {
                foreach (preg_split('/\R/', rtrim($buffer)) ?: [] as $line) {
                    if ($line === '') {
                        continue;
                    }
                    $this->line("[{$lane}] {$line}");
                }
            });
    }

    /**
     * @param  string[]  $activeLanes
     * @param  string[]  $symbols
     * @param  array<string, string[]>  $lanes
     */
    private function supervise(
        array $activeLanes,
        array $symbols,
        array $lanes,
        string $reconnectSleep,
        int $maxRuntime,
        bool $testnet,
        bool $symbolsPinned,
        int $startedAt,
    ): int {
        $reconnectSleepBase = max(1, (int) $reconnectSleep);
        $failureCounts = array_fill_keys($activeLanes, 0);
        $nextRestartAt = array_fill_keys($activeLanes, 0);

        while (! $this->shouldStop) {
            if ($maxRuntime > 0 && (time() - $startedAt) >= $maxRuntime) {
                $this->info('Max runtime reached, stopping Future Trade live-feed orchestrator.');
                break;
            }

            foreach ($activeLanes as $lane) {
                $process = $this->children[$lane] ?? null;

                if ($process === null) {
                    if (time() >= ($nextRestartAt[$lane] ?? 0)) {
                        $this->startLane($lane, $symbols, $lanes[$lane], $reconnectSleep, $maxRuntime, $testnet, $symbolsPinned);
                    }

                    continue;
                }

                if ($process->running()) {
                    continue;
                }

                $result = $process->wait();
                unset($this->children[$lane]);
                $failureCounts[$lane]++;

                $this->warn(sprintf(
                    '[FT LIVE FEED] lane=%s child exited unexpectedly exit_code=%s (failure #%d); restarting with backoff.',
                    $lane,
                    $result->exitCode(),
                    $failureCounts[$lane]
                ));
                Log::warning('[FT LIVE FEED CHILD EXITED]', [
                    'lane' => $lane,
                    'exit_code' => $result->exitCode(),
                    'failure_count' => $failureCounts[$lane],
                    'output_tail' => $this->tail($result->output()),
                    'error_output_tail' => $this->tail($result->errorOutput()),
                ]);

                // Bounded exponential backoff, capped at 60s -- mirrors
                // market:ws-consume's own internal websocket-reconnect
                // backoff shape so a crash-looping lane never hammers the
                // process table, but never gives up retrying either.
                $delay = min(60, $reconnectSleepBase * (2 ** min($failureCounts[$lane], 5)));
                $nextRestartAt[$lane] = time() + $delay;
            }

            if ($this->shouldStop) {
                break;
            }

            sleep(1);
        }

        return self::SUCCESS;
    }

    private function stopAllChildren(): void
    {
        foreach ($this->children as $lane => $process) {
            try {
                $process->stop(10);
            } catch (Throwable $exception) {
                Log::warning('[FT LIVE FEED] failed to stop child cleanly', [
                    'lane' => $lane,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $this->children = [];
    }

    private function registerSignalHandlers(): void
    {
        if (! function_exists('pcntl_async_signals') || ! function_exists('pcntl_signal')) {
            // Windows/dev environments without ext-pcntl fall back to relying
            // on Supervisor's own stop sequence (SIGTERM -> wait -> SIGKILL)
            // reaching this process's normal PHP shutdown, which still runs
            // the finally block above via register_shutdown_function-driven
            // cleanup where the runtime supports it; on a real Supervisor
            // (Linux) host ext-pcntl is always available.
            return;
        }

        pcntl_async_signals(true);
        $handler = function (int $signal): void {
            $this->shouldStop = true;
            $this->warn(sprintf('[FT LIVE FEED] received signal %d; stopping both lanes cleanly.', $signal));
            Log::info('[FT LIVE FEED SIGNAL]', ['signal' => $signal]);
        };
        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
    }

    private function tail(string $text, int $maxLength = 2000): string
    {
        return strlen($text) > $maxLength ? substr($text, -$maxLength) : $text;
    }

    /**
     * @return string[]
     */
    private function resolveSymbols(): array
    {
        $option = trim((string) $this->option('symbols'));
        if ($option !== '') {
            return collect(explode(',', $option))
                ->map(static fn (string $symbol) => strtoupper(trim($symbol)))
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        return app(RealtimeMarketFeedService::class)->selectedSymbols('futures');
    }

    /**
     * @param  string[]  $lanes
     */
    private function anyLaneHeartbeatFresh(array $lanes): bool
    {
        foreach ($lanes as $lane) {
            if ($this->laneHeartbeatFresh($lane)) {
                return true;
            }
        }

        return false;
    }

    private function laneHeartbeatFresh(string $lane): bool
    {
        $path = MarketWsConsumerCommand::heartbeatPathFor('futures', $lane);
        if (! file_exists($path)) {
            return false;
        }

        $raw = @file_get_contents($path);
        $data = $raw !== false ? @json_decode($raw, true) : null;
        $epoch = is_array($data) ? (int) ($data['last_message_epoch'] ?? 0) : 0;

        return $epoch > 0 && (time() - $epoch) <= 30;
    }

    /**
     * @param  string[]  $lanes
     */
    private function waitUntilAllLaneHeartbeatsStale(array $lanes, int $startedAt, int $maxRuntime): void
    {
        while ($this->anyLaneHeartbeatFresh($lanes)) {
            if ($maxRuntime > 0 && (time() - $startedAt) >= $maxRuntime) {
                return;
            }

            sleep(5);
        }
    }

    /**
     * Same shared-hosting-safe resolution FutureTradeLiveFeedManager uses for
     * its manual-start fallback: this process is already running as a real
     * CLI artisan command (Supervisor, or that manager's own spawn), so
     * PHP_BINARY here is already a working CLI binary in the overwhelming
     * majority of cases -- the php-fpm/php-cgi guard only exists as a last-
     * resort safety net, matching the existing pattern rather than assuming
     * it can never happen.
     */
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
}
