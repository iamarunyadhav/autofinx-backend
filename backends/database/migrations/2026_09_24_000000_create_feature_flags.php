<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generic, reusable on/off switch store for AI/automation capabilities
 * (Unified Forecast Engine shadow/live modes, per-bot-type scheduled
 * backtest/calibration jobs, the forecast readiness sweep) that today are
 * controlled only by static .env/config() values requiring a code deploy
 * to change. See App\Domain\Ops\FeatureFlagService.
 *
 * `default_value` mirrors the CURRENT real config default for each key at
 * the time this table was introduced, so a missing row changes nothing --
 * behavior is identical to before this table existed until a human
 * explicitly edits a row via the admin UI/API.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_flags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 191)->unique();
            $table->boolean('enabled')->default(false);
            $table->string('category', 64)->index();
            $table->string('label', 191);
            $table->text('description')->nullable();
            $table->boolean('default_value')->default(false);
            $table->timestamp('updated_at')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_flags');
    }
};
