<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('integration_id')->index();
            $table->string('asset');
            $table->decimal('free', 24, 10)->default(0);
            $table->decimal('locked', 24, 10)->default(0);
            $table->decimal('total', 24, 10)->default(0);
            $table->timestamp('captured_at')->index();
            $table->timestamps();
            $table->unique(['integration_id','asset','captured_at']);
        });

        Schema::create('reconciliation_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->uuid('integration_id')->nullable()->index();
            $table->string('status')->default('ok');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->json('meta_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_runs');
        Schema::dropIfExists('wallet_snapshots');
    }
};
