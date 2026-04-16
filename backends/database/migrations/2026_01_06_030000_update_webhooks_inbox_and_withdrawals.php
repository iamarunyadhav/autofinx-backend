<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhooks_inbox', function (Blueprint $table) {
            if (!Schema::hasColumn('webhooks_inbox', 'status')) {
                $table->string('status')->default('received');
            }
            if (!Schema::hasColumn('webhooks_inbox', 'processed_at')) {
                $table->timestamp('processed_at')->nullable();
            }
            if (!Schema::hasColumn('webhooks_inbox', 'payload')) {
                $table->json('payload')->nullable();
            }
            $table->unique(['source', 'event_id']);
        });

        Schema::create('withdrawal_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('user_id');
            $table->decimal('amount', 18, 4);
            $table->string('currency', 10)->default('USD');
            $table->string('status')->default('pending');
            $table->string('method')->default('bank');
            $table->json('reference')->nullable();
            $table->boolean('requires_2fa')->default(true);
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('webhooks_inbox', function (Blueprint $table) {
            $table->dropUnique(['source', 'event_id']);
            $table->dropColumn(['status', 'processed_at']);
        });

        Schema::dropIfExists('withdrawal_requests');
    }
};
