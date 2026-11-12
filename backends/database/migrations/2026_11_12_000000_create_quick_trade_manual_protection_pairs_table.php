<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('quick_trade_manual_protection_pairs')) return;
        Schema::create('quick_trade_manual_protection_pairs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('position_id');
            $table->uuid('workspace_id')->index();
            $table->unsignedSmallInteger('level');
            $table->string('tp_mode', 12)->default('percent');
            $table->decimal('tp_value', 24, 10);
            $table->decimal('tp_price', 24, 10)->nullable();
            $table->string('sl_mode', 12)->default('fixed');
            $table->string('sl_input_mode', 12)->default('percent');
            $table->decimal('sl_value', 24, 10);
            $table->decimal('sl_price', 24, 10)->nullable();
            $table->decimal('trailing_distance', 24, 10)->nullable();
            $table->decimal('peak_price', 24, 10)->nullable();
            $table->decimal('trough_price', 24, 10)->nullable();
            $table->decimal('active_stop_price', 24, 10)->nullable();
            $table->string('status', 20)->default('PENDING');
            $table->string('tp_algo_id', 40)->nullable();
            $table->string('tp_client_algo_id', 48)->nullable();
            $table->string('tp_status', 16)->default('NONE');
            $table->string('sl_algo_id', 40)->nullable();
            $table->string('sl_client_algo_id', 48)->nullable();
            $table->string('sl_status', 16)->default('NONE');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('updated_stop_at')->nullable();
            $table->timestamps();
            $table->foreign('position_id')->references('id')->on('quick_trade_manual_positions')->cascadeOnDelete();
            $table->unique(['position_id', 'level'], 'qtm_pairs_position_level_unique');
            $table->index(['position_id', 'status'], 'qtm_pairs_position_status_idx');
        });
    }

    public function down(): void { Schema::dropIfExists('quick_trade_manual_protection_pairs'); }
};
