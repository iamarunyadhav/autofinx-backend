<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ratio_bot_states', function (Blueprint $table): void {
            // Exchange-side reduce-only stop tracking (Part C.1) -- the
            // basis fields let a fill decide "did the cost basis actually
            // change" before cancel-and-replacing a still-valid stop.
            if (! Schema::hasColumn('ratio_bot_states', 'futures_stop_order_id')) {
                $table->string('futures_stop_order_id')->nullable();
            }
            if (! Schema::hasColumn('ratio_bot_states', 'futures_stop_client_order_id')) {
                $table->string('futures_stop_client_order_id')->nullable();
            }
            if (! Schema::hasColumn('ratio_bot_states', 'futures_stop_price')) {
                $table->decimal('futures_stop_price', 20, 8)->nullable();
            }
            if (! Schema::hasColumn('ratio_bot_states', 'futures_stop_basis_price')) {
                $table->decimal('futures_stop_basis_price', 20, 8)->nullable();
            }
            if (! Schema::hasColumn('ratio_bot_states', 'futures_stop_basis_qty')) {
                $table->decimal('futures_stop_basis_qty', 20, 8)->nullable();
            }

            // Funding-rate erosion tracking (Part C.2) -- applied once per
            // 8-hour funding boundary, see RatioBotFundingCostService.
            if (! Schema::hasColumn('ratio_bot_states', 'estimated_funding_cost')) {
                $table->decimal('estimated_funding_cost', 20, 8)->default(0);
            }
            if (! Schema::hasColumn('ratio_bot_states', 'last_funding_rate')) {
                $table->decimal('last_funding_rate', 12, 8)->nullable();
            }
            if (! Schema::hasColumn('ratio_bot_states', 'last_funding_applied_at')) {
                $table->timestamp('last_funding_applied_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('ratio_bot_states', function (Blueprint $table): void {
            foreach ([
                'futures_stop_order_id',
                'futures_stop_client_order_id',
                'futures_stop_price',
                'futures_stop_basis_price',
                'futures_stop_basis_qty',
                'estimated_funding_cost',
                'last_funding_rate',
                'last_funding_applied_at',
            ] as $column) {
                if (Schema::hasColumn('ratio_bot_states', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
