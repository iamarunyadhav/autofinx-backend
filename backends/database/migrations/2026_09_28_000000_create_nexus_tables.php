<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nexus_runs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $t->string('slot', 80);
            $t->string('kind', 30)->default('cycle');
            $t->string('status', 30)->default('queued');
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->json('summary')->nullable();
            $t->string('error_code')->nullable();
            $t->timestamps();
            $t->unique(['workspace_id', 'slot']);
            $t->index(['status', 'created_at']);
        });
        // Typed artifacts share provenance, workspace isolation and retention semantics.
        Schema::create('nexus_entries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 30);
            $t->string('fingerprint', 64);
            $t->string('title');
            $t->string('status', 30);
            $t->string('evidence_level', 1)->nullable();
            $t->json('payload');
            $t->timestamps();
            $t->unique(['workspace_id', 'kind', 'fingerprint'], 'nexus_entry_scope_unique');
            $t->index(['workspace_id', 'kind', 'created_at'], 'nexus_entry_listing');
        });
        Schema::create('nexus_reviews', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('entry_id')->constrained('nexus_entries')->cascadeOnDelete();
            $t->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action', 30);
            $t->text('reason');
            $t->timestamps();
        });
        Schema::create('nexus_model_calls', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $t->string('fingerprint', 64);
            $t->string('model');
            $t->boolean('deep')->default(false);
            $t->string('status', 30);
            $t->decimal('reserved_usd', 14, 6);
            $t->json('result')->nullable();
            $t->timestamps();
            $t->index(['created_at', 'status']);
            $t->index(['workspace_id', 'fingerprint']);
        });
    }

    public function down(): void
    {
        foreach (['nexus_model_calls', 'nexus_reviews', 'nexus_entries', 'nexus_runs'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
