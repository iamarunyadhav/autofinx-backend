<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('settings')) {
            // already created in earlier runs; skip to avoid duplicate table error
        } else {
            Schema::create('settings', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id')->nullable()->index();
                $table->string('group')->index();
                $table->string('key')->index();
                $table->json('value_json')->nullable();
                $table->boolean('encrypted')->default(false);
                $table->uuid('updated_by')->nullable();
                $table->timestamps();
                $table->unique(['workspace_id','group','key']);
            });
        }

        if (!Schema::hasTable('market_regimes')) {
            Schema::create('market_regimes', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id')->nullable()->index();
                $table->uuid('symbol_id')->nullable()->index();
                $table->string('regime');
                $table->json('meta_json')->nullable();
                $table->timestamp('detected_at')->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('strategy_performance_snapshots')) {
            Schema::create('strategy_performance_snapshots', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('strategy_instance_id')->index();
                $table->json('metrics_json')->nullable();
                $table->timestamp('captured_at')->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('copy_trading_profiles')) {
            Schema::create('copy_trading_profiles', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('master_instance_id')->index();
                $table->uuid('follower_workspace_id')->index();
                $table->string('allocation_type')->default('PERCENT_EQUITY');
                $table->decimal('allocation_value', 18, 4)->default(0);
                $table->decimal('max_trade_size', 18, 4)->nullable();
                $table->decimal('max_daily_loss', 18, 4)->nullable();
                $table->json('allowed_symbols')->nullable();
                $table->decimal('slippage_limit', 10, 4)->nullable();
                $table->boolean('paused')->default(false);
                $table->timestamps();
                // keep index name short for MySQL
                $table->unique(['master_instance_id','follower_workspace_id'], 'copy_prof_master_follower_unique');
            });
        }

        if (!Schema::hasTable('copy_trade_replications')) {
            Schema::create('copy_trade_replications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('master_trade_id')->index();
                $table->uuid('follower_workspace_id')->index();
                $table->uuid('order_id')->nullable()->index();
                $table->json('meta')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('signals')) {
            Schema::create('signals', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id')->index();
                $table->string('symbol')->index();
                $table->string('action');
                $table->decimal('confidence', 6, 4)->default(0);
                $table->string('regime')->nullable();
                $table->string('strategy_name')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('api_tokens')) {
            Schema::create('api_tokens', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id')->nullable()->index();
                $table->string('name');
                $table->string('token_hash')->unique();
                $table->json('scopes')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('webhooks')) {
            Schema::create('webhooks', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id')->index();
                $table->string('target_url');
                $table->text('secret_encrypted')->nullable();
                $table->json('config')->nullable();
                $table->boolean('enabled')->default(true);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('webhook_deliveries')) {
            Schema::create('webhook_deliveries', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('webhook_id')->index();
                $table->string('status')->default('pending');
                $table->json('payload')->nullable();
                $table->json('response')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhooks');
        Schema::dropIfExists('api_tokens');
        Schema::dropIfExists('signals');
        Schema::dropIfExists('copy_trade_replications');
        Schema::dropIfExists('copy_trading_profiles');
        Schema::dropIfExists('strategy_performance_snapshots');
        Schema::dropIfExists('market_regimes');
        Schema::dropIfExists('settings');
    }
};
