<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Loads an NDJSON stream made by ml:offload:export into THIS machine database
 * (intended for a separate training machine). Idempotent (insertOrIgnore);
 * refuses to run without --yes-local so it is never run against the
 * production database by mistake.
 */
final class MlOffloadImportCommand extends Command
{
    protected $signature = 'ml:offload:import {file : .ndjson or .ndjson.gz} {--yes-local : Confirm this is the training machine, not production}';

    protected $description = 'Import an ml:offload:export stream into the local training database.';

    private const BATCH = 500;

    public function handle(): int
    {
        if (! $this->option('yes-local')) {
            $this->error('Refusing: pass --yes-local to confirm this is the local training machine (never the production server).');

            return self::FAILURE;
        }
        $path = (string) $this->argument('file');
        $gz = str_ends_with($path, '.gz');
        $handle = $gz ? gzopen($path, 'rb') : fopen($path, 'rb');
        if ($handle === false) {
            $this->error("Cannot open {$path}.");

            return self::FAILURE;
        }
        $now = now('UTC')->toDateTimeString();
        $buffers = ['ml_funding_rates' => [], 'ml_feature_rows' => [], 'ml_labels' => []];
        $written = ['ml_funding_rates' => 0, 'ml_feature_rows' => 0, 'ml_labels' => 0];
        $end = null;
        $flush = function (string $table) use (&$buffers, &$written): void {
            if ($buffers[$table] !== []) {
                $written[$table] += DB::table($table)->insertOrIgnore($buffers[$table]);
                $buffers[$table] = [];
            }
        };

        while (! ($gz ? gzeof($handle) : feof($handle))) {
            $line = $gz ? gzgets($handle) : fgets($handle);
            if ($line === false || trim($line) === '') {
                continue;
            }
            $r = json_decode($line, true);
            if (! is_array($r)) {
                $this->error('A line of the stream is not valid JSON (cut or damaged file). Fetch it again.');

                return self::FAILURE;
            }
            $table = null;
            switch ($r['k'] ?? '') {
                case 'r':
                    $table = 'ml_funding_rates';
                    $buffers[$table][] = ['symbol' => $r['s'], 'funding_time_ms' => $r['t'], 'funding_rate' => $r['r'], 'mark_price' => $r['p'], 'created_at' => $now, 'updated_at' => $now];
                    break;
                case 'f':
                    $table = 'ml_feature_rows';
                    $buffers[$table][] = ['symbol' => $r['s'], 'timeframe' => $r['tf'], 'anchor_time_ms' => $r['a'], 'anchor_close' => $r['c'], 'feature_schema_version' => $r['v'], 'features' => $r['x'], 'created_at' => $now, 'updated_at' => $now];
                    break;
                case 'l':
                    $table = 'ml_labels';
                    $buffers[$table][] = ['symbol' => $r['s'], 'timeframe' => $r['tf'], 'horizon_minutes' => $r['h'], 'anchor_time_ms' => $r['a'], 'target_time_ms' => $r['t'],
                        'anchor_close' => $r['c'], 'target_close' => $r['tc'], 'future_return' => $r['r'], 'status' => 'MATURE', 'feature_schema_version' => $r['v'], 'matured_at' => $r['m'], 'created_at' => $now, 'updated_at' => $now];
                    break;
                case 'end':
                    $end = $r;
                    break;
            }
            if ($table !== null && count($buffers[$table]) >= self::BATCH) {
                $flush($table);
            }
        }
        foreach (array_keys($buffers) as $table) {
            $flush($table);
        }
        $gz ? gzclose($handle) : fclose($handle);

        if ($end === null) {
            $this->error('The stream has no end marker: the download was cut. Fetch it again (the import is safe to repeat).');

            return self::FAILURE;
        }
        $this->info(sprintf('%s imported: funding %d, feature rows %d, labels %d new rows (stream had %d/%d/%d).',
            $end['s'], $written['ml_funding_rates'], $written['ml_feature_rows'], $written['ml_labels'], $end['funding'], $end['features'], $end['labels']));

        return self::SUCCESS;
    }
}
