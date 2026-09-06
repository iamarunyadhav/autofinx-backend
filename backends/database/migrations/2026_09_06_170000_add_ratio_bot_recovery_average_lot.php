<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ratio_bot_lots', function (Blueprint $table): void {
            if (! Schema::hasColumn('ratio_bot_lots', 'is_recovery_average')) {
                $table->boolean('is_recovery_average')->default(false);
            }
        });

        Schema::table('ratio_bot_configs', function (Blueprint $table): void {
            if (! Schema::hasColumn('ratio_bot_configs', 'recovery_lot_min_profit_ratio')) {
                $table->decimal('recovery_lot_min_profit_ratio', 6, 4)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('ratio_bot_lots', function (Blueprint $table): void {
            if (Schema::hasColumn('ratio_bot_lots', 'is_recovery_average')) {
                $table->dropColumn('is_recovery_average');
            }
        });

        Schema::table('ratio_bot_configs', function (Blueprint $table): void {
            if (Schema::hasColumn('ratio_bot_configs', 'recovery_lot_min_profit_ratio')) {
                $table->dropColumn('recovery_lot_min_profit_ratio');
            }
        });
    }
};
