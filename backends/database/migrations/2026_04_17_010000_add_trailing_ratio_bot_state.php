<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ratio_bot_configs', function (Blueprint $table): void {
            if (! Schema::hasColumn('ratio_bot_configs', 'trigger_mode')) {
                $table->string('trigger_mode', 32)->default('threshold')->after('advisory_mode');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'selling_loss_ratio')) {
                $table->decimal('selling_loss_ratio', 10, 6)->nullable()->after('trigger_mode');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'buying_loss_ratio')) {
                $table->decimal('buying_loss_ratio', 10, 6)->nullable()->after('selling_loss_ratio');
            }
        });

        Schema::table('ratio_bot_states', function (Blueprint $table): void {
            if (! Schema::hasColumn('ratio_bot_states', 'sell_armed')) {
                $table->boolean('sell_armed')->default(false)->after('recovery_action_required');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'sell_peak_ratio')) {
                $table->decimal('sell_peak_ratio', 10, 6)->nullable()->after('sell_armed');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'sell_peak_price')) {
                $table->decimal('sell_peak_price', 20, 8)->nullable()->after('sell_peak_ratio');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'buy_armed')) {
                $table->boolean('buy_armed')->default(false)->after('sell_peak_price');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'buy_trough_ratio')) {
                $table->decimal('buy_trough_ratio', 10, 6)->nullable()->after('buy_armed');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'buy_trough_price')) {
                $table->decimal('buy_trough_price', 20, 8)->nullable()->after('buy_trough_ratio');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ratio_bot_states', function (Blueprint $table): void {
            foreach ([
                'buy_trough_price',
                'buy_trough_ratio',
                'buy_armed',
                'sell_peak_price',
                'sell_peak_ratio',
                'sell_armed',
            ] as $column) {
                if (Schema::hasColumn('ratio_bot_states', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('ratio_bot_configs', function (Blueprint $table): void {
            foreach (['buying_loss_ratio', 'selling_loss_ratio', 'trigger_mode'] as $column) {
                if (Schema::hasColumn('ratio_bot_configs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
