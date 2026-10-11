<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_webhook_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->string('scope', 32);
            $table->string('token_hash');
            $table->string('token_hint', 12)->nullable();
            $table->dateTime('rotated_at');
            $table->uuid('rotated_by')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['workspace_id', 'scope'], 'workspace_webhook_tokens_ws_scope_uq');
        });

        Schema::create('strategy_lab_shadow_signals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->string('strategy_id', 120)->index();
            $table->string('symbol', 32)->index();
            $table->string('timeframe', 8)->index();
            $table->string('signal', 24)->index();
            $table->decimal('entry', 24, 10)->nullable();
            $table->decimal('sl', 24, 10)->nullable();
            $table->decimal('tp1', 24, 10)->nullable();
            $table->decimal('tp2', 24, 10)->nullable();
            $table->unsignedBigInteger('time_close_ms');
            $table->unsignedInteger('latency_ms');
            $table->string('strategy_order_id', 191)->nullable();
            $table->json('payload');
            $table->string('evaluation_status', 24)->default('PENDING')->index();
            $table->decimal('gross_return_pct', 16, 8)->nullable();
            $table->decimal('fees_pct', 16, 8)->nullable();
            $table->decimal('funding_pct', 16, 8)->nullable();
            $table->decimal('net_return_pct', 16, 8)->nullable();
            $table->string('exit_reason', 32)->nullable();
            $table->dateTime('evaluated_at')->nullable();
            $table->dateTime('received_at');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['workspace_id', 'strategy_id', 'strategy_order_id'], 'strategy_shadow_signal_idem_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strategy_lab_shadow_signals');
        Schema::dropIfExists('workspace_webhook_tokens');
    }
};
