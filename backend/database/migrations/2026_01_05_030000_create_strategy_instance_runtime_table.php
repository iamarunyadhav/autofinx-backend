<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('strategy_instance_runtime', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('strategy_instance_id')->unique();
            $table->timestamp('last_evaluated_at')->nullable();
            $table->timestamp('last_trade_at')->nullable();
            $table->integer('trades_in_current_hour')->default(0);
            $table->integer('trades_in_current_day')->default(0);
            $table->string('hour_bucket')->nullable();
            $table->string('day_bucket')->nullable();
            $table->timestamps();

            $table->foreign('strategy_instance_id')->references('id')->on('strategy_instances')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strategy_instance_runtime');
    }
};
