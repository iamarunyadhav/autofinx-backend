<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ratio_bot_configs', function (Blueprint $table): void {
            if (! Schema::hasColumn('ratio_bot_configs', 'wallet_fallback_enabled')) {
                $table->boolean('wallet_fallback_enabled')->default(false)->after('seed_position_pct');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'wallet_fallback_min_cooldown_minutes')) {
                $table->integer('wallet_fallback_min_cooldown_minutes')->nullable()->after('wallet_fallback_enabled');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'wallet_fallback_base_observation_minutes')) {
                $table->integer('wallet_fallback_base_observation_minutes')->nullable()->after('wallet_fallback_min_cooldown_minutes');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'wallet_fallback_min_observation_minutes')) {
                $table->integer('wallet_fallback_min_observation_minutes')->nullable()->after('wallet_fallback_base_observation_minutes');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'wallet_fallback_max_observation_minutes')) {
                $table->integer('wallet_fallback_max_observation_minutes')->nullable()->after('wallet_fallback_min_observation_minutes');
            }
        });

        Schema::table('ratio_bot_states', function (Blueprint $table): void {
            if (! Schema::hasColumn('ratio_bot_states', 'wallet_fallback_streak_started_at')) {
                $table->timestamp('wallet_fallback_streak_started_at')->nullable()->after('seed_position_executed_at');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'wallet_fallback_streak_ticks')) {
                $table->integer('wallet_fallback_streak_ticks')->default(0)->after('wallet_fallback_streak_started_at');
            }
            // Same type/precision as the pre-existing
            // recovery_trend_composite_at_last_review column -- deliberately a
            // SEPARATE column, not a reuse of that one: the recovery-monitoring
            // feature and this wallet-aware fallback each track their own
            // velocity independently, so sharing a field would let one
            // feature's review cadence clobber the other's on the same bot.
            if (! Schema::hasColumn('ratio_bot_states', 'wallet_fallback_trend_composite_at_last_review')) {
                $table->decimal('wallet_fallback_trend_composite_at_last_review', 8, 4)->nullable()->after('wallet_fallback_streak_ticks');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'last_wallet_fallback_flip_at')) {
                $table->timestamp('last_wallet_fallback_flip_at')->nullable()->after('wallet_fallback_trend_composite_at_last_review');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ratio_bot_states', function (Blueprint $table): void {
            foreach ([
                'last_wallet_fallback_flip_at',
                'wallet_fallback_trend_composite_at_last_review',
                'wallet_fallback_streak_ticks',
                'wallet_fallback_streak_started_at',
            ] as $column) {
                if (Schema::hasColumn('ratio_bot_states', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('ratio_bot_configs', function (Blueprint $table): void {
            foreach ([
                'wallet_fallback_max_observation_minutes',
                'wallet_fallback_min_observation_minutes',
                'wallet_fallback_base_observation_minutes',
                'wallet_fallback_min_cooldown_minutes',
                'wallet_fallback_enabled',
            ] as $column) {
                if (Schema::hasColumn('ratio_bot_configs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
