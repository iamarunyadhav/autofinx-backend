<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->uuid('requested_by')->index();
            $table->string('mode')->default('paper_to_live');
            $table->json('payload_json')->nullable();
            $table->string('status')->default('pending'); // pending|approved|rejected
            $table->timestamps();
        });

        Schema::create('promotion_approvals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('request_id')->index();
            $table->uuid('approved_by')->index();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('totp_verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('optimizer_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->string('strategy')->default('fixed');
            $table->json('inputs_json')->nullable();
            $table->json('outputs_json')->nullable();
            $table->timestamps();
        });

        Schema::create('risk_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->string('type');
            $table->string('severity')->default('info');
            $table->json('reason_json')->nullable();
            $table->timestamp('triggered_at')->index();
            $table->timestamps();
        });

        Schema::create('event_store', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->nullable()->index();
            $table->string('event_type')->index();
            $table->json('payload_json');
            $table->timestamp('occurred_at')->index();
            $table->string('hash')->unique();
            $table->timestamps();
        });

        Schema::create('execution_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id')->index();
            $table->uuid('venue_id')->nullable()->index();
            $table->integer('attempt_no')->default(1);
            $table->json('request_json')->nullable();
            $table->json('response_json')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();
        });

        Schema::create('market_feed_status', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->uuid('symbol_id')->index();
            $table->timestamp('last_tick_at')->nullable();
            $table->decimal('last_price', 20, 8)->nullable();
            $table->string('source')->nullable();
            $table->json('meta_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_feed_status');
        Schema::dropIfExists('execution_attempts');
        Schema::dropIfExists('event_store');
        Schema::dropIfExists('risk_events');
        Schema::dropIfExists('optimizer_runs');
        Schema::dropIfExists('promotion_approvals');
        Schema::dropIfExists('promotion_requests');
    }
};
