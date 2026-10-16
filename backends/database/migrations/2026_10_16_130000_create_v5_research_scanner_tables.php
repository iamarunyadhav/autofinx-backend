<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v5_research_scanner_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('run_key', 128)->unique();
            $table->dateTime('as_of');
            $table->string('source_mode', 32);
            $table->string('status', 32);
            $table->unsignedSmallInteger('requested_limit');
            $table->unsignedInteger('eligible_count')->default(0);
            $table->json('source_status');
            $table->timestamps();
        });
        Schema::create('v5_research_scanner_candidates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('scanner_run_id');
            $table->string('symbol', 32);
            $table->unsignedSmallInteger('rank');
            $table->decimal('ranking_score', 24, 8);
            $table->string('direction', 16)->nullable();
            $table->json('evidence');
            $table->boolean('execution_authorized')->default(false);
            $table->timestamps();
            $table->unique(['scanner_run_id', 'symbol']);
            $table->foreign('scanner_run_id')->references('id')->on('v5_research_scanner_runs')->cascadeOnDelete();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('v5_research_scanner_candidates');
        Schema::dropIfExists('v5_research_scanner_runs');
    }
};
