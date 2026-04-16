<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('strategy_instances', function (Blueprint $table) {
            if (!Schema::hasColumn('strategy_instances', 'connection_id')) {
                $table->uuid('connection_id')->nullable()->after('workspace_id');
                $table->foreign('connection_id')->references('id')->on('exchange_connections')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('strategy_instances', function (Blueprint $table) {
            if (Schema::hasColumn('strategy_instances', 'connection_id')) {
                $table->dropForeign(['connection_id']);
                $table->dropColumn('connection_id');
            }
        });
    }
};
