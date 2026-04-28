<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ratio_bot_configs', function (Blueprint $table): void {
            if (! Schema::hasColumn('ratio_bot_configs', 'fee_bps')) {
                $table->decimal('fee_bps', 10, 4)->default(0)->after('cooldown_seconds_after_trade');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'max_slippage_bps')) {
                $table->decimal('max_slippage_bps', 10, 4)->default(0)->after('fee_bps');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'min_net_profit_bps')) {
                $table->decimal('min_net_profit_bps', 10, 4)->default(0)->after('max_slippage_bps');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'stale_feed_max_age_seconds')) {
                $table->integer('stale_feed_max_age_seconds')->default(30)->after('min_net_profit_bps');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'min_seconds_between_evaluations')) {
                $table->integer('min_seconds_between_evaluations')->default(1)->after('stale_feed_max_age_seconds');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'use_advisory_filter')) {
                $table->boolean('use_advisory_filter')->default(false)->after('min_seconds_between_evaluations');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'advisory_mode')) {
                $table->string('advisory_mode', 32)->default('off')->after('use_advisory_filter');
            }
        });

        Schema::table('ratio_bot_states', function (Blueprint $table): void {
            if (! Schema::hasColumn('ratio_bot_states', 'last_price_source')) {
                $table->string('last_price_source', 32)->nullable()->after('last_seen_price');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'last_price_timestamp')) {
                $table->timestamp('last_price_timestamp')->nullable()->after('last_price_source');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'awaiting_since')) {
                $table->timestamp('awaiting_since')->nullable()->after('cooldown_until');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'recovery_action_required')) {
                $table->boolean('recovery_action_required')->default(false)->after('awaiting_since');
            }
        });

        Schema::table('ratio_bot_decisions', function (Blueprint $table): void {
            if (! Schema::hasColumn('ratio_bot_decisions', 'price_source')) {
                $table->string('price_source', 32)->nullable()->after('evaluated_price');
            }
            if (! Schema::hasColumn('ratio_bot_decisions', 'price_timestamp')) {
                $table->timestamp('price_timestamp')->nullable()->after('price_source');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ratio_bot_decisions', function (Blueprint $table): void {
            foreach (['price_timestamp', 'price_source'] as $column) {
                if (Schema::hasColumn('ratio_bot_decisions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('ratio_bot_states', function (Blueprint $table): void {
            foreach (['recovery_action_required', 'awaiting_since', 'last_price_timestamp', 'last_price_source'] as $column) {
                if (Schema::hasColumn('ratio_bot_states', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('ratio_bot_configs', function (Blueprint $table): void {
            foreach ([
                'advisory_mode',
                'use_advisory_filter',
                'min_seconds_between_evaluations',
                'stale_feed_max_age_seconds',
                'min_net_profit_bps',
                'max_slippage_bps',
                'fee_bps',
            ] as $column) {
                if (Schema::hasColumn('ratio_bot_configs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
