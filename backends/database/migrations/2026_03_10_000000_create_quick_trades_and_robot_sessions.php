<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('robot_sessions')) {
            Schema::create('robot_sessions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id')->index();
                $table->uuid('user_id')->nullable()->index();
                $table->uuid('wallet_account_id')->nullable()->index();
                $table->uuid('risk_profile_id')->nullable()->index();
                $table->string('name', 120);
                $table->string('symbol', 32)->index();
                $table->string('asset_class', 16)->default('METALS');
                $table->string('timeframe', 16)->default('1m');
                $table->unsignedInteger('expiry_seconds')->default(60);
                $table->json('indicator_set')->nullable();
                $table->string('strategy_profile', 32)->default('balanced');
                $table->decimal('base_stake', 20, 8);
                $table->decimal('profit_target', 20, 8)->nullable();
                $table->decimal('loss_limit', 20, 8)->nullable();
                $table->unsignedInteger('max_consecutive_losses')->nullable();
                $table->unsignedInteger('max_active_trades')->default(1);
                $table->unsignedTinyInteger('confidence_threshold')->default(60);
                $table->string('mode', 16)->default('paper');
                $table->string('status', 16)->default('STOPPED')->index();
                $table->decimal('total_pnl', 20, 8)->default(0);
                $table->unsignedInteger('win_count')->default(0);
                $table->unsignedInteger('loss_count')->default(0);
                $table->unsignedInteger('consecutive_losses')->default(0);
                $table->dateTime('last_signal_at')->nullable();
                $table->dateTime('last_trade_at')->nullable();
                $table->text('last_error')->nullable();
                $table->json('runtime_json')->nullable();
                $table->timestamps();

                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
                $table->foreign('wallet_account_id')->references('id')->on('wallet_accounts')->nullOnDelete();
                $table->foreign('risk_profile_id')->references('id')->on('risk_profiles')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('quick_trades')) {
            Schema::create('quick_trades', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id')->index();
                $table->uuid('order_id')->nullable()->unique();
                $table->uuid('robot_session_id')->nullable()->index();
                $table->uuid('wallet_account_id')->nullable()->index();
                $table->uuid('created_by')->nullable()->index();
                $table->string('symbol', 32)->index();
                $table->string('asset_class', 16)->default('METALS');
                $table->string('side', 8);
                $table->decimal('stake_amount', 20, 8);
                $table->string('timeframe', 16)->default('1m');
                $table->unsignedInteger('expiry_seconds');
                $table->decimal('payout_ratio', 10, 4)->default(0.91);
                $table->string('mode', 16)->default('paper');
                $table->string('source', 32)->default('manual_quick_trade')->index();
                $table->decimal('entry_price', 20, 8);
                $table->decimal('exit_price', 20, 8)->nullable();
                $table->unsignedTinyInteger('confidence')->nullable();
                $table->string('status', 16)->default('open')->index();
                $table->decimal('profit_loss', 20, 8)->default(0);
                $table->decimal('payout_amount', 20, 8)->default(0);
                $table->json('signal_payload')->nullable();
                $table->json('indicator_snapshot')->nullable();
                $table->dateTime('entry_at');
                $table->dateTime('expires_at')->index();
                $table->dateTime('settled_at')->nullable()->index();
                $table->timestamps();

                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
                $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
                $table->foreign('robot_session_id')->references('id')->on('robot_sessions')->nullOnDelete();
                $table->foreign('wallet_account_id')->references('id')->on('wallet_accounts')->nullOnDelete();
                $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('quick_trades');
        Schema::dropIfExists('robot_sessions');
    }
};
