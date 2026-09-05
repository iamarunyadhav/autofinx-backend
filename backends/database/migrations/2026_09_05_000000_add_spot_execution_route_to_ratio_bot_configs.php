<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ratio_bot_configs', function (Blueprint $table): void {
            $table->string('spot_execution_route', 32)->default('spot_market')->after('execution_mode');
        });
    }

    public function down(): void
    {
        Schema::table('ratio_bot_configs', function (Blueprint $table): void {
            $table->dropColumn('spot_execution_route');
        });
    }
};
