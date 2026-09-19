<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backtest_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('bot_type', 32)->index();
            $table->string('exchange', 64);
            $table->string('symbol', 32);
            $table->string('timeframe', 16);
            $table->unsignedInteger('horizon_seconds');
            $table->unsignedInteger('candle_limit')->default(500);
            $table->string('status', 24)->index();
            $table->unsignedInteger('candles_written')->default(0);
            $table->unsignedInteger('result_rows')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['bot_type', 'symbol', 'timeframe', 'horizon_seconds']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backtest_runs');
    }
};
