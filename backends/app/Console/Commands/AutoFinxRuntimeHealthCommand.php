<?php

namespace App\Console\Commands;

use App\Domain\MarketData\MarketSnapshotService;
use App\Domain\Ops\OperationalBreakerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class AutoFinxRuntimeHealthCommand extends Command
{
    protected $signature = 'autofinx:runtime-health {--symbols=BTCUSDT,ETHUSDT}';

    protected $description = 'Report AutoFinix runtime health without exposing secrets.';

    public function handle(MarketSnapshotService $snapshots, OperationalBreakerService $breakers): int
    {
        $exit = 0;
        $this->line('AUTOFINX RUNTIME HEALTH');
        $this->newLine();

        $phpOk = version_compare(PHP_VERSION, '8.2.0', '>=');
        $this->line(sprintf('PHP  CLI binary................ %s %s', $phpOk ? 'PASS' : 'FAIL', PHP_VERSION));
        $exit = $phpOk ? $exit : 2;

        try {
            Redis::ping();
            $this->line('REDIS Connection................ PASS');
            $this->line('REDIS DB........................ '.(string) config('database.redis.default.database'));
            $this->line('REDIS Prefix.................... '.$this->mask((string) config('database.redis.options.prefix', '')));
        } catch (\Throwable $exception) {
            $this->line('REDIS Connection................ FAIL '.$exception->getMessage());
            return 2;
        }

        $worker = $this->redisJson('autofinx:market-feed:binance:futures:worker')
            ?? $this->redisJson('autofinx:market-feed:binance:spot:worker');
        $this->newLine();
        $this->line('MARKET FEED');
        $this->line('  Worker metadata............... '.($worker ? 'FOUND' : 'MISSING'));
        $this->line('  Worker ID..................... '.($worker['worker_id'] ?? 'n/a'));
        $this->line('  Connection ID................. '.$this->short((string) ($worker['connection_id'] ?? '')));
        $this->line('  PID........................... '.($worker['pid'] ?? 'n/a'));
        $this->line('  Status........................ '.($worker['status'] ?? 'UNKNOWN'));
        $this->line('  Reconnect count............... '.($worker['reconnect_count'] ?? 'n/a'));
        if (! $worker) {
            $exit = max($exit, 1);
        }

        $symbols = array_values(array_filter(array_map(
            static fn (string $symbol) => strtoupper(trim($symbol)),
            explode(',', (string) $this->option('symbols'))
        )));

        foreach ($symbols as $symbol) {
            $snapshot = $snapshots->getLiveSnapshot('binance', $symbol, 'binance_futures', (int) config('future_trade.live_feed_max_age_seconds', 3));
            $state = (string) ($snapshot['connection_state'] ?? 'UNKNOWN');
            $breaker = $breakers->staleFeedState(null);
            $this->newLine();
            $this->line($symbol);
            $this->line('  Canonical key................. '.($this->redisJson("market:live:{$symbol}") ? 'FOUND' : 'MISSING'));
            $this->line('  Source key.................... '.(data_get($snapshot, 'legacy.source_key') ?: 'n/a'));
            $this->line('  Source........................ '.($snapshot['source'] ?? 'n/a'));
            $this->line('  Stream........................ '.(data_get($snapshot, 'legacy.stream') ?: 'n/a'));
            $this->line('  Event type.................... '.(data_get($snapshot, 'legacy.event_type') ?: 'n/a'));
            $this->line('  Age........................... '.($snapshot['age_ms'] ?? 'n/a').' ms');
            $this->line('  State......................... '.$state);
            $this->line('  Breaker....................... '.((bool) ($breaker['active'] ?? false) ? 'ACTIVE' : 'CLEAR'));

            if (! (bool) ($snapshot['is_fresh'] ?? false)) {
                $exit = max($exit, $state === 'STALE' ? 2 : 1);
            }
        }

        $this->newLine();
        $this->line('OVERALL......................... '.($exit === 0 ? 'PASS' : ($exit === 1 ? 'WARN' : 'FAIL')));

        return $exit;
    }

    private function redisJson(string $key): ?array
    {
        try {
            $raw = Redis::get($key);
        } catch (\Throwable) {
            return null;
        }

        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        return is_array($data) ? $data : null;
    }

    private function mask(string $value): string
    {
        if ($value === '') {
            return '(none)';
        }

        return strlen($value) <= 6 ? '***' : substr($value, 0, 3).'***'.substr($value, -2);
    }

    private function short(string $value): string
    {
        return $value === '' ? 'n/a' : substr($value, 0, 8);
    }
}
