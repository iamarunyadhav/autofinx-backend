<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('quick_trades')) {
            return;
        }

        Schema::table('quick_trades', function (Blueprint $table) {
            if (! Schema::hasColumn('quick_trades', 'leverage_enabled')) {
                // Live mode only — paper mode ignores this entirely (synthetic
                // payout model, no real leveraged position exists).
                $table->boolean('leverage_enabled')->default(false)->after('stop_loss_triggered_at');
            }
            if (! Schema::hasColumn('quick_trades', 'leverage')) {
                // Meaningful only when leverage_enabled = true. No app-layer
                // upper bound is enforced by schema/validation — the real
                // ceiling is whatever LeverageValidationService determines
                // from Binance's own tiered leverage brackets for this
                // symbol/notional at request time.
                $table->decimal('leverage', 6, 2)->nullable()->after('leverage_enabled');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('quick_trades')) {
            return;
        }

        Schema::table('quick_trades', function (Blueprint $table) {
            foreach (['leverage', 'leverage_enabled'] as $column) {
                if (Schema::hasColumn('quick_trades', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
