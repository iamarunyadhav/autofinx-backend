<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('schedule_states', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('schedule_id')->unique();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('cooldown_until')->nullable();
            $table->string('hour_key')->nullable();
            $table->string('day_key')->nullable();
            $table->unsignedInteger('hour_count')->default(0);
            $table->unsignedInteger('day_count')->default(0);
            $table->timestamps();

            $table->foreign('schedule_id')->references('id')->on('schedules')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedule_states');
    }
};
