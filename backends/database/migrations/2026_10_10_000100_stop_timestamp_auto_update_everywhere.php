<?php

use App\Support\Database\TimestampAutoUpdateRepair;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Production had 43 TIMESTAMP columns carrying a silent ON UPDATE
 * CURRENT_TIMESTAMP (see TimestampAutoUpdateRepair): ratio_bot_decisions.
 * decided_at, trades.executed_at, order_fills.fill_ts, audit/event times and
 * more were rewritten to the server clock whenever their row was updated.
 * This drops the clause from every such column in the database. Values, type
 * and nullability are unchanged; on MariaDB each change is INSTANT.
 *
 * It also restores ratio_bot_decisions.decided_at where it can be recovered:
 * every writer sets decided_at = now() on insert, the same instant as
 * created_at, and a rewritten value lands at least 2.5h later (server +08 vs
 * app +05:30). Other columns have no second source, so they are only
 * protected from now on.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! TimestampAutoUpdateRepair::supported()) {
            return;
        }

        foreach (TimestampAutoUpdateRepair::columns() as $column) {
            $algorithm = TimestampAutoUpdateRepair::fix($column);
            Log::info('[Migration] Dropped ON UPDATE CURRENT_TIMESTAMP', [
                'table' => $column->TABLE_NAME, 'column' => $column->COLUMN_NAME, 'algorithm' => $algorithm,
            ]);
        }

        // Plain SELECTs do not lock; each UPDATE touches only primary keys.
        DB::table('ratio_bot_decisions')
            ->whereNotNull('created_at')
            ->whereRaw('decided_at > created_at + INTERVAL 2 HOUR')
            ->select('id')
            ->chunkById(1000, function ($rows): void {
                DB::table('ratio_bot_decisions')
                    ->whereIn('id', $rows->pluck('id'))
                    ->update(['decided_at' => DB::raw('created_at')]);
            });
    }

    public function down(): void
    {
        // Intentionally irreversible: restoring a silent auto-update would
        // reintroduce the corruption this migration removes.
    }
};
