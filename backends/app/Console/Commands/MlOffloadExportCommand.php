<?php

namespace App\Console\Commands;

use App\Domain\Ml\Shadow\ShadowFeatureBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Streams the TRAINING inputs of one symbol (funding, feature rows of the
 * training schema, MATURE labels of every horizon) as NDJSON on stdout, so a
 * stronger machine can train from the same data. Read-only, keyset-paged (flat
 * memory); nothing is written to the disk of the server. Pipe it, e.g.
 *   artisan ml:offload:export ETHUSDT | gzip > eth.ndjson.gz
 * Progress goes to stderr. The importer is idempotent, so a cut stream can
 * simply be run again.
 */
final class MlOffloadExportCommand extends Command
{
    protected $signature = 'ml:offload:export {symbol}';

    protected $description = 'Stream one symbol training data (funding, features, mature labels) as NDJSON on stdout.';

    private const PAGE = 2000;

    public function handle(): int
    {
        $symbol = strtoupper((string) $this->argument('symbol'));
        if (! in_array($symbol, (array) config('ml.symbols', []), true)) {
            fwrite(STDERR, "Not configured in ml.symbols: {$symbol}\n");

            return self::FAILURE;
        }
        $timeframe = (string) config('ml.base_timeframe', '5m');
        $schema = ShadowFeatureBuilder::SCHEMA_VERSION;
        $counts = ['funding' => 0, 'features' => 0, 'labels' => 0];

        $last = null;
        while (true) {
            $rows = DB::table('ml_funding_rates')->where('symbol', $symbol)
                ->when($last !== null, fn ($q) => $q->where('funding_time_ms', '>', $last))
                ->orderBy('funding_time_ms')->limit(self::PAGE)->get(['funding_time_ms', 'funding_rate', 'mark_price']);
            if ($rows->isEmpty()) {
                break;
            }
            foreach ($rows as $r) {
                $this->emit(['k' => 'r', 's' => $symbol, 't' => (int) $r->funding_time_ms, 'r' => $r->funding_rate, 'p' => $r->mark_price]);
                $counts['funding']++;
            }
            $last = (int) $rows->last()->funding_time_ms;
        }
        fwrite(STDERR, "{$symbol}: funding {$counts['funding']}\n");

        $last = null;
        while (true) {
            $rows = DB::table('ml_feature_rows')->where('symbol', $symbol)->where('timeframe', $timeframe)->where('feature_schema_version', $schema)
                ->when($last !== null, fn ($q) => $q->where('anchor_time_ms', '>', $last))
                ->orderBy('anchor_time_ms')->limit(self::PAGE)->get(['anchor_time_ms', 'anchor_close', 'features']);
            if ($rows->isEmpty()) {
                break;
            }
            foreach ($rows as $r) {
                $this->emit(['k' => 'f', 's' => $symbol, 'tf' => $timeframe, 'v' => $schema, 'a' => (int) $r->anchor_time_ms, 'c' => $r->anchor_close, 'x' => $r->features]);
                $counts['features']++;
            }
            $last = (int) $rows->last()->anchor_time_ms;
            fwrite(STDERR, "{$symbol}: features {$counts['features']}\r");
        }
        fwrite(STDERR, "\n");

        foreach ((array) config('ml.horizons_minutes', []) as $horizon) {
            $last = null;
            while (true) {
                $rows = DB::table('ml_labels')->where('symbol', $symbol)->where('timeframe', $timeframe)->where('horizon_minutes', (int) $horizon)->where('status', 'MATURE')
                    ->when($last !== null, fn ($q) => $q->where('anchor_time_ms', '>', $last))
                    ->orderBy('anchor_time_ms')->limit(self::PAGE)->get(['anchor_time_ms', 'target_time_ms', 'anchor_close', 'target_close', 'future_return', 'feature_schema_version', 'matured_at']);
                if ($rows->isEmpty()) {
                    break;
                }
                foreach ($rows as $r) {
                    $this->emit(['k' => 'l', 's' => $symbol, 'tf' => $timeframe, 'h' => (int) $horizon, 'a' => (int) $r->anchor_time_ms, 't' => (int) $r->target_time_ms,
                        'c' => $r->anchor_close, 'tc' => $r->target_close, 'r' => $r->future_return, 'v' => $r->feature_schema_version, 'm' => $r->matured_at]);
                    $counts['labels']++;
                }
                $last = (int) $rows->last()->anchor_time_ms;
            }
            fwrite(STDERR, "{$symbol}: labels h{$horizon} done ({$counts['labels']} total)\n");
        }
        $this->emit(['k' => 'end', 's' => $symbol, 'funding' => $counts['funding'], 'features' => $counts['features'], 'labels' => $counts['labels']]);

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $record */
    private function emit(array $record): void
    {
        fwrite(STDOUT, json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }
}
