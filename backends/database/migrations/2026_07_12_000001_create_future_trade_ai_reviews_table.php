<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('future_trade_ai_reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->date('review_date');
            $table->json('stats_json')->nullable();
            $table->json('report_json')->nullable();
            $table->string('ai_model', 64)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->decimal('cost_usd', 12, 6)->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'review_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('future_trade_ai_reviews');
    }
};
