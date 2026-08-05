<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('future_trade_ai_decisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->uuid('future_trade_bot_config_id')->index();
            $table->string('signal_key')->index(); // NOT unique — error retries may re-log
            $table->string('symbol', 24);
            $table->string('side', 8); // LONG|SHORT
            $table->string('timeframe', 8)->default('15m');
            $table->string('mode', 12)->default('paper'); // paper|testnet|live
            $table->decimal('signal_confidence', 8, 4)->nullable();
            $table->decimal('pine_score', 8, 2)->nullable();

            // AI verdict
            $table->string('decision', 24)->index(); // CONFIRM|VETO|RESIZE|ERROR_FAIL_OPEN|ERROR_FAIL_CLOSED|SKIPPED_CAP
            $table->decimal('ai_confidence', 8, 4)->nullable();
            $table->decimal('size_multiplier', 6, 4)->nullable();
            $table->decimal('sl_adjust_pct', 8, 4)->nullable();
            $table->decimal('tp_adjust_pct', 8, 4)->nullable();
            $table->text('reasoning')->nullable();
            $table->json('risk_flags')->nullable();

            // Provenance + cost
            $table->string('ai_provider', 32)->default('anthropic');
            $table->string('ai_model', 64)->nullable();
            $table->string('prompt_version', 64)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('cache_read_input_tokens')->nullable();
            $table->unsignedInteger('cache_creation_input_tokens')->nullable();
            $table->decimal('cost_usd', 12, 6)->nullable();
            $table->text('error')->nullable();
            $table->json('context_json')->nullable();

            // Execution linkage + outcome (backfilled)
            $table->boolean('executed')->default(false);
            $table->string('block_reason', 64)->nullable();
            $table->uuid('logical_trade_id')->nullable()->index();
            $table->string('outcome', 16)->nullable(); // win|loss|breakeven
            $table->decimal('outcome_pnl', 20, 10)->nullable();
            $table->timestamp('outcome_filled_at')->nullable();

            $table->timestamps();

            // Explicit names — MySQL caps identifier length at 64 chars and the
            // auto-generated names for these composites exceed it.
            $table->index(['future_trade_bot_config_id', 'created_at'], 'ft_ai_decisions_config_created_idx');
            $table->index(['workspace_id', 'decision', 'created_at'], 'ft_ai_decisions_ws_decision_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('future_trade_ai_decisions');
    }
};
