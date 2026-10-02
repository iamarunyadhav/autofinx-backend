<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quick Trade ML shadow — Phase 3 (long horizons, funding, side classifiers).
 * Purely additive: one new table and nullable columns; nothing is altered or
 * dropped, existing rows stay valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Public Binance Futures funding history (GET /fapi/v1/fundingRate).
        if (! Schema::hasTable('ml_funding_rates')) {
            Schema::create('ml_funding_rates', function (Blueprint $table): void {
                $table->id();
                $table->string('symbol', 30);
                $table->unsignedBigInteger('funding_time_ms');
                $table->decimal('funding_rate', 18, 12);
                $table->decimal('mark_price', 24, 10)->nullable();
                $table->timestamps();
                $table->unique(['symbol', 'funding_time_ms'], 'ml_funding_rates_unique');
            });
        }

        if (Schema::hasTable('ml_models') && ! Schema::hasColumn('ml_models', 'model_type')) {
            Schema::table('ml_models', function (Blueprint $table): void {
                // 'regression' (Phase 1/2) or 'side_classifier' (Phase 3).
                $table->string('model_type', 24)->default('regression')->after('feature_schema_version');
            });
        }

        if (Schema::hasTable('ml_shadow_predictions') && ! Schema::hasColumn('ml_shadow_predictions', 'p_buy')) {
            Schema::table('ml_shadow_predictions', function (Blueprint $table): void {
                $table->string('model_type', 24)->nullable()->after('model_version');
                $table->decimal('p_buy', 8, 6)->nullable()->after('predicted_high');
                $table->decimal('p_sell', 8, 6)->nullable()->after('p_buy');
                $table->decimal('expected_net_buy_pct', 16, 8)->nullable()->after('p_sell');
                $table->decimal('expected_net_sell_pct', 16, 8)->nullable()->after('expected_net_buy_pct');
                $table->decimal('funding_estimate_buy_pct', 16, 8)->nullable()->after('expected_net_sell_pct');
                $table->decimal('funding_actual_buy_pct', 16, 8)->nullable()->after('decision_net_pct');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ml_shadow_predictions') && Schema::hasColumn('ml_shadow_predictions', 'p_buy')) {
            Schema::table('ml_shadow_predictions', function (Blueprint $table): void {
                $table->dropColumn(['model_type', 'p_buy', 'p_sell', 'expected_net_buy_pct', 'expected_net_sell_pct', 'funding_estimate_buy_pct', 'funding_actual_buy_pct']);
            });
        }
        if (Schema::hasTable('ml_models') && Schema::hasColumn('ml_models', 'model_type')) {
            Schema::table('ml_models', function (Blueprint $table): void {
                $table->dropColumn('model_type');
            });
        }
        Schema::dropIfExists('ml_funding_rates');
    }
};
