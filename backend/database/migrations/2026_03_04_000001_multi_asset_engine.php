<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fx_rate_snapshots')) {
            Schema::create('fx_rate_snapshots', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('provider')->default('openexchangerates');
                $table->string('base', 8)->index();
                $table->string('quote', 8)->index();
                $table->decimal('rate', 20, 8);
                $table->timestamp('ts');
                $table->timestamps();
                $table->unique(['provider', 'base', 'quote', 'ts'], 'fx_snapshot_unique');
            });
        }

        Schema::table('bots', function (Blueprint $table) {
            if (! Schema::hasColumn('bots', 'asset_class')) {
                $table->string('asset_class', 16)->default('CRYPTO')->after('name');
            }
            if (! Schema::hasColumn('bots', 'metal_type')) {
                $table->string('metal_type', 16)->nullable()->after('asset_class');
            }
            if (! Schema::hasColumn('bots', 'asset_code')) {
                $table->string('asset_code', 32)->nullable()->after('metal_type')->index();
            }
            if (! Schema::hasColumn('bots', 'quote_currency')) {
                $table->string('quote_currency', 8)->default('USD')->after('asset_code');
            }
            if (! Schema::hasColumn('bots', 'provider')) {
                $table->string('provider', 32)->nullable()->after('quote_currency')->index();
            }
        });

        Schema::table('trade_signals', function (Blueprint $table) {
            if (! Schema::hasColumn('trade_signals', 'asset_class')) {
                $table->string('asset_class', 16)->default('CRYPTO')->after('user_id');
            }
            if (! Schema::hasColumn('trade_signals', 'asset_code')) {
                $table->string('asset_code', 32)->nullable()->after('asset_class')->index();
            }
            if (! Schema::hasColumn('trade_signals', 'quote_currency')) {
                $table->string('quote_currency', 8)->default('USD')->after('asset_code');
            }
            if (! Schema::hasColumn('trade_signals', 'provider')) {
                $table->string('provider', 32)->nullable()->after('quote_currency')->index();
            }
        });

        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'asset_class')) {
                $table->string('asset_class', 16)->default('CRYPTO')->after('signal_id');
            }
            if (! Schema::hasColumn('orders', 'asset_code')) {
                $table->string('asset_code', 32)->nullable()->after('asset_class')->index();
            }
            if (! Schema::hasColumn('orders', 'quote_currency')) {
                $table->string('quote_currency', 8)->default('USD')->after('asset_code');
            }
            if (! Schema::hasColumn('orders', 'provider')) {
                $table->string('provider', 32)->nullable()->after('quote_currency')->index();
            }
            if (! Schema::hasColumn('orders', 'spread_pct')) {
                $table->decimal('spread_pct', 10, 4)->nullable()->after('slippage_pct');
            }
            if (! Schema::hasColumn('orders', 'expected_profit_pct')) {
                $table->decimal('expected_profit_pct', 10, 4)->nullable()->after('spread_pct');
            }
        });

        Schema::table('positions', function (Blueprint $table) {
            if (! Schema::hasColumn('positions', 'asset_class')) {
                $table->string('asset_class', 16)->default('CRYPTO')->after('bot_id');
            }
            if (! Schema::hasColumn('positions', 'asset_code')) {
                $table->string('asset_code', 32)->nullable()->after('asset_class')->index();
            }
            if (! Schema::hasColumn('positions', 'quote_currency')) {
                $table->string('quote_currency', 8)->default('USD')->after('asset_code');
            }
            if (! Schema::hasColumn('positions', 'provider')) {
                $table->string('provider', 32)->nullable()->after('quote_currency')->index();
            }
        });

        Schema::table('wallet_accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('wallet_accounts', 'wallet_type')) {
                $table->string('wallet_type', 24)->default('cash')->after('type');
            }
            if (! Schema::hasColumn('wallet_accounts', 'asset_class')) {
                $table->string('asset_class', 16)->nullable()->after('wallet_type')->index();
            }
        });

        Schema::table('trades', function (Blueprint $table) {
            if (! Schema::hasColumn('trades', 'asset_class')) {
                $table->string('asset_class', 16)->default('CRYPTO')->after('bot_id');
            }
            if (! Schema::hasColumn('trades', 'asset_code')) {
                $table->string('asset_code', 32)->nullable()->after('asset_class')->index();
            }
            if (! Schema::hasColumn('trades', 'quote_currency')) {
                $table->string('quote_currency', 8)->default('USD')->after('asset_code');
            }
            if (! Schema::hasColumn('trades', 'provider')) {
                $table->string('provider', 32)->nullable()->after('quote_currency')->index();
            }
            if (! Schema::hasColumn('trades', 'fx_used_usd_lkr')) {
                $table->decimal('fx_used_usd_lkr', 20, 8)->nullable()->after('realized_pnl');
            }
            if (! Schema::hasColumn('trades', 'pnl_lkr')) {
                $table->decimal('pnl_lkr', 20, 8)->nullable()->after('fx_used_usd_lkr');
            }
            if (! Schema::hasColumn('trades', 'spread_pct')) {
                $table->decimal('spread_pct', 10, 4)->nullable()->after('pnl_lkr');
            }
        });
    }

    public function down(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            foreach (['spread_pct', 'pnl_lkr', 'fx_used_usd_lkr', 'provider', 'quote_currency', 'asset_code', 'asset_class'] as $column) {
                if (Schema::hasColumn('trades', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('wallet_accounts', function (Blueprint $table) {
            foreach (['asset_class', 'wallet_type'] as $column) {
                if (Schema::hasColumn('wallet_accounts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('positions', function (Blueprint $table) {
            foreach (['provider', 'quote_currency', 'asset_code', 'asset_class'] as $column) {
                if (Schema::hasColumn('positions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('orders', function (Blueprint $table) {
            foreach (['expected_profit_pct', 'spread_pct', 'provider', 'quote_currency', 'asset_code', 'asset_class'] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('trade_signals', function (Blueprint $table) {
            foreach (['provider', 'quote_currency', 'asset_code', 'asset_class'] as $column) {
                if (Schema::hasColumn('trade_signals', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('bots', function (Blueprint $table) {
            foreach (['provider', 'quote_currency', 'asset_code', 'metal_type', 'asset_class'] as $column) {
                if (Schema::hasColumn('bots', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('fx_rate_snapshots');
    }
};

