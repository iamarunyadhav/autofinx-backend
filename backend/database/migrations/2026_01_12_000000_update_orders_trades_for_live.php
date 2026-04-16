<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'integration_id')) {
                $table->uuid('integration_id')->nullable()->after('workspace_id')->index();
            }
            if (!Schema::hasColumn('orders', 'exchange_order_id')) {
                $table->string('exchange_order_id')->nullable()->after('integration_id')->index();
            }
            if (!Schema::hasColumn('orders', 'requested_qty')) {
                $table->decimal('requested_qty', 20, 8)->nullable()->after('quantity');
            }
            if (!Schema::hasColumn('orders', 'filled_qty')) {
                $table->decimal('filled_qty', 20, 8)->default(0)->after('requested_qty');
            }
            if (!Schema::hasColumn('orders', 'avg_fill_price')) {
                $table->decimal('avg_fill_price', 20, 8)->nullable()->after('price');
            }
            if (!Schema::hasColumn('orders', 'fee_amount')) {
                $table->decimal('fee_amount', 20, 8)->nullable()->after('avg_fill_price');
            }
            if (!Schema::hasColumn('orders', 'fee_asset')) {
                $table->string('fee_asset')->nullable()->after('fee_amount');
            }
            if (!Schema::hasColumn('orders', 'raw_json')) {
                $table->json('raw_json')->nullable()->after('meta');
            }
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['integration_id', 'exchange_order_id']);
        });

        Schema::table('trades', function (Blueprint $table) {
            if (!Schema::hasColumn('trades', 'integration_id')) {
                $table->uuid('integration_id')->nullable()->after('workspace_id')->index();
            }
            if (!Schema::hasColumn('trades', 'exchange_trade_id')) {
                $table->string('exchange_trade_id')->nullable()->after('integration_id')->index();
            }
            if (!Schema::hasColumn('trades', 'symbol')) {
                $table->string('symbol')->nullable()->after('exchange_trade_id');
            }
            if (!Schema::hasColumn('trades', 'side')) {
                $table->string('side')->nullable()->after('symbol');
            }
            if (!Schema::hasColumn('trades', 'quantity')) {
                $table->decimal('quantity', 20, 8)->nullable()->after('side');
            }
            if (!Schema::hasColumn('trades', 'fee_amount')) {
                $table->decimal('fee_amount', 20, 8)->nullable()->after('quantity');
            }
            if (!Schema::hasColumn('trades', 'fee_asset')) {
                $table->string('fee_asset')->nullable()->after('fee_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['integration_id', 'exchange_order_id']);
            $table->dropColumn(['integration_id','exchange_order_id','requested_qty','filled_qty','avg_fill_price','fee_amount','fee_asset','raw_json']);
        });
        Schema::table('trades', function (Blueprint $table) {
            $table->dropIndex(['integration_id', 'exchange_trade_id']);
            $table->dropColumn(['integration_id','exchange_trade_id','symbol','side','quantity','fee_amount','fee_asset']);
        });
    }
};
