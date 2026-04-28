<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ratio_bot_configs', function (Blueprint $table): void {
            if (! Schema::hasColumn('ratio_bot_configs', 'max_allowed_slippage_bps')) {
                $table->decimal('max_allowed_slippage_bps', 10, 4)->nullable()->after('max_slippage_bps');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'max_trades_per_hour')) {
                $table->integer('max_trades_per_hour')->nullable()->after('cooldown_seconds_after_trade');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'max_trades_per_day')) {
                $table->integer('max_trades_per_day')->nullable()->after('max_trades_per_hour');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'trigger_confirm_seconds')) {
                $table->integer('trigger_confirm_seconds')->default(0)->after('buying_loss_ratio');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'trigger_confirm_updates')) {
                $table->integer('trigger_confirm_updates')->default(1)->after('trigger_confirm_seconds');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'use_candle_close_confirmation')) {
                $table->boolean('use_candle_close_confirmation')->default(false)->after('trigger_confirm_updates');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'min_price_stability_seconds')) {
                $table->integer('min_price_stability_seconds')->default(0)->after('use_candle_close_confirmation');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'max_spike_deviation_bps')) {
                $table->decimal('max_spike_deviation_bps', 10, 4)->nullable()->after('min_price_stability_seconds');
            }
        });

        Schema::table('ratio_bot_states', function (Blueprint $table): void {
            if (! Schema::hasColumn('ratio_bot_states', 'pending_trigger_side')) {
                $table->string('pending_trigger_side', 8)->nullable()->after('buy_trough_price');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'pending_trigger_started_at')) {
                $table->timestamp('pending_trigger_started_at')->nullable()->after('pending_trigger_side');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'pending_trigger_updates')) {
                $table->integer('pending_trigger_updates')->default(0)->after('pending_trigger_started_at');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'pending_trigger_ratio')) {
                $table->decimal('pending_trigger_ratio', 10, 6)->nullable()->after('pending_trigger_updates');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'pending_trigger_price')) {
                $table->decimal('pending_trigger_price', 20, 8)->nullable()->after('pending_trigger_ratio');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'last_active_idempotency_key')) {
                $table->string('last_active_idempotency_key')->nullable()->after('last_executed_client_order_id');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'last_unresolved_order_id')) {
                $table->string('last_unresolved_order_id')->nullable()->after('last_active_idempotency_key');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'last_unresolved_client_order_id')) {
                $table->string('last_unresolved_client_order_id')->nullable()->after('last_unresolved_order_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ratio_bot_states', function (Blueprint $table): void {
            foreach ([
                'last_unresolved_client_order_id',
                'last_unresolved_order_id',
                'last_active_idempotency_key',
                'pending_trigger_price',
                'pending_trigger_ratio',
                'pending_trigger_updates',
                'pending_trigger_started_at',
                'pending_trigger_side',
            ] as $column) {
                if (Schema::hasColumn('ratio_bot_states', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('ratio_bot_configs', function (Blueprint $table): void {
            foreach ([
                'max_spike_deviation_bps',
                'min_price_stability_seconds',
                'use_candle_close_confirmation',
                'trigger_confirm_updates',
                'trigger_confirm_seconds',
                'max_trades_per_day',
                'max_trades_per_hour',
                'max_allowed_slippage_bps',
            ] as $column) {
                if (Schema::hasColumn('ratio_bot_configs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
