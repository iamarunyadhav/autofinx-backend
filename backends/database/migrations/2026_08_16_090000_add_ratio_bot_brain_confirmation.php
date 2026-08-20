<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ratio_bot_configs', function (Blueprint $table) {
            if (! Schema::hasColumn('ratio_bot_configs', 'brain_enabled')) {
                $table->boolean('brain_enabled')->default(false)->after('algorithm');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'brain_min_confidence')) {
                $table->unsignedTinyInteger('brain_min_confidence')->default(65)->after('brain_enabled');
            }
        });

        // ----------------------------------------------------------------
        // ratio_bot_brain_snapshots
        // Point-in-time Master Brain (SignalPreviewService + TradeForecastService)
        // evaluation, horizon-matched to the bot's own timeframe. Modeled on
        // future_trade_ai_decisions — one immutable row per brain refresh.
        // ----------------------------------------------------------------
        Schema::create('ratio_bot_brain_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ratio_bot_config_id')->index();
            $table->string('symbol', 32);
            $table->string('timeframe', 16);
            $table->integer('horizon_seconds');
            $table->decimal('price_at_evaluation', 20, 8)->nullable();

            // SignalPreviewService output ("Robot Signal")
            $table->string('direction', 8)->nullable(); // buy | sell | neutral
            $table->unsignedTinyInteger('confidence')->nullable();
            $table->decimal('bullish_score', 8, 2)->nullable();
            $table->decimal('neutral_score', 8, 2)->nullable();
            $table->decimal('bearish_score', 8, 2)->nullable();
            $table->json('reasons')->nullable();

            // TradeForecastService output ("Near-term Forecast")
            $table->string('forecast_bias', 8)->nullable(); // BUY | SELL | NEUTRAL
            $table->decimal('forecast_confidence', 6, 2)->nullable();
            $table->decimal('expected_mid_price', 20, 8)->nullable();
            $table->decimal('expected_low', 20, 8)->nullable();
            $table->decimal('expected_high', 20, 8)->nullable();
            $table->decimal('atr_pct', 8, 4)->nullable();

            $table->json('raw_payload')->nullable();

            $table->timestamps();

            $table->foreign('ratio_bot_config_id')
                ->references('id')->on('ratio_bot_configs')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ratio_bot_brain_snapshots');

        Schema::table('ratio_bot_configs', function (Blueprint $table) {
            if (Schema::hasColumn('ratio_bot_configs', 'brain_min_confidence')) {
                $table->dropColumn('brain_min_confidence');
            }
            if (Schema::hasColumn('ratio_bot_configs', 'brain_enabled')) {
                $table->dropColumn('brain_enabled');
            }
        });
    }
};
