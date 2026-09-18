<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quick_trade_research_samples', function (Blueprint $table) {
            $table->id();
            $table->uuid('quick_trade_id');
            $table->unsignedBigInteger('quote_time_ms');
            $table->decimal('price', 24, 10);
            $table->string('source', 100);
            $table->timestamp('recorded_at', 3);
            $table->unique(['quick_trade_id', 'quote_time_ms'], 'qt_research_quote_unique');
            $table->foreign('quick_trade_id')->references('id')->on('quick_trades')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quick_trade_research_samples');
    }
};
