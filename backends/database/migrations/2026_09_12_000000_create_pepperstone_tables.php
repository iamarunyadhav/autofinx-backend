<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brand-new Pepperstone Metals & Energy Trading module (Gold/Silver/
 * Platinum/Crude Oil CFDs). Entirely independent of ratio_bot_* and
 * forex_grid_bot_* tables -- no shared columns, no foreign keys into any
 * table belonging to either of those modules. See App\Domain\Pepperstone
 * and App\Jobs\RunPepperstoneBotEvaluationJob.
 *
 * Written but NOT run against any database as part of this change -- per
 * this task's explicit instruction, migrations are reviewed/approved
 * separately before `php artisan migrate` is ever invoked.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ----------------------------------------------------------------
        // instruments
        // Canonical, broker-agnostic instrument catalogue (Gold, Silver,
        // Platinum, Crude Oil). A single instrument can have mappings to
        // multiple providers via instrument_mappings below.
        // ----------------------------------------------------------------
        Schema::create('instruments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('canonical_symbol', 32)->unique(); // e.g. XAUUSD, XAGUSD, XPTUSD, WTICOUSD
            $table->string('market_type', 16); // metal | energy
            $table->string('name');
            $table->string('quote_currency', 8);
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });

        // ----------------------------------------------------------------
        // instrument_mappings
        // Per-provider broker-symbol + contract-spec mapping for an
        // instrument (tick size/value, contract size, min volume/step,
        // margin requirement). Pepperstone's real cTrader/MT5 contract
        // specs are unconfirmed (Phase 0 pending a real account) -- rows
        // for provider='pepperstone' are seeded with placeholder/sandbox
        // values until a real account confirms them.
        // ----------------------------------------------------------------
        Schema::create('instrument_mappings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('instrument_id')->index();
            $table->string('provider', 32);
            $table->string('broker_symbol', 32);

            $table->decimal('tick_size', 18, 8);
            $table->decimal('tick_value', 18, 8);
            $table->decimal('contract_size', 18, 4);
            $table->decimal('min_volume', 10, 4);
            $table->decimal('volume_step', 10, 4);
            $table->decimal('margin_requirement', 10, 6)->nullable();

            $table->timestamp('discovered_at')->nullable();
            $table->timestamps();

            $table->unique(['instrument_id', 'provider']);
            $table->foreign('instrument_id')
                ->references('id')->on('instruments')
                ->onDelete('cascade');
        });

        // ----------------------------------------------------------------
        // pepperstone_bot_configs
        // Operator-controlled settings per workspace + instrument.
        // ----------------------------------------------------------------
        Schema::create('pepperstone_bot_configs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->uuid('instrument_id')->index();

            // Broker connection (credentials live on ExchangeConnection,
            // reused as-is -- see App\Domain\Venue\TradingVenueClientFactory).
            $table->uuid('connection_id')->nullable()->index();

            $table->boolean('enabled')->default(false);

            $table->string('position_sizing_mode', 16)->default('fixed');
            // fixed | martingale | hybrid
            $table->decimal('base_lot_size', 10, 4);
            $table->decimal('martingale_multiplier', 6, 3)->nullable();
            $table->decimal('martingale_max_lot_size', 10, 4)->nullable();
            $table->integer('hybrid_martingale_level_count')->nullable();

            $table->integer('max_grid_levels')->default(8);
            $table->decimal('max_total_exposure_lots', 10, 4);
            $table->decimal('max_position_lots', 10, 4);

            $table->decimal('spread_limit_pips', 10, 2)->nullable();
            $table->decimal('slippage_limit_pips', 10, 2)->nullable();
            $table->decimal('v3_confidence_threshold', 6, 4)->nullable();
            $table->decimal('daily_loss_limit_pct', 6, 2)->nullable();

            $table->decimal('basket_stop_loss_pct', 6, 2)->nullable();
            // Required at the validation layer (controller), not the
            // schema -- mirrors forex_grid_bot_configs' identical
            // nullable-column-but-required-by-controller pattern.
            $table->decimal('basket_take_profit_pct', 6, 2)->nullable();

            $table->timestamps();

            $table->foreign('instrument_id')
                ->references('id')->on('instruments');
        });

        // ----------------------------------------------------------------
        // pepperstone_bot_states
        // Live runtime state. One row per pepperstone_bot_config.
        // ----------------------------------------------------------------
        Schema::create('pepperstone_bot_states', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('pepperstone_bot_config_id')->unique()->index();

            $table->string('status', 16)->default('idle');
            // idle | running | paused | blocked | stopped

            $table->string('block_reason_code', 64)->nullable();
            $table->text('block_reason_detail')->nullable();

            $table->integer('active_levels_count')->default(0);
            $table->decimal('total_exposure_lots', 10, 4)->default(0);
            $table->decimal('basket_avg_price', 18, 6)->nullable();

            $table->timestamp('last_evaluated_at')->nullable();

            $table->timestamps();

            $table->foreign('pepperstone_bot_config_id')
                ->references('id')->on('pepperstone_bot_configs')
                ->onDelete('cascade');
        });

        // ----------------------------------------------------------------
        // pepperstone_positions
        // Every level/position ever opened for a config (audit + live
        // ledger) -- "lots not qty", per this module's own convention.
        // ----------------------------------------------------------------
        Schema::create('pepperstone_positions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('pepperstone_bot_config_id')->index();

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

            $table->foreign('pepperstone_bot_config_id')
                ->references('id')->on('pepperstone_bot_configs')
                ->onDelete('cascade');
        });

        // ----------------------------------------------------------------
        // pepperstone_bot_events
        // Lifecycle / audit events (started, blocked, basket_closed, etc.).
        // ----------------------------------------------------------------
        Schema::create('pepperstone_bot_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('pepperstone_bot_config_id')->index();

            $table->string('event_type', 64);
            $table->json('context_json')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->foreign('pepperstone_bot_config_id')
                ->references('id')->on('pepperstone_bot_configs')
                ->onDelete('cascade');
        });

        // ----------------------------------------------------------------
        // trading_account_snapshots
        // Periodic equity/margin snapshot per workspace+connection+provider
        // -- feeds PositionSizingService's free-margin checks. Generic
        // across providers (not Pepperstone-specific), but introduced here
        // since this module is its first consumer.
        // ----------------------------------------------------------------
        Schema::create('trading_account_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->uuid('connection_id')->nullable()->index();
            $table->string('provider', 32);

            $table->decimal('equity', 18, 4);
            $table->decimal('used_margin', 18, 4);
            $table->decimal('free_margin', 18, 4);
            $table->decimal('balance', 18, 4);

            $table->timestamp('synced_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trading_account_snapshots');
        Schema::dropIfExists('pepperstone_bot_events');
        Schema::dropIfExists('pepperstone_positions');
        Schema::dropIfExists('pepperstone_bot_states');
        Schema::dropIfExists('pepperstone_bot_configs');
        Schema::dropIfExists('instrument_mappings');
        Schema::dropIfExists('instruments');
    }
};
