<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * market_ohlc.ts was created as TIMESTAMP NOT NULL. With MySQL/MariaDB's
 * explicit_defaults_for_timestamp off, the first such column silently gets
 * DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, so every UPDATE of a
 * candle (forming -> closed, a REST re-poll) overwrote its open time with the
 * server clock (+08 on production). Those candles jumped hours into the future
 * and their real slots went missing.
 *
 * DATETIME has no auto-update and no session-timezone conversion. MySQL turns
 * TIMESTAMP into DATETIME using the session time zone, which is the same one
 * the app reads with, so every stored wall time is preserved as the app sees
 * it today (UTC wall time, see App\Casts\UtcDateTime).
 *
 * The ALTER copies the table and blocks writes while it runs: stop the market
 * feed workers first. SQLite (tests) has no such behaviour and is skipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement('ALTER TABLE market_ohlc MODIFY ts DATETIME NOT NULL');
    }

    public function down(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        // Explicit default with no ON UPDATE clause, so rolling back does not
        // bring the auto-update back.
        DB::statement('ALTER TABLE market_ohlc MODIFY ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
    }
};
