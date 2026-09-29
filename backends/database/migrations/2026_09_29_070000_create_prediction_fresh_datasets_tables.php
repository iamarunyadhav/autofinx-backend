<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('prediction_fresh_datasets')) {
            Schema::create('prediction_fresh_datasets', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('name')->unique();
                $table->string('exchange', 40);
                $table->json('symbols');
                $table->json('timeframes');
                $table->dateTime('started_at');
                $table->string('status', 30)->default('COLLECTING');
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['exchange', 'status']);
            });
        }

        if (! Schema::hasTable('prediction_fresh_dataset_candles')) {
            Schema::create('prediction_fresh_dataset_candles', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('dataset_id');
                $table->string('exchange', 40);
                $table->string('symbol', 30);
                $table->string('timeframe', 10);
                $table->dateTime('open_time');
                $table->dateTime('close_time');
                $table->decimal('open', 24, 10);
                $table->decimal('high', 24, 10);
                $table->decimal('low', 24, 10);
                $table->decimal('close', 24, 10);
                $table->decimal('volume', 30, 10)->default(0);
                $table->string('source', 80);
                $table->dateTime('received_at');
                $table->json('quality')->nullable();
                $table->timestamps();
                $table->foreign('dataset_id')->references('id')->on('prediction_fresh_datasets')->cascadeOnDelete();
                $table->unique(['dataset_id', 'symbol', 'timeframe', 'open_time'], 'pred_fresh_candle_unique');
                $table->index(['dataset_id', 'symbol', 'timeframe', 'close_time'], 'pred_fresh_candle_lookup');
            });
        }

        if (! Schema::hasTable('prediction_fresh_model_candidates')) {
            Schema::create('prediction_fresh_model_candidates', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('dataset_id');
                $table->string('symbol', 30);
                $table->string('timeframe', 10);
                $table->string('provider', 40)->default('lightgbm');
                $table->string('status', 30);
                $table->string('artifact_path');
                $table->string('artifact_sha256', 64);
                $table->json('metrics');
                $table->timestamps();
                $table->foreign('dataset_id')->references('id')->on('prediction_fresh_datasets')->cascadeOnDelete();
                $table->unique(['dataset_id', 'symbol', 'timeframe', 'provider'], 'pred_fresh_candidate_unique');
                $table->index(['symbol', 'timeframe', 'status'], 'pred_fresh_candidate_lookup');
            });
        }

        if (! Schema::hasTable('prediction_fresh_model_registry')) {
            Schema::create('prediction_fresh_model_registry', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('candidate_id');
                $table->string('symbol', 30);
                $table->string('timeframe', 10);
                $table->string('provider', 40);
                $table->string('status', 30)->default('SHADOW_ONLY');
                $table->boolean('execution')->default(false);
                $table->boolean('influence')->default(false);
                $table->timestamps();
                $table->foreign('candidate_id')->references('id')->on('prediction_fresh_model_candidates')->cascadeOnDelete();
                $table->unique(['candidate_id'], 'pred_fresh_registry_candidate_unique');
                $table->unique(['symbol', 'timeframe', 'provider'], 'pred_fresh_registry_scope_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('prediction_fresh_model_registry');
        Schema::dropIfExists('prediction_fresh_model_candidates');
        Schema::dropIfExists('prediction_fresh_dataset_candles');
        Schema::dropIfExists('prediction_fresh_datasets');
    }
};
