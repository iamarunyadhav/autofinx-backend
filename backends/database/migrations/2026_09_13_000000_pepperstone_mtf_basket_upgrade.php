<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MTF Confirmation + multi-slot equal-lot Basket upgrade for the
 * Pepperstone Metals & Energy module (see
 * C:\Users\PC\.claude\plans\cosmic-knitting-marshmallow.md).
 *
 * This is a genuine schema redesign of the single-ladder, BUY-only module
 * created in 2026_09_12_000000_create_pepperstone_tables.php -- authorized
 * because nothing from that migration has ever been applied to any
 * database (it is still pending alongside this one). Written but NOT run
 * against any database -- same "review before migrate" discipline as every
 * prior module this session.
 *
 * Decisions made explicit here (per this task's brief):
 *   - `pepperstone_positions` is LEFT IN PLACE, UNUSED, rather than dropped:
 *     tests/Unit/Pepperstone/{RiskEngineTest,PositionSizingServiceTest}.php
 *     and tests/Feature/Pepperstone/PepperstoneBrokerAdapterTest.php still
 *     construct/exercise the OLD PepperstoneBotConfig shape (position
 *     sizing mode, martingale fields, max_grid_levels) directly against
 *     RiskEngine/PositionSizingService, which are additive-extended, not
 *     replaced, by this upgrade. Dropping the table would not by itself
 *     break those tests (they never touch pepperstone_positions rows), but
 *     leaving it in place costs nothing and avoids an unnecessary
 *     destructive DDL statement in a pre-deployment migration that already
 *     does a lot. Only RunPepperstoneBotEvaluationJob (which owned that
 *     table) is being rewritten to the basket model -- see that file's new
 *     docblock.
 *   - `position_sizing_mode`/`martingale_*`/`hybrid_martingale_level_count`
 *     columns on pepperstone_bot_configs are LEFT IN PLACE (nullable
 *     already except position_sizing_mode, which keeps its 'fixed'
 *     default) rather than dropped, for the same reason: minimal schema
 *     churn, and PepperstoneConfigValidationTest/RiskEngineTest/
 *     PositionSizingServiceTest still construct configs with them. They are
 *     simply no longer part of PepperstoneBotController's validation
 *     surface (see that controller) -- basket configs are equal-lot only,
 *     per the plan's explicit "no martingale" rule.
 *   - `max_grid_levels` is kept as-is (not renamed) for the same
 *     backward-compatibility reason; the new basket cap lives in a new,
 *     separate `max_basket_levels` column so old and new code paths never
 *     collide on one column's meaning.
 *   - `v3_confidence_threshold` is REUSED as-is for the basket engine's
 *     "min_v3_confidence" role (per the plan's own instruction) -- no
 *     rename, just a documented reuse; a new `min_mtf_confidence` column is
 *     added alongside it for the MTF-side threshold.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A failed MySQL attempt can leave both basket tables created while
        // Laravel still considers this migration pending. In that state the
        // config columns have also already been added, so skip the entire
        // column phase and repair only the missing index.
        if (Schema::hasTable('pepperstone_baskets') && Schema::hasTable('pepperstone_basket_slots')) {
            if (! Schema::hasIndex('pepperstone_basket_slots', 'ps_basket_slots_basket_level_unique')) {
                Schema::table('pepperstone_basket_slots', function (Blueprint $table) {
                    $table->unique(
                        ['pepperstone_basket_id', 'level_index'],
                        'ps_basket_slots_basket_level_unique'
                    );
                });
            }

            return;
        }

        // MySQL DDL is not transactional. If a previous attempt reached this
        // point and failed later, some columns can already exist even though
        // Laravel did not record the migration as ran. Add only missing ones
        // so the migration can safely resume.
        if (! Schema::hasColumn('pepperstone_bot_configs', 'lot_per_level')) {
            Schema::table('pepperstone_bot_configs', fn (Blueprint $table) =>
                $table->decimal('lot_per_level', 10, 4)->nullable()->after('base_lot_size'));
        }
        if (! Schema::hasColumn('pepperstone_bot_configs', 'max_basket_levels')) {
            Schema::table('pepperstone_bot_configs', fn (Blueprint $table) =>
                $table->integer('max_basket_levels')->default(8)->after('max_grid_levels'));
        }
        if (! Schema::hasColumn('pepperstone_bot_configs', 'min_mtf_confidence')) {
            Schema::table('pepperstone_bot_configs', fn (Blueprint $table) =>
                $table->decimal('min_mtf_confidence', 6, 4)->nullable()->after('v3_confidence_threshold'));
        }
        if (! Schema::hasColumn('pepperstone_bot_configs', 'portfolio_exposure_cap_lots')) {
            Schema::table('pepperstone_bot_configs', fn (Blueprint $table) =>
                $table->decimal('portfolio_exposure_cap_lots', 12, 4)->nullable()->after('max_total_exposure_lots'));
        }
        if (! Schema::hasColumn('pepperstone_bot_configs', 'maximum_allowed_slippage')) {
            Schema::table('pepperstone_bot_configs', fn (Blueprint $table) =>
                $table->decimal('maximum_allowed_slippage', 10, 4)->nullable()->after('slippage_limit_pips'));
        }
        if (! Schema::hasColumn('pepperstone_bot_configs', 'margin_safety_reserve_floor')) {
            Schema::table('pepperstone_bot_configs', fn (Blueprint $table) =>
                $table->decimal('margin_safety_reserve_floor', 18, 4)->nullable()->after('daily_loss_limit_pct'));
        }

        // base_lot_size was NOT NULL under the old single-ladder/martingale
        // design (position_sizing_mode='fixed' always needed it). Basket
        // configs use lot_per_level instead and never populate
        // base_lot_size at all -- widened to nullable here so
        // PepperstoneBotController's basket-only validation surface (which
        // no longer accepts position_sizing_mode/base_lot_size/martingale_*
        // at all, per the plan's "no martingale" rule) can create a config
        // without violating a NOT NULL constraint on a column it never
        // writes to. position_sizing_mode/martingale_* columns are left
        // exactly as they were (position_sizing_mode already defaults to
        // 'fixed') -- present but unused by any new code path.
        Schema::table('pepperstone_bot_configs', function (Blueprint $table) {
            $table->decimal('base_lot_size', 10, 4)->nullable()->change();
        });

        // ------------------------------------------------------------
        // pepperstone_baskets
        // One basket = one directional (BUY or SELL) multi-slot position
        // for a config. Only one non-closed basket per config at a time
        // (enforced in RunPepperstoneBotEvaluationJob, not a DB
        // constraint, mirroring this module's existing convention of
        // enforcing basket-shape invariants in application code).
        // ------------------------------------------------------------
        Schema::create('pepperstone_baskets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('pepperstone_bot_config_id')->index();

            $table->string('direction', 8); // buy | sell
            $table->string('status', 16)->default('idle');
            // idle | watching | ready | active | paused | exiting | closed | risk_blocked

            $table->integer('max_levels')->default(8);
            $table->integer('opened_levels')->default(0);
            $table->decimal('lot_per_level', 10, 4);

            $table->decimal('average_entry', 18, 6)->nullable();
            $table->decimal('floating_pnl', 18, 6)->nullable();

            $table->decimal('take_profit_target', 18, 6)->nullable();
            $table->decimal('maximum_allowed_loss', 18, 6)->nullable();

            $table->string('v3_direction', 8)->nullable();
            $table->decimal('v3_confidence', 6, 4)->nullable();
            $table->decimal('mtf_confidence', 6, 4)->nullable();
            $table->decimal('mtf_agreement_score', 6, 4)->nullable();

            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('closed_reason', 64)->nullable();

            $table->timestamps();

            $table->foreign('pepperstone_bot_config_id')
                ->references('id')->on('pepperstone_bot_configs')
                ->onDelete('cascade');

            $table->index(['pepperstone_bot_config_id', 'status']);
        });

        // ------------------------------------------------------------
        // pepperstone_basket_slots
        // Individual equal-lot fills within a basket -- supersedes
        // pepperstone_positions for all NEW trading (that table stays,
        // unused, for the reason documented above).
        // ------------------------------------------------------------
        Schema::create('pepperstone_basket_slots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('pepperstone_basket_id')->index();

            $table->integer('level_index');
            $table->decimal('entry_price', 18, 6);
            $table->decimal('lot_size', 10, 4);
            $table->string('order_id')->nullable();

            $table->string('status', 16)->default('pending');
            // pending | filled | closed | cancelled

            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->decimal('realized_pnl', 18, 6)->nullable();

            $table->timestamps();

            $table->foreign('pepperstone_basket_id')
                ->references('id')->on('pepperstone_baskets')
                ->onDelete('cascade');

            // Keep the MySQL index identifier under its 64-character limit.
            $table->unique(
                ['pepperstone_basket_id', 'level_index'],
                'ps_basket_slots_basket_level_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pepperstone_basket_slots');
        Schema::dropIfExists('pepperstone_baskets');

        Schema::table('pepperstone_bot_configs', function (Blueprint $table) {
            $table->dropColumn([
                'lot_per_level',
                'max_basket_levels',
                'min_mtf_confidence',
                'portfolio_exposure_cap_lots',
                'maximum_allowed_slippage',
                'margin_safety_reserve_floor',
            ]);
        });
    }
};
