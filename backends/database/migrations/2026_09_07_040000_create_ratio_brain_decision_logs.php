<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ratio_brain_decision_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('ratio_bot_config_id')->index();
            $table->timestamp('occurred_at')->index();
            $table->decimal('market_price', 24, 8)->nullable();
            $table->string('position_state')->nullable();
            $table->string('action');
            $table->text('reason')->nullable();
            $table->boolean('shadow_mode')->default(true);
            $table->json('trend_score_breakdown')->nullable();
            $table->json('portfolio_guard_result')->nullable();
            $table->unsignedInteger('observation_window_minutes')->nullable();
            $table->json('sell_candidates')->nullable();
            $table->json('emergency_assessment')->nullable();
            $table->decimal('overall_cycle_profit_at_decision_time', 24, 8)->nullable();
            $table->boolean('executed')->default(false);
            $table->timestamps();

            $table->foreign('ratio_bot_config_id')
                ->references('id')
                ->on('ratio_bot_configs')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ratio_brain_decision_logs');
    }
};
