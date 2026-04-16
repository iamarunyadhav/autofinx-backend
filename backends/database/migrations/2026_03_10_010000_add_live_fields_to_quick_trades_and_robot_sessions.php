<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('robot_sessions')) {
            Schema::table('robot_sessions', function (Blueprint $table) {
                if (! Schema::hasColumn('robot_sessions', 'provider_key')) {
                    $table->string('provider_key', 32)->nullable()->after('mode')->index();
                }
                if (! Schema::hasColumn('robot_sessions', 'integration_id')) {
                    $table->uuid('integration_id')->nullable()->after('provider_key')->index();
                }
            });
        }

        if (Schema::hasTable('quick_trades')) {
            Schema::table('quick_trades', function (Blueprint $table) {
                if (! Schema::hasColumn('quick_trades', 'provider_key')) {
                    $table->string('provider_key', 32)->nullable()->after('source')->index();
                }
                if (! Schema::hasColumn('quick_trades', 'integration_id')) {
                    $table->uuid('integration_id')->nullable()->after('provider_key')->index();
                }
                if (! Schema::hasColumn('quick_trades', 'close_order_id')) {
                    $table->uuid('close_order_id')->nullable()->after('order_id')->index();
                }
                if (! Schema::hasColumn('quick_trades', 'entry_trade_id')) {
                    $table->uuid('entry_trade_id')->nullable()->after('close_order_id')->index();
                }
                if (! Schema::hasColumn('quick_trades', 'close_trade_id')) {
                    $table->uuid('close_trade_id')->nullable()->after('entry_trade_id')->index();
                }
                if (! Schema::hasColumn('quick_trades', 'quantity')) {
                    $table->decimal('quantity', 20, 8)->nullable()->after('stake_amount');
                }
                if (! Schema::hasColumn('quick_trades', 'quote_currency')) {
                    $table->string('quote_currency', 10)->default('USD')->after('quantity');
                }
                if (! Schema::hasColumn('quick_trades', 'fees_total')) {
                    $table->decimal('fees_total', 20, 8)->default(0)->after('payout_amount');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('quick_trades')) {
            Schema::table('quick_trades', function (Blueprint $table) {
                foreach (['fees_total', 'quote_currency', 'quantity', 'close_trade_id', 'entry_trade_id', 'close_order_id', 'integration_id', 'provider_key'] as $column) {
                    if (Schema::hasColumn('quick_trades', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('robot_sessions')) {
            Schema::table('robot_sessions', function (Blueprint $table) {
                foreach (['integration_id', 'provider_key'] as $column) {
                    if (Schema::hasColumn('robot_sessions', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
