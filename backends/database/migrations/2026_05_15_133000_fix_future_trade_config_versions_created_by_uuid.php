<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('future_trade_config_versions') || ! Schema::hasColumn('future_trade_config_versions', 'created_by')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver !== 'mysql') {
            return;
        }

        try {
            DB::statement('ALTER TABLE future_trade_config_versions MODIFY created_by CHAR(36) NULL');
        } catch (\Throwable) {
            // If the column is already CHAR(36) or the table was never created, ignore.
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('future_trade_config_versions') || ! Schema::hasColumn('future_trade_config_versions', 'created_by')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver !== 'mysql') {
            return;
        }

        try {
            DB::statement('ALTER TABLE future_trade_config_versions MODIFY created_by BIGINT UNSIGNED NULL');
        } catch (\Throwable) {
            // No-op rollback guard.
        }
    }
};
