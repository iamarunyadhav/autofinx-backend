<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v5_shadow_predictions', function (Blueprint $table) {
            $table->decimal('reference_price', 30, 12)->nullable()->after('expected_move');
            $table->decimal('outcome_price', 30, 12)->nullable()->after('actual_move');
            $table->dateTime('outcome_observed_at')->nullable()->after('outcome_price');
        });
    }

    public function down(): void
    {
        Schema::table('v5_shadow_predictions', function (Blueprint $table) {
            $table->dropColumn(['reference_price', 'outcome_price', 'outcome_observed_at']);
        });
    }
};
