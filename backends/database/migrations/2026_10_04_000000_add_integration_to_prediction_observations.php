<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prediction_observations', function (Blueprint $table): void {
            $table->uuid('integration_id')->nullable()->after('correlation_id')->index();
            $table->foreign('integration_id')->references('id')->on('integrations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('prediction_observations', function (Blueprint $table): void {
            $table->dropForeign(['integration_id']);
            $table->dropColumn('integration_id');
        });
    }
};
