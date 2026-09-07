<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ratio_bot_configs', function (Blueprint $table): void {
            if (! Schema::hasColumn('ratio_bot_configs', 'forecast_horizon_seconds')) {
                // Null = derive from timeframe (unchanged legacy behavior,
                // see RatioBotBrainService::horizonSeconds()). An explicit
                // value decouples "how often to re-run the brain / what
                // window TradeForecastService projects over" from "which
                // candle size the ladder/stage logic itself trades on".
                $table->integer('forecast_horizon_seconds')->nullable()->after('brain_min_confidence');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ratio_bot_configs', function (Blueprint $table): void {
            if (Schema::hasColumn('ratio_bot_configs', 'forecast_horizon_seconds')) {
                $table->dropColumn('forecast_horizon_seconds');
            }
        });
    }
};
