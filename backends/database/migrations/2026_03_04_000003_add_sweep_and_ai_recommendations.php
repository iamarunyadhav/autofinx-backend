<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wallet_sweep_rules')) {
            Schema::create('wallet_sweep_rules', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id')->index();
                $table->uuid('user_id')->nullable()->index();
                $table->uuid('bot_id')->nullable()->index();
                $table->string('name', 120)->default('Default Sweep Rule');
                $table->string('provider', 32)->default('binance')->index();
                $table->string('asset_class', 16)->nullable()->index();
                $table->string('asset_code', 32)->nullable()->index();
                $table->string('currency', 10)->default('USD')->index();
                $table->decimal('min_amount', 20, 8)->default(0);
                $table->boolean('auto_withdraw_enabled')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamp('last_run_at')->nullable();
                $table->string('last_status', 32)->nullable();
                $table->string('last_error')->nullable();
                $table->json('meta_json')->nullable();
                $table->timestamps();

                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
                $table->foreign('bot_id')->references('id')->on('bots')->nullOnDelete();
                $table->index(['workspace_id', 'provider', 'currency', 'is_active'], 'sweep_rules_lookup');
            });
        }

        if (! Schema::hasTable('wallet_sweep_runs')) {
            Schema::create('wallet_sweep_runs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id')->index();
                $table->uuid('rule_id')->nullable()->index();
                $table->uuid('bot_id')->nullable()->index();
                $table->string('provider', 32)->index();
                $table->string('asset_class', 16)->nullable()->index();
                $table->string('asset_code', 32)->nullable()->index();
                $table->string('currency', 10)->index();
                $table->decimal('amount', 20, 8)->default(0);
                $table->string('status', 32)->default('queued')->index();
                $table->string('idempotency_key', 190)->nullable()->unique();
                $table->string('trace_id')->nullable()->index();
                $table->json('meta_json')->nullable();
                $table->timestamp('swept_at')->nullable();
                $table->timestamps();

                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
                $table->foreign('rule_id')->references('id')->on('wallet_sweep_rules')->nullOnDelete();
                $table->foreign('bot_id')->references('id')->on('bots')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('ai_recommendations')) {
            Schema::create('ai_recommendations', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id')->index();
                $table->uuid('bot_id')->index();
                $table->string('mode', 24)->default('ADVISORY')->index();
                $table->boolean('applied')->default(false);
                $table->timestamp('applied_at')->nullable();
                $table->json('payload_json');
                $table->timestamps();

                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
                $table->foreign('bot_id')->references('id')->on('bots')->cascadeOnDelete();
                $table->index(['bot_id', 'created_at'], 'ai_reco_bot_created_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_recommendations');
        Schema::dropIfExists('wallet_sweep_runs');
        Schema::dropIfExists('wallet_sweep_rules');
    }
};
