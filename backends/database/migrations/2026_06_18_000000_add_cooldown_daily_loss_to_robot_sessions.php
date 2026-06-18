<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('robot_sessions', function (Blueprint $table) {
            // Cooldown between trades (in seconds). After a loss the bot waits
            // this long before opening the next position.
            $table->unsignedInteger('cooldown_seconds')->default(300)->after('take_profit_pct');

            // Max total loss per calendar day (UTC). When hit the bot pauses
            // evaluation for the rest of the day and resets automatically at midnight.
            $table->decimal('daily_loss_limit', 18, 8)->nullable()->after('cooldown_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('robot_sessions', function (Blueprint $table) {
            $table->dropColumn(['cooldown_seconds', 'daily_loss_limit']);
        });
    }
};
