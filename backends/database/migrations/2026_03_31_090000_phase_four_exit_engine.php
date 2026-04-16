<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('workspace_exit_policies')) {
            Schema::create('workspace_exit_policies', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id')->unique();
                $table->decimal('default_stop_loss_pct', 10, 4)->nullable();
                $table->decimal('default_take_profit_pct', 10, 4)->nullable();
                $table->unsignedInteger('default_time_stop_minutes')->nullable();
                $table->boolean('trailing_stop_enabled')->default(false);
                $table->decimal('trailing_stop_pct', 10, 4)->nullable();
                $table->boolean('partial_take_profit_enabled')->default(false);
                $table->json('partial_take_profit_levels')->nullable();
                $table->json('partial_take_profit_sizes')->nullable();
                $table->boolean('break_even_after_partial')->default(false);
                $table->boolean('emergency_exit_on_breaker')->default(false);
                $table->boolean('force_exit_on_pause')->default(false);
                $table->boolean('force_exit_on_max_daily_loss')->default(false);
                $table->decimal('minimum_position_value_for_partial', 20, 8)->nullable();
                $table->string('native_protection_mode', 32)->default('disabled');
                $table->uuid('updated_by')->nullable()->index();
                $table->timestamps();

                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
                $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('position_exit_states')) {
            Schema::create('position_exit_states', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id')->index();
                $table->uuid('position_id')->unique();
                $table->uuid('active_exit_order_id')->nullable()->index();
                $table->string('symbol', 32)->index();
                $table->string('provider', 32)->default('binance')->index();
                $table->string('market_type', 16)->default('spot')->index();
                $table->string('status', 32)->default('open')->index();
                $table->string('managed_mode', 32)->default('review_required')->index();
                $table->unsignedInteger('state_version')->default(1);
                $table->json('exit_policy_snapshot')->nullable();
                $table->decimal('stop_loss_price', 20, 8)->nullable();
                $table->decimal('take_profit_price', 20, 8)->nullable();
                $table->timestamp('time_stop_at')->nullable()->index();
                $table->decimal('trailing_high_water_mark', 20, 8)->nullable();
                $table->decimal('trailing_stop_price', 20, 8)->nullable();
                $table->json('partial_progress')->nullable();
                $table->json('active_native_order_refs')->nullable();
                $table->string('last_exit_decision_hash', 191)->nullable()->index();
                $table->timestamp('last_evaluated_at')->nullable()->index();
                $table->timestamp('last_action_at')->nullable()->index();
                $table->string('blocked_reason', 128)->nullable()->index();
                $table->string('uncertainty_reason', 128)->nullable();
                $table->timestamps();

                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
                $table->foreign('position_id')->references('id')->on('positions')->cascadeOnDelete();
                $table->foreign('active_exit_order_id')->references('id')->on('orders')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('position_exit_events')) {
            Schema::create('position_exit_events', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id')->index();
                $table->uuid('position_exit_state_id')->index();
                $table->uuid('position_id')->index();
                $table->uuid('order_id')->nullable()->index();
                $table->string('symbol', 32)->nullable()->index();
                $table->string('event_type', 64)->index();
                $table->string('severity', 16)->default('info')->index();
                $table->string('source', 64)->nullable()->index();
                $table->string('correlation_id', 150)->nullable()->index();
                $table->json('payload_json')->nullable();
                $table->timestamp('occurred_at')->index();
                $table->timestamps();

                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
                $table->foreign('position_exit_state_id')->references('id')->on('position_exit_states')->cascadeOnDelete();
                $table->foreign('position_id')->references('id')->on('positions')->cascadeOnDelete();
                $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('position_exit_events');
        Schema::dropIfExists('position_exit_states');
        Schema::dropIfExists('workspace_exit_policies');
    }
};
