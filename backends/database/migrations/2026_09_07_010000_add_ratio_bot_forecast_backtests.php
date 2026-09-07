<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ratio_bot_forecast_backtests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('ratio_bot_config_id')->index();
            $table->integer('horizon_seconds');
            $table->unsignedInteger('sample_size')->default(0);
            $table->unsignedInteger('hits')->default(0);
            $table->decimal('hit_rate_pct', 5, 2)->nullable();
            $table->decimal('avg_confidence_pct', 5, 2)->nullable();
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->unique(['ratio_bot_config_id', 'horizon_seconds'], 'rb_forecast_backtests_config_horizon_unique');
            $table->foreign('ratio_bot_config_id')
                ->references('id')
                ->on('ratio_bot_configs')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ratio_bot_forecast_backtests');
    }
};
