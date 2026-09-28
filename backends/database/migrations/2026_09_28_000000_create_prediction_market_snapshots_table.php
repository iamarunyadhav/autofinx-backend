<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prediction_market_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('exchange', 40);
            $table->string('market_type', 20)->default('crypto');
            $table->string('symbol', 40);
            $table->string('timeframe', 12);
            $table->unsignedBigInteger('ts');
            $table->decimal('bid', 30, 12)->nullable();
            $table->decimal('ask', 30, 12)->nullable();
            $table->decimal('mid', 30, 12)->nullable();
            $table->decimal('last', 30, 12)->nullable();
            $table->decimal('mark', 30, 12)->nullable();
            $table->decimal('spread', 30, 12)->nullable();
            $table->decimal('open', 30, 12)->nullable();
            $table->decimal('high', 30, 12)->nullable();
            $table->decimal('low', 30, 12)->nullable();
            $table->decimal('close', 30, 12)->nullable();
            $table->decimal('volume', 40, 12)->nullable();
            $table->string('source', 80);
            $table->string('feed_sequence', 120)->nullable();
            $table->json('quality_json')->nullable();
            $table->timestampTz('captured_at');
            $table->timestampsTz();

            $table->unique(['exchange', 'market_type', 'symbol', 'timeframe', 'ts'], 'prediction_market_snapshot_point');
            $table->index(['symbol', 'timeframe', 'ts'], 'prediction_market_snapshot_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prediction_market_snapshots');
    }
};
