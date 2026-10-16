<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('robot_sessions')) {
            return;
        }

        Schema::table('robot_sessions', function (Blueprint $table): void {
            if (! Schema::hasColumn('robot_sessions', 'execution_mode')) {
                $table->string('execution_mode', 16)->default('MANUAL')->after('mode')->index();
            }
            if (! Schema::hasColumn('robot_sessions', 'strategy_lab_version_id')) {
                $table->uuid('strategy_lab_version_id')->nullable()->after('execution_mode')->index();
            }
            if (! Schema::hasColumn('robot_sessions', 'auto_enabled_at')) {
                $table->dateTime('auto_enabled_at')->nullable()->after('strategy_lab_version_id');
            }
            if (! Schema::hasColumn('robot_sessions', 'auto_disabled_reason')) {
                $table->string('auto_disabled_reason', 255)->nullable()->after('auto_enabled_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('robot_sessions')) {
            return;
        }
        Schema::table('robot_sessions', function (Blueprint $table): void {
            foreach (['auto_disabled_reason', 'auto_enabled_at', 'strategy_lab_version_id', 'execution_mode'] as $column) {
                if (Schema::hasColumn('robot_sessions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
