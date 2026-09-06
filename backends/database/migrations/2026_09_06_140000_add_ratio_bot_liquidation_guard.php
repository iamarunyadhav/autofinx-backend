<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ratio_bot_configs', function (Blueprint $table): void {
            if (! Schema::hasColumn('ratio_bot_configs', 'liquidation_guard_enabled')) {
                $table->boolean('liquidation_guard_enabled')->default(false)->after('leverage');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'liquidation_watch_buffer_pct')) {
                $table->decimal('liquidation_watch_buffer_pct', 5, 2)->nullable()->after('liquidation_guard_enabled');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'liquidation_critical_buffer_pct')) {
                $table->decimal('liquidation_critical_buffer_pct', 5, 2)->nullable()->after('liquidation_watch_buffer_pct');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ratio_bot_configs', function (Blueprint $table): void {
            foreach (['liquidation_critical_buffer_pct', 'liquidation_watch_buffer_pct', 'liquidation_guard_enabled'] as $column) {
                if (Schema::hasColumn('ratio_bot_configs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
