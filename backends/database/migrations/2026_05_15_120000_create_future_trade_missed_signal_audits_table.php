<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('future_trade_missed_signal_audits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('future_trade_bot_config_id')->index();
            $table->uuid('workspace_id')->index();
            $table->string('symbol', 32)->index();
            $table->string('side', 16)->index();
            $table->string('timeframe', 16)->default('5m');
            $table->timestamp('signal_time')->index();
            $table->decimal('entry_reference_price', 20, 8);
            $table->decimal('final_confidence', 6, 4)->nullable();
            $table->decimal('active_minimum', 6, 4)->nullable();
            $table->decimal('confidence_gap', 8, 4)->nullable();
            $table->string('strategy_mode', 64)->nullable();
            $table->string('confidence_mode', 32)->nullable();
            $table->boolean('strict_entry_guards')->default(false);
            $table->boolean('core_entry_passed')->default(false);
            $table->unsignedTinyInteger('core_passed_count')->default(0);
            $table->unsignedTinyInteger('review_window_minutes')->default(15);
            $table->string('top_skip_reason', 255)->nullable();
            $table->json('top_3_blockers')->nullable();
            $table->json('soft_penalties_json')->nullable();
            $table->json('hard_blockers_json')->nullable();
            $table->json('component_scores_json')->nullable();
            $table->json('component_breakdown_json')->nullable();
            $table->json('indicator_influence_json')->nullable();
            $table->json('market_snapshot_json')->nullable();
            $table->string('status', 32)->default('PENDING_REVIEW')->index();
            $table->timestamp('review_due_at')->nullable()->index();
            $table->timestamp('reviewed_at')->nullable();
            $table->decimal('max_favorable_move_percent', 10, 4)->nullable();
            $table->decimal('max_adverse_move_percent', 10, 4)->nullable();
            $table->boolean('would_hit_take_profit')->nullable();
            $table->boolean('would_hit_stop_loss')->nullable();
            $table->string('would_have_result', 32)->nullable();
            $table->decimal('would_have_pnl_percent', 10, 4)->nullable();
            $table->timestamps();

            $table->unique(
                ['future_trade_bot_config_id', 'symbol', 'side', 'signal_time'],
                'future_trade_missed_signal_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('future_trade_missed_signal_audits');
    }
};
