<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('quick_trade_manual_positions') || Schema::hasColumn('quick_trade_manual_positions', 'trailing_pct')) return;
        Schema::table('quick_trade_manual_positions', function (Blueprint $table): void {
            $table->decimal('trailing_pct', 8, 4)->nullable()->after('tp_trigger_price');
            $table->decimal('trailing_activation_pct', 8, 4)->nullable()->after('trailing_pct');
            $table->decimal('trailing_activation_price', 24, 10)->nullable()->after('trailing_activation_pct');
            $table->string('trailing_client_algo_id', 48)->nullable()->after('tp_verified_at');
            $table->string('trailing_algo_id', 40)->nullable()->after('trailing_client_algo_id');
            $table->string('trailing_status', 16)->default('NONE')->after('trailing_algo_id');
            $table->timestamp('trailing_verified_at')->nullable()->after('trailing_status');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('quick_trade_manual_positions') || ! Schema::hasColumn('quick_trade_manual_positions', 'trailing_pct')) return;
        Schema::table('quick_trade_manual_positions', fn (Blueprint $table) => $table->dropColumn(['trailing_pct','trailing_activation_pct','trailing_activation_price','trailing_client_algo_id','trailing_algo_id','trailing_status','trailing_verified_at']));
    }
};
