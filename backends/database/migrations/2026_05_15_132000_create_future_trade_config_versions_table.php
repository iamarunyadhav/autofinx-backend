<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('future_trade_config_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('future_trade_bot_config_id')->index();
            $table->uuid('workspace_id')->index();
            $table->unsignedInteger('version_number');
            $table->string('label', 255)->nullable();
            $table->string('source', 64)->default('manual_update');
            $table->json('settings_json');
            $table->text('algorithm_notes')->nullable();
            $table->json('summary_json')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->unique(['future_trade_bot_config_id', 'version_number'], 'future_trade_config_versions_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('future_trade_config_versions');
    }
};
