<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ratio_bot_configs', function (Blueprint $table): void {
            if (! Schema::hasColumn('ratio_bot_configs', 'algorithm')) {
                $table->string('algorithm', 32)->default('ratio_threshold')->after('market_type');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'timeframe')) {
                $table->string('timeframe', 16)->default('1m')->after('algorithm');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'side_mode')) {
                $table->string('side_mode', 24)->default('buy_sell')->after('timeframe');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'base_asset_budget')) {
                $table->decimal('base_asset_budget', 20, 8)->nullable()->after('base_quote_amount');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'quote_asset_budget')) {
                $table->decimal('quote_asset_budget', 20, 8)->nullable()->after('base_asset_budget');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'strategy_params')) {
                $table->json('strategy_params')->nullable()->after('quote_asset_budget');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'integration_id')) {
                $table->uuid('integration_id')->nullable()->after('connection_id')->index();
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'stale_feed_timeout_seconds')) {
                $table->integer('stale_feed_timeout_seconds')->nullable()->after('stale_feed_max_age_seconds');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'kill_switch')) {
                $table->boolean('kill_switch')->default(false)->after('kill_switch_enabled');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'leverage')) {
                $table->decimal('leverage', 10, 4)->nullable()->after('take_profit_pct');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'margin_type')) {
                $table->string('margin_type', 16)->nullable()->after('leverage');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'position_mode')) {
                $table->string('position_mode', 16)->nullable()->after('margin_type');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'reduce_only_exit_orders')) {
                $table->boolean('reduce_only_exit_orders')->default(true)->after('position_mode');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'max_open_position_per_symbol')) {
                $table->decimal('max_open_position_per_symbol', 20, 8)->nullable()->after('reduce_only_exit_orders');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'allow_reverse_signal')) {
                $table->boolean('allow_reverse_signal')->default(false)->after('max_open_position_per_symbol');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'close_on_opposite_signal')) {
                $table->boolean('close_on_opposite_signal')->default(true)->after('allow_reverse_signal');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'risk_per_trade')) {
                $table->decimal('risk_per_trade', 10, 4)->nullable()->after('close_on_opposite_signal');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'max_daily_loss')) {
                $table->decimal('max_daily_loss', 20, 8)->nullable()->after('risk_per_trade');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'max_consecutive_losses')) {
                $table->integer('max_consecutive_losses')->nullable()->after('max_daily_loss');
            }
        });

        Schema::table('ratio_bot_states', function (Blueprint $table): void {
            if (! Schema::hasColumn('ratio_bot_states', 'feed_status')) {
                $table->string('feed_status', 24)->nullable()->after('last_price_timestamp');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'feed_status_updated_at')) {
                $table->timestamp('feed_status_updated_at')->nullable()->after('feed_status');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'last_signal')) {
                $table->string('last_signal', 24)->nullable()->after('feed_status_updated_at');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'last_signal_at')) {
                $table->timestamp('last_signal_at')->nullable()->after('last_signal');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'last_signal_reason')) {
                $table->text('last_signal_reason')->nullable()->after('last_signal_at');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'last_signal_hash')) {
                $table->string('last_signal_hash', 80)->nullable()->after('last_signal_reason');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'last_order_status')) {
                $table->string('last_order_status', 32)->nullable()->after('last_signal_hash');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'last_fill_price')) {
                $table->decimal('last_fill_price', 20, 8)->nullable()->after('last_order_status');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'last_position_side')) {
                $table->string('last_position_side', 16)->nullable()->after('last_fill_price');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'last_position_qty')) {
                $table->decimal('last_position_qty', 20, 8)->nullable()->after('last_position_side');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'entry_price')) {
                $table->decimal('entry_price', 20, 8)->nullable()->after('last_position_qty');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'unrealized_pnl')) {
                $table->decimal('unrealized_pnl', 20, 8)->nullable()->after('entry_price');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'last_candle_close_at')) {
                $table->timestamp('last_candle_close_at')->nullable()->after('unrealized_pnl');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'last_runtime_error')) {
                $table->text('last_runtime_error')->nullable()->after('last_candle_close_at');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'runtime_meta')) {
                $table->json('runtime_meta')->nullable()->after('last_runtime_error');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'trade_day')) {
                $table->date('trade_day')->nullable()->after('runtime_meta');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'daily_trade_count')) {
                $table->integer('daily_trade_count')->default(0)->after('trade_day');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'daily_loss_total')) {
                $table->decimal('daily_loss_total', 20, 8)->default(0)->after('daily_trade_count');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'consecutive_losses')) {
                $table->integer('consecutive_losses')->default(0)->after('daily_loss_total');
            }
        });

        Schema::table('market_feed_status', function (Blueprint $table): void {
            if (! Schema::hasColumn('market_feed_status', 'exchange')) {
                $table->string('exchange', 32)->default('binance')->after('symbol_id');
            }
            if (! Schema::hasColumn('market_feed_status', 'market_type')) {
                $table->string('market_type', 16)->default('spot')->after('exchange');
            }
            if (! Schema::hasColumn('market_feed_status', 'symbol')) {
                $table->string('symbol', 32)->nullable()->after('market_type');
            }
            if (! Schema::hasColumn('market_feed_status', 'status')) {
                $table->string('status', 24)->default('unknown')->after('source');
            }
        });

        if (! Schema::hasTable('ratio_bot_reconciliation_logs')) {
            Schema::create('ratio_bot_reconciliation_logs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('ratio_bot_config_id')->index();
                $table->string('market_type', 16)->default('spot');
                $table->string('status', 24);
                $table->text('detail')->nullable();
                $table->json('snapshot')->nullable();
                $table->timestamp('occurred_at');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ratio_bot_reconciliation_logs');

        Schema::table('market_feed_status', function (Blueprint $table): void {
            foreach (['status', 'symbol', 'market_type', 'exchange'] as $column) {
                if (Schema::hasColumn('market_feed_status', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('ratio_bot_states', function (Blueprint $table): void {
            foreach ([
                'consecutive_losses',
                'daily_loss_total',
                'daily_trade_count',
                'trade_day',
                'runtime_meta',
                'last_runtime_error',
                'last_candle_close_at',
                'unrealized_pnl',
                'entry_price',
                'last_position_qty',
                'last_position_side',
                'last_fill_price',
                'last_order_status',
                'last_signal_hash',
                'last_signal_reason',
                'last_signal_at',
                'last_signal',
                'feed_status_updated_at',
                'feed_status',
            ] as $column) {
                if (Schema::hasColumn('ratio_bot_states', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('ratio_bot_configs', function (Blueprint $table): void {
            foreach ([
                'max_consecutive_losses',
                'max_daily_loss',
                'risk_per_trade',
                'close_on_opposite_signal',
                'allow_reverse_signal',
                'max_open_position_per_symbol',
                'reduce_only_exit_orders',
                'position_mode',
                'margin_type',
                'leverage',
                'kill_switch',
                'stale_feed_timeout_seconds',
                'integration_id',
                'strategy_params',
                'quote_asset_budget',
                'base_asset_budget',
                'side_mode',
                'timeframe',
                'algorithm',
            ] as $column) {
                if (Schema::hasColumn('ratio_bot_configs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
