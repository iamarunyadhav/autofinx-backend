<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brand-new Forex Grid Trading module (OANDA-backed). Entirely independent
 * of the ratio_bot_* tables -- no shared columns, no foreign keys into any
 * ratio_bot_* table. See App\Domain\ForexGrid and App\Jobs\RunForexGridBotEvaluationJob.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ----------------------------------------------------------------
        // forex_grid_bot_configs
        // Operator-controlled settings per workspace + symbol.
        // ----------------------------------------------------------------
        Schema::create('forex_grid_bot_configs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();

            // Broker connection (OANDA credentials live on ExchangeConnection,
            // reused as-is -- see App\Domain\Venue\TradingVenueClientFactory).
            $table->uuid('connection_id')->nullable()->index();

            $table->string('provider', 32)->default('oanda');
            $table->string('symbol', 32); // bare format, e.g. XAUUSD, EURUSD
            $table->boolean('enabled')->default(false);

            $table->decimal('grid_spacing_pips', 10, 2);

            $table->string('position_sizing_mode', 16)->default('fixed');
            // fixed | martingale | hybrid
            $table->decimal('base_lot_size', 10, 4);
            $table->decimal('martingale_multiplier', 6, 3)->nullable();
            $table->decimal('martingale_max_lot_size', 10, 4)->nullable();
            $table->integer('hybrid_martingale_level_count')->nullable();

            $table->integer('max_grid_levels')->default(8);
            $table->decimal('max_total_exposure_lots', 10, 4);

            $table->string('direction_mode', 16)->default('both');
            // both | buy_only | sell_only

            $table->decimal('basket_take_profit_pct', 6, 2)->nullable();
            $table->decimal('basket_stop_loss_pct', 6, 2)->nullable();

            $table->timestamps();
        });

        // ----------------------------------------------------------------
        // forex_grid_bot_states
        // Live runtime state. One row per forex_grid_bot_config.
        // ----------------------------------------------------------------
        Schema::create('forex_grid_bot_states', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('forex_grid_bot_config_id')->unique()->index();

            $table->string('status', 16)->default('idle');
            // idle | running | paused | blocked | stopped

            $table->string('block_reason_code', 64)->nullable();
            $table->text('block_reason_detail')->nullable();

            $table->integer('active_levels_count')->default(0);
            $table->decimal('total_exposure_lots', 10, 4)->default(0);
            $table->decimal('basket_avg_price', 18, 6)->nullable();

            $table->timestamp('last_evaluated_at')->nullable();

            $table->timestamps();

            $table->foreign('forex_grid_bot_config_id')
                ->references('id')->on('forex_grid_bot_configs')
                ->onDelete('cascade');
        });

        // ----------------------------------------------------------------
        // forex_grid_bot_levels
        // Every grid level ever opened for a config (audit + live ledger).
        // ----------------------------------------------------------------
        Schema::create('forex_grid_bot_levels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('forex_grid_bot_config_id')->index();

            $table->integer('level_index');
            $table->string('side', 8); // buy | sell
            $table->decimal('entry_price', 18, 6);
            $table->decimal('lot_size', 10, 4);
            $table->string('order_id')->nullable();

            $table->string('status', 16)->default('pending');
            // pending | filled | closed | cancelled

            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->decimal('realized_pnl', 18, 6)->nullable();

            $table->timestamps();

            $table->foreign('forex_grid_bot_config_id')
                ->references('id')->on('forex_grid_bot_configs')
                ->onDelete('cascade');
        });

        // ----------------------------------------------------------------
        // forex_grid_bot_events
        // Lifecycle / audit events (started, blocked, basket_closed, etc.).
        // ----------------------------------------------------------------
        Schema::create('forex_grid_bot_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('forex_grid_bot_config_id')->index();

            $table->string('event_type', 64);
            $table->json('context_json')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->foreign('forex_grid_bot_config_id')
                ->references('id')->on('forex_grid_bot_configs')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forex_grid_bot_events');
        Schema::dropIfExists('forex_grid_bot_levels');
        Schema::dropIfExists('forex_grid_bot_states');
        Schema::dropIfExists('forex_grid_bot_configs');
    }
};
