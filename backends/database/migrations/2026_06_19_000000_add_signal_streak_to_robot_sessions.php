<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('robot_sessions', function (Blueprint $table) {
            $table->string('last_signal_direction', 8)->nullable()->after('last_signal_at');
            $table->integer('signal_streak')->default(0)->after('last_signal_direction');
            $table->timestamp('last_signal_direction_at')->nullable()->after('signal_streak');
        });
    }

    public function down(): void
    {
        Schema::table('robot_sessions', function (Blueprint $table) {
            $table->dropColumn(['last_signal_direction', 'signal_streak', 'last_signal_direction_at']);
        });
    }
};
