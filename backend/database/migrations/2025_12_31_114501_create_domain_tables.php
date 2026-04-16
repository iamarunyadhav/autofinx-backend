<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('timezone')->default('Asia/Colombo');
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('workspace_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('user_id');
            $table->string('role')->default('trader');
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['workspace_id', 'user_id']);
        });

        Schema::create('symbols', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('symbol')->index();
            $table->string('type')->default('crypto');
            $table->string('exchange')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('market_candles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('symbol_id')->index();
            $table->string('timeframe')->default('1m');
            $table->timestamp('opens_at');
            $table->decimal('open', 16, 8);
            $table->decimal('high', 16, 8);
            $table->decimal('low', 16, 8);
            $table->decimal('close', 16, 8);
            $table->decimal('volume', 24, 8)->default(0);
            $table->timestamps();

            $table->foreign('symbol_id')->references('id')->on('symbols')->cascadeOnDelete();
            $table->unique(['symbol_id', 'timeframe', 'opens_at']);
        });

        Schema::create('strategies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->json('params_schema')->nullable();
            $table->boolean('enabled')->default(true);
            $table->string('category')->nullable();
            $table->timestamps();
        });

        Schema::create('strategy_instances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('strategy_id');
            $table->string('name');
            $table->string('mode')->default('paper');
            $table->string('status')->default('idle');
            $table->json('performance')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('strategy_id')->references('id')->on('strategies')->cascadeOnDelete();
        });

        Schema::create('strategy_params', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('instance_id');
            $table->string('key');
            $table->json('value')->nullable();
            $table->timestamps();

            $table->foreign('instance_id')->references('id')->on('strategy_instances')->cascadeOnDelete();
            $table->unique(['instance_id', 'key']);
        });

        Schema::create('schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->string('name');
            $table->json('window_json')->nullable();
            $table->unsignedBigInteger('interval_ms')->default(60000);
            $table->unsignedInteger('cooldown_s')->default(0);
            $table->json('max_trades_json')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });

        Schema::create('risk_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->string('name');
            $table->json('rules')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });

        Schema::create('instance_risk_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('instance_id');
            $table->uuid('risk_profile_id');
            $table->timestamps();

            $table->foreign('instance_id')->references('id')->on('strategy_instances')->cascadeOnDelete();
            $table->foreign('risk_profile_id')->references('id')->on('risk_profiles')->cascadeOnDelete();
            $table->unique(['instance_id', 'risk_profile_id']);
        });

        Schema::create('exchange_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->string('name');
            $table->string('provider');
            $table->string('api_key_encrypted')->nullable();
            $table->string('api_secret_encrypted')->nullable();
            $table->string('passphrase_encrypted')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_testnet')->default(false);
            $table->json('permissions')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });

        Schema::create('broker_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->string('name');
            $table->string('provider');
            $table->json('credentials')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('instance_id')->nullable();
            $table->uuid('symbol_id')->nullable();
            $table->string('side');
            $table->string('type')->default('limit');
            $table->decimal('quantity', 20, 8);
            $table->decimal('price', 20, 8)->nullable();
            $table->string('status')->default('pending');
            $table->json('meta')->nullable();
            $table->string('external_id')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('instance_id')->references('id')->on('strategy_instances')->nullOnDelete();
            $table->foreign('symbol_id')->references('id')->on('symbols')->nullOnDelete();
            $table->index(['workspace_id', 'status']);
        });

        Schema::create('trades', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->uuid('workspace_id');
            $table->decimal('price', 20, 8);
            $table->decimal('quantity', 20, 8);
            $table->decimal('fee', 20, 8)->default(0);
            $table->timestamp('executed_at');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });

        Schema::create('positions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('symbol_id');
            $table->decimal('quantity', 20, 8)->default(0);
            $table->decimal('avg_price', 20, 8)->default(0);
            $table->decimal('unrealized_pl', 20, 8)->default(0);
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('symbol_id')->references('id')->on('symbols')->cascadeOnDelete();
            $table->unique(['workspace_id', 'symbol_id']);
        });

        Schema::create('ledgers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->decimal('balance', 20, 8)->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });

        Schema::create('ledger_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ledger_id');
            $table->string('type');
            $table->decimal('amount', 20, 8);
            $table->json('meta')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->string('idempotency_key')->nullable()->unique();
            $table->timestamps();

            $table->foreign('ledger_id')->references('id')->on('ledgers')->cascadeOnDelete();
        });

        Schema::create('plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->decimal('price', 10, 2)->default(0);
            $table->string('interval')->default('monthly');
            $table->json('features')->nullable();
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('plan_id');
            $table->string('status')->default('active');
            $table->timestamp('renews_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('plan_id')->references('id')->on('plans')->cascadeOnDelete();
        });

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->string('type');
            $table->json('details')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->decimal('amount', 12, 2);
            $table->string('status')->default('pending');
            $table->date('due_date')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('invoice_id')->nullable();
            $table->uuid('payment_method_id')->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('status')->default('pending');
            $table->string('provider')->default('payhere');
            $table->string('reference')->nullable();
            $table->json('metadata')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('invoice_id')->references('id')->on('invoices')->nullOnDelete();
            $table->foreign('payment_method_id')->references('id')->on('payment_methods')->nullOnDelete();
            $table->index(['workspace_id', 'status']);
        });

        Schema::create('webhooks_inbox', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('source')->index();
            $table->string('idempotency_key')->unique();
            $table->string('event_id')->nullable()->index();
            $table->json('payload');
            $table->string('status')->default('pending');
            $table->timestamp('received_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->nullable();
            $table->uuid('user_id')->nullable();
            $table->string('type');
            $table->json('content')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('notification_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->json('preferences')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->nullable();
            $table->string('action');
            $table->uuid('actor_id')->nullable();
            $table->string('actor_type')->nullable();
            $table->uuid('target_id')->nullable();
            $table->string('target_type')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->nullOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('assistant_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('workspace_id')->nullable();
            $table->json('context')->nullable();
            $table->json('messages')->nullable();
            $table->boolean('voice_enabled')->default(false);
            $table->string('mode')->nullable();
            $table->string('language')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->nullOnDelete();
        });

        Schema::create('config_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->string('config_key');
            $table->json('new_value')->nullable();
            $table->json('previous_value')->nullable();
            $table->uuid('actor_id')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['workspace_id', 'config_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('config_versions');
        Schema::dropIfExists('assistant_sessions');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('notification_settings');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('webhooks_inbox');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('ledger_transactions');
        Schema::dropIfExists('ledgers');
        Schema::dropIfExists('positions');
        Schema::dropIfExists('trades');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('broker_connections');
        Schema::dropIfExists('exchange_connections');
        Schema::dropIfExists('instance_risk_profiles');
        Schema::dropIfExists('risk_profiles');
        Schema::dropIfExists('schedules');
        Schema::dropIfExists('strategy_params');
        Schema::dropIfExists('strategy_instances');
        Schema::dropIfExists('strategies');
        Schema::dropIfExists('market_candles');
        Schema::dropIfExists('symbols');
        Schema::dropIfExists('workspace_members');
        Schema::dropIfExists('workspaces');
    }
};
