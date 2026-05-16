<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('future_trade_bot_configs', function (Blueprint $table) {
            // Drop the existing unique constraint so multiple slots per workspace are allowed
            $table->dropUnique(['workspace_id']);
            $table->unsignedTinyInteger('slot_index')->default(0)->after('workspace_id');
            // Slot 0 is the primary (settings authority); slots 1..N-1 are shadow positions
            $table->unique(['workspace_id', 'slot_index']);
        });
    }

    public function down(): void
    {
        Schema::table('future_trade_bot_configs', function (Blueprint $table) {
            $table->dropUnique(['workspace_id', 'slot_index']);
            $table->dropColumn('slot_index');
            $table->unique(['workspace_id']);
        });
    }
};
