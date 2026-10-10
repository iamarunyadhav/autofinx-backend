<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit log for Quick Trade Manual SL/TP positions: one row per
 * state transition or notable exchange observation. Never updated or deleted by
 * application code.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('quick_trade_manual_events')) {
            return;
        }

        Schema::create('quick_trade_manual_events', function (Blueprint $table): void {
            $table->id('seq');                       // monotonic order (created_at has only second precision)
            $table->uuid('id')->unique();
            $table->uuid('position_id');
            $table->uuid('workspace_id');
            $table->string('type', 48);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->string('source', 16);                 // api | reconciler | system
            $table->string('severity', 12)->default('info');
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['position_id', 'seq'], 'qtm_events_position_idx');
            $table->index(['workspace_id', 'severity', 'created_at'], 'qtm_events_ws_sev_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quick_trade_manual_events');
    }
};
