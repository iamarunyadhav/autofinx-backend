<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ratio_bot_configs', function (Blueprint $table) {
            if (! Schema::hasColumn('ratio_bot_configs', 'stage_buy_allocations')) {
                $table->json('stage_buy_allocations')->nullable()->after('initial_entry_pct');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'stage_buy_drops')) {
                $table->json('stage_buy_drops')->nullable()->after('stage_buy_allocations');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'stage_sell_allocations')) {
                $table->json('stage_sell_allocations')->nullable()->after('initial_exit_pct');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'stage_sell_rises')) {
                $table->json('stage_sell_rises')->nullable()->after('stage_sell_allocations');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'strategy_reserve_pct')) {
                $table->decimal('strategy_reserve_pct', 6, 4)->default(0)->after('reserve_cash_buffer_pct');
            }
        });

        Schema::table('ratio_bot_states', function (Blueprint $table) {
            if (! Schema::hasColumn('ratio_bot_states', 'strategy_health_status')) {
                $table->string('strategy_health_status', 64)->nullable()->after('position_state');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'market_regime')) {
                $table->string('market_regime', 32)->nullable()->after('strategy_health_status');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'range_status')) {
                $table->string('range_status', 64)->nullable()->after('market_regime');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'current_stage_side')) {
                $table->string('current_stage_side', 8)->nullable()->after('range_status');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'current_stage_index')) {
                $table->unsignedTinyInteger('current_stage_index')->nullable()->after('current_stage_side');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'used_buy_allocation_pct')) {
                $table->decimal('used_buy_allocation_pct', 8, 4)->default(0)->after('current_stage_index');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'used_sell_allocation_pct')) {
                $table->decimal('used_sell_allocation_pct', 8, 4)->default(0)->after('used_buy_allocation_pct');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'observed_low_price')) {
                $table->decimal('observed_low_price', 20, 8)->nullable()->after('used_sell_allocation_pct');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'observed_high_price')) {
                $table->decimal('observed_high_price', 20, 8)->nullable()->after('observed_low_price');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'review_alerted_at')) {
                $table->timestamp('review_alerted_at')->nullable()->after('observed_high_price');
            }
        });

        if (! Schema::hasTable('ratio_bot_lots')) {
            Schema::create('ratio_bot_lots', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('ratio_bot_config_id')->index();
                $table->uuid('buy_decision_id')->nullable()->index();
                $table->string('buy_order_id')->nullable();
                $table->unsignedTinyInteger('buy_stage_index')->default(1);
                $table->decimal('allocation_pct', 8, 4)->default(0);
                $table->decimal('buy_price', 20, 8);
                $table->decimal('original_qty', 20, 8);
                $table->decimal('remaining_qty', 20, 8);
                $table->decimal('spent_quote', 20, 8)->default(0);
                $table->string('status', 16)->default('open')->index();
                $table->timestamp('bought_at')->nullable();
                $table->json('sell_fills')->nullable();
                $table->decimal('realized_pnl', 20, 8)->default(0);
                $table->timestamp('closed_at')->nullable();
                $table->timestamps();

                $table->foreign('ratio_bot_config_id')
                    ->references('id')->on('ratio_bot_configs')
                    ->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ratio_bot_lots');

        Schema::table('ratio_bot_states', function (Blueprint $table) {
            foreach ([
                'review_alerted_at',
                'observed_high_price',
                'observed_low_price',
                'used_sell_allocation_pct',
                'used_buy_allocation_pct',
                'current_stage_index',
                'current_stage_side',
                'range_status',
                'market_regime',
                'strategy_health_status',
            ] as $column) {
                if (Schema::hasColumn('ratio_bot_states', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('ratio_bot_configs', function (Blueprint $table) {
            foreach ([
                'strategy_reserve_pct',
                'stage_sell_rises',
                'stage_sell_allocations',
                'stage_buy_drops',
                'stage_buy_allocations',
            ] as $column) {
                if (Schema::hasColumn('ratio_bot_configs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
