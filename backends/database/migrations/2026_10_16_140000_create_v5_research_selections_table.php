<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v5_research_selections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('workspace_id');
            $table->string('symbol', 32);
            $table->string('side', 20);
            $table->string('status', 20)->default('TRACKING');
            $table->dateTime('selected_at');
            $table->dateTime('expires_at')->nullable();
            $table->decimal('selection_price', 30, 10);
            $table->uuid('prediction_snapshot_id')->nullable();
            $table->unsignedInteger('original_rank')->nullable();
            $table->unsignedInteger('current_rank')->nullable();
            $table->string('horizon', 20)->default('24h');
            $table->text('notes')->nullable();
            $table->json('research_metrics')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->index(['user_id', 'workspace_id', 'status'], 'v5_selection_owner_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v5_research_selections');
    }
};
