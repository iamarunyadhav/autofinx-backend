<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prediction_observations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('symbol', 32)->index();
            $table->string('market_type', 16)->default('crypto');
            $table->string('timeframe', 16)->index();
            $table->unsignedInteger('horizon_seconds');
            $table->string('provider', 64)->index();
            $table->string('provider_version', 64)->nullable();
            $table->string('predicted_direction', 16)->nullable();
            $table->decimal('confidence', 8, 4)->nullable();
            $table->decimal('entry_price', 24, 12)->nullable();
            $table->decimal('expiry_price', 24, 12)->nullable();
            $table->decimal('return_pct', 16, 8)->nullable();
            $table->boolean('direction_correct')->nullable();
            $table->string('status', 24)->default('PENDING')->index();
            $table->timestamp('predicted_at')->index();
            $table->timestamp('evaluated_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['symbol', 'provider', 'timeframe', 'predicted_at'], 'pred_obs_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prediction_observations');
    }
};
