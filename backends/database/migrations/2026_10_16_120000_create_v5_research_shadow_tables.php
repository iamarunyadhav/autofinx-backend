<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('v5_market_observations')) {
            Schema::create('v5_market_observations', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('symbol', 32);
                $table->string('timeframe', 8);
                $table->unsignedBigInteger('candle_open_ms');
                $table->unsignedBigInteger('candle_close_ms');
                $table->decimal('open', 30, 12);
                $table->decimal('high', 30, 12);
                $table->decimal('low', 30, 12);
                $table->decimal('close', 30, 12);
                $table->decimal('volume', 38, 12)->nullable();
                $table->decimal('taker_buy_volume', 38, 12)->nullable();
                $table->decimal('funding_rate', 20, 12)->nullable();
                $table->decimal('open_interest', 38, 12)->nullable();
                $table->string('source', 64);
                $table->string('source_revision', 128)->nullable();
                $table->string('data_hash_sha256', 64)->nullable();
                $table->dateTime('observed_at');
                $table->timestamps();
                $table->unique(['symbol', 'timeframe', 'candle_open_ms']);
                $table->index(['symbol', 'timeframe', 'candle_close_ms']);
            });
        }

        if (! Schema::hasTable('v5_shadow_predictions')) {
            Schema::create('v5_shadow_predictions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('symbol', 32);
                $table->string('horizon', 8);
                $table->unsignedInteger('horizon_minutes');
                $table->dateTime('forecast_at');
                $table->dateTime('outcome_available_at');
                $table->string('direction', 16); // UP, DOWN, SIDEWAYS
                $table->decimal('expected_move', 20, 12)->nullable();
                $table->json('probabilities')->nullable();
                $table->boolean('probability_calibrated')->default(false);
                $table->json('evidence')->nullable();
                $table->string('model_version', 128);
                $table->string('data_status', 32)->default('valid');
                $table->string('model_status', 32)->default('available');
                $table->string('outcome_status', 32)->default('pending');
                $table->string('actual_direction', 16)->nullable();
                $table->decimal('actual_move', 20, 12)->nullable();
                $table->boolean('correct')->nullable();
                $table->dateTime('evaluated_at')->nullable();
                $table->timestamps();
                $table->unique(
                    ['symbol', 'horizon', 'forecast_at', 'model_version'],
                    'v5_shadow_prediction_forecast_unique'
                );
                $table->index(
                    ['outcome_status', 'outcome_available_at'],
                    'v5_shadow_prediction_outcome_index'
                );
            });
        }

        // MySQL DDL is not transactional. A failed CREATE TABLE can leave the
        // table present without indexes while Laravel keeps this migration pending.
        if (! Schema::hasIndex('v5_shadow_predictions', 'v5_shadow_prediction_forecast_unique')) {
            Schema::table('v5_shadow_predictions', function (Blueprint $table) {
                $table->unique(
                    ['symbol', 'horizon', 'forecast_at', 'model_version'],
                    'v5_shadow_prediction_forecast_unique'
                );
            });
        }

        if (! Schema::hasIndex('v5_shadow_predictions', 'v5_shadow_prediction_outcome_index')) {
            Schema::table('v5_shadow_predictions', function (Blueprint $table) {
                $table->index(
                    ['outcome_status', 'outcome_available_at'],
                    'v5_shadow_prediction_outcome_index'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v5_shadow_predictions');
        Schema::dropIfExists('v5_market_observations');
    }
};
