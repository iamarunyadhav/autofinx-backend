<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_trading_configs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->unique();
            $table->boolean('enabled')->default(false);
            $table->string('mode', 32)->default('advisor');
            $table->string('exchange', 32)->default('binance');
            $table->string('market_type', 32)->default('spot');
            $table->json('allowed_symbols')->nullable();
            $table->json('blocked_symbols')->nullable();
            $table->decimal('max_trade_size_pct', 10, 4)->nullable();
            $table->decimal('min_confidence', 10, 4)->nullable();
            $table->boolean('require_stop_loss')->default(true);
            $table->boolean('require_take_profit')->default(true);
            $table->boolean('require_time_stop')->default(true);
            $table->boolean('auto_trade_enabled')->default(false);
            $table->text('notes')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('ai_decisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->uuid('requested_by')->nullable()->index();
            $table->uuid('approved_by')->nullable()->index();
            $table->uuid('rejected_by')->nullable()->index();
            $table->uuid('executed_order_id')->nullable()->index();
            $table->uuid('integration_id')->nullable()->index();
            $table->string('exchange', 32)->default('binance');
            $table->string('market_type', 32)->default('spot');
            $table->string('operator_mode', 32)->default('advisor');
            $table->string('symbol', 32)->index();
            $table->string('action', 32)->nullable()->index();
            $table->decimal('confidence', 10, 4)->nullable();
            $table->json('context_summary_json')->nullable();
            $table->json('blockers_json')->nullable();
            $table->longText('raw_proposal_text')->nullable();
            $table->json('raw_proposal_json')->nullable();
            $table->json('validated_proposal_json')->nullable();
            $table->json('validation_result_json')->nullable();
            $table->json('policy_result_json')->nullable();
            $table->string('provider_name', 64)->nullable();
            $table->string('provider_model', 128)->nullable();
            $table->string('final_status', 32)->default('recorded')->index();
            $table->boolean('requires_approval')->default(false);
            $table->string('rejection_reason')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('rejected_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('executed_order_id')->references('id')->on('orders')->nullOnDelete();
            $table->foreign('integration_id')->references('id')->on('integrations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_decisions');
        Schema::dropIfExists('ai_trading_configs');
    }
};
