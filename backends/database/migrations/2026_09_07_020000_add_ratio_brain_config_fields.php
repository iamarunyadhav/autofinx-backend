<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ratio_bot_configs', function (Blueprint $table): void {
            if (! Schema::hasColumn('ratio_bot_configs', 'ratio_brain_enabled')) {
                $table->boolean('ratio_brain_enabled')->default(false)->after('recovery_lot_min_profit_ratio');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'ratio_brain_shadow_mode')) {
                // Nullable, not a plain default(true) boolean -- null means
                // "inherit the global config('autofinx.ratio_brain.shadow_mode')
                // default" so an operator lowering the global default later
                // doesn't need to touch every bot's row.
                $table->boolean('ratio_brain_shadow_mode')->nullable()->after('ratio_brain_enabled');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'portfolio_guard_min_profit_usdt')) {
                $table->decimal('portfolio_guard_min_profit_usdt', 12, 4)->nullable()->after('ratio_brain_shadow_mode');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'portfolio_guard_safety_margin_pct')) {
                $table->decimal('portfolio_guard_safety_margin_pct', 5, 2)->nullable()->after('portfolio_guard_min_profit_usdt');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'recovery_min_observation_minutes')) {
                $table->integer('recovery_min_observation_minutes')->nullable()->after('portfolio_guard_safety_margin_pct');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'recovery_max_observation_minutes')) {
                $table->integer('recovery_max_observation_minutes')->nullable()->after('recovery_min_observation_minutes');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'sell_selector_weights')) {
                $table->json('sell_selector_weights')->nullable()->after('recovery_max_observation_minutes');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'emergency_atr_multiple_threshold')) {
                $table->decimal('emergency_atr_multiple_threshold', 6, 2)->nullable()->after('sell_selector_weights');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ratio_bot_configs', function (Blueprint $table): void {
            foreach ([
                'emergency_atr_multiple_threshold',
                'sell_selector_weights',
                'recovery_max_observation_minutes',
                'recovery_min_observation_minutes',
                'portfolio_guard_safety_margin_pct',
                'portfolio_guard_min_profit_usdt',
                'ratio_brain_shadow_mode',
                'ratio_brain_enabled',
            ] as $column) {
                if (Schema::hasColumn('ratio_bot_configs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
