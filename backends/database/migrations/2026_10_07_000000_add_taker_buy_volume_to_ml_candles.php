<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quick Trade ML shadow — Phase 2. Binance klines already return the taker
 * BUY base volume (field 9); Phase 1 fetched it but did not store it.
 * Additive and nullable: existing rows stay valid and are enriched in place
 * by the next backfill (`ml:candles:backfill --restart`).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ml_candles') && ! Schema::hasColumn('ml_candles', 'taker_buy_volume')) {
            Schema::table('ml_candles', function (Blueprint $table): void {
                $table->decimal('taker_buy_volume', 30, 10)->nullable()->after('volume');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ml_candles') && Schema::hasColumn('ml_candles', 'taker_buy_volume')) {
            Schema::table('ml_candles', function (Blueprint $table): void {
                $table->dropColumn('taker_buy_volume');
            });
        }
    }
};
