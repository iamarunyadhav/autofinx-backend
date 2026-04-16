<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venues', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->unique();
            $table->string('type')->default('exchange'); // exchange|broker|defi
            $table->boolean('enabled')->default(true);
            $table->json('meta_json')->nullable();
            $table->timestamps();
        });

        Schema::create('venue_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->uuid('venue_id')->index();
            $table->string('label')->nullable();
            $table->text('api_key_encrypted')->nullable();
            $table->text('api_secret_encrypted')->nullable();
            $table->text('passphrase_encrypted')->nullable();
            $table->boolean('sandbox_mode')->default(true);
            $table->boolean('enabled')->default(false);
            $table->timestamp('last_tested_at')->nullable();
            $table->json('status_json')->nullable();
            $table->timestamps();

            $table->foreign('venue_id')->references('id')->on('venues')->cascadeOnDelete();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->unique(['workspace_id','venue_id']);
        });

        Schema::create('order_routes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->uuid('order_id')->index();
            $table->uuid('chosen_venue_id')->index();
            $table->json('reason_json')->nullable();
            $table->json('quote_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_routes');
        Schema::dropIfExists('venue_connections');
        Schema::dropIfExists('venues');
    }
};
