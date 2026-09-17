<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ratio_bot_execution_cost_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('ratio_bot_config_id')->index();
            $table->string('source_key', 100);
            $table->json('payload');
            $table->timestamps();
            $table->unique(['ratio_bot_config_id', 'source_key'], 'rb_cost_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ratio_bot_execution_cost_records');
    }
};
