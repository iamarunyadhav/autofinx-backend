<?php

namespace App\Console\Commands;

use App\Domain\Market\MarketTransportCoalescer;
use Illuminate\Console\Command;

/** CPU-only synthetic benchmark. It performs no Redis, DB, network, or order writes. */
class MarketTransportBenchmarkCommand extends Command
{
    protected $signature = 'market:transport-benchmark
        {--events=100000 : Synthetic quote events}
        {--symbols=4 : Number of isolated symbols}
        {--flush-ms=100 : Coalescer cadence}';

    protected $description = 'Benchmark bounded websocket frame coalescing without touching production state.';

    public function handle(): int
    {
        $events = max(100, min(2_000_000, (int) $this->option('events')));
        $symbolCount = max(1, min(100, (int) $this->option('symbols')));
        $flushMs = max(10, min(1000, (int) $this->option('flush-ms')));
        $coalescer = new MarketTransportCoalescer($flushMs, 0);
        $started = hrtime(true);
        $syntheticMs = 0;
        $drainSizes = [];

        for ($i = 0; $i < $events; $i++) {
            $syntheticMs++;
            $symbol = 'ASSET'.($i % $symbolCount).'USDT';
            $drained = $coalescer->offer(['stream' => strtolower($symbol).'@bookTicker', 'data' => [
                'e' => 'bookTicker', 's' => $symbol, 'E' => $syntheticMs,
                'b' => (string) (100 + ($i / 10000)), 'a' => (string) (100 + ($i / 10000)),
            ]], $syntheticMs);
            if ($drained !== []) {
                $drainSizes[] = count($drained);
            }
        }
        $tail = $coalescer->drain($syntheticMs + $flushMs);
        if ($tail !== []) {
            $drainSizes[] = count($tail);
        }
        $elapsedSeconds = max(0.000001, (hrtime(true) - $started) / 1_000_000_000);
        sort($drainSizes);
        $stats = $coalescer->stats();
        $percentile = static fn (float $p): int => $drainSizes === [] ? 0 : $drainSizes[(int) floor((count($drainSizes) - 1) * $p)];

        $this->line(json_encode($stats + [
            'events_per_second' => round($events / $elapsedSeconds, 2),
            'emitted_per_second' => round($stats['emitted'] / $elapsedSeconds, 2),
            'coalescing_pct' => round(($stats['coalesced'] / max(1, $events)) * 100, 2),
            'drain_batch_p50' => $percentile(0.50),
            'drain_batch_p95' => $percentile(0.95),
            'elapsed_ms' => round($elapsedSeconds * 1000, 2),
            'memory_peak_bytes' => memory_get_peak_usage(true),
            'production_state_modified' => false,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
