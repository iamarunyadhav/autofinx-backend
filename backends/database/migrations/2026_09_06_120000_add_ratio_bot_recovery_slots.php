<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ratio_bot_configs', function (Blueprint $table): void {
            if (! Schema::hasColumn('ratio_bot_configs', 'recovery_slot_allocation_pct')) {
                $table->decimal('recovery_slot_allocation_pct', 5, 2)->nullable()->after('recovery_enabled');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'recovery_slot_target_pct')) {
                $table->decimal('recovery_slot_target_pct', 5, 2)->nullable()->after('recovery_slot_allocation_pct');
            }
            if (! Schema::hasColumn('ratio_bot_configs', 'recovery_slot_enabled')) {
                $table->boolean('recovery_slot_enabled')->default(false)->after('recovery_slot_target_pct');
            }
        });

        Schema::create('ratio_bot_recovery_slots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->uuid('ratio_bot_config_id')->unique()->index();
            $table->string('symbol')->index();
            $table->decimal('quote_balance', 24, 8)->default(0);
            $table->decimal('base_balance', 24, 8)->default(0);
            $table->decimal('target_cap_quote_equiv', 24, 8)->nullable();
            $table->timestamp('last_topped_up_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->foreign('ratio_bot_config_id')
                ->references('id')
                ->on('ratio_bot_configs')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ratio_bot_recovery_slots');

        Schema::table('ratio_bot_configs', function (Blueprint $table): void {
            foreach (['recovery_slot_enabled', 'recovery_slot_target_pct', 'recovery_slot_allocation_pct'] as $column) {
                if (Schema::hasColumn('ratio_bot_configs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
