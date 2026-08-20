<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ratio_bot_configs', function (Blueprint $table) {
            if (! Schema::hasColumn('ratio_bot_configs', 'max_entry_price_drift_bps')) {
                $table->decimal('max_entry_price_drift_bps', 8, 4)->nullable()->default(50.0000)
                    ->after('max_allowed_slippage_bps');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ratio_bot_configs', function (Blueprint $table) {
            if (Schema::hasColumn('ratio_bot_configs', 'max_entry_price_drift_bps')) {
                $table->dropColumn('max_entry_price_drift_bps');
            }
        });
    }
};
