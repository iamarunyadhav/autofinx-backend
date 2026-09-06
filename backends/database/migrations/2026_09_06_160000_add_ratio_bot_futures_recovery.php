<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ratio_bot_configs', function (Blueprint $table): void {
            if (! Schema::hasColumn('ratio_bot_configs', 'futures_recovery_enabled')) {
                $table->boolean('futures_recovery_enabled')->default(false);
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'futures_recovery_topup_pct')) {
                $table->decimal('futures_recovery_topup_pct', 5, 2)->nullable();
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'futures_recovery_timeout_minutes')) {
                $table->integer('futures_recovery_timeout_minutes')->nullable();
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'futures_recovery_min_confidence')) {
                $table->decimal('futures_recovery_min_confidence', 5, 2)->nullable();
            }
        });

        Schema::table('ratio_bot_states', function (Blueprint $table): void {
            if (! Schema::hasColumn('ratio_bot_states', 'futures_position_opened_at')) {
                $table->timestamp('futures_position_opened_at')->nullable();
            }
            if (! Schema::hasColumn('ratio_bot_states', 'futures_recovery_review_at')) {
                $table->timestamp('futures_recovery_review_at')->nullable();
            }
            if (! Schema::hasColumn('ratio_bot_states', 'futures_recovery_used')) {
                $table->boolean('futures_recovery_used')->default(false);
            }
            if (! Schema::hasColumn('ratio_bot_states', 'futures_recovery_topup_notional')) {
                $table->decimal('futures_recovery_topup_notional', 24, 8)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('ratio_bot_configs', function (Blueprint $table): void {
            foreach ([
                'futures_recovery_enabled',
                'futures_recovery_topup_pct',
                'futures_recovery_timeout_minutes',
                'futures_recovery_min_confidence',
            ] as $column) {
                if (Schema::hasColumn('ratio_bot_configs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('ratio_bot_states', function (Blueprint $table): void {
            foreach ([
                'futures_position_opened_at',
                'futures_recovery_review_at',
                'futures_recovery_used',
                'futures_recovery_topup_notional',
            ] as $column) {
                if (Schema::hasColumn('ratio_bot_states', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
