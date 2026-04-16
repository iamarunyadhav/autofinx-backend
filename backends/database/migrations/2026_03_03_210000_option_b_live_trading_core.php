<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('bots')) {
            Schema::create('bots', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id')->nullable()->index();
                $table->uuid('user_id')->nullable()->index();
                $table->uuid('connection_id')->nullable()->index();
                $table->string('name');
                $table->string('exchange')->default('binance');
                $table->string('symbol');
                $table->unsignedInteger('interval_sec')->default(60);
                $table->boolean('is_live')->default(false);
                $table->string('status')->default('STOPPED');
                $table->uuid('risk_profile_id')->nullable()->index();
                $table->string('strategy_type')->default('threshold');
                $table->json('strategy_params')->nullable();
                $table->timestamp('last_evaluated_at')->nullable();
                $table->timestamp('last_started_at')->nullable();
                $table->timestamp('cooldown_until')->nullable();
                $table->timestamps();

                $table->foreign('workspace_id')->references('id')->on('workspaces')->nullOnDelete();
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
                $table->foreign('connection_id')->references('id')->on('exchange_connections')->nullOnDelete();
                $table->foreign('risk_profile_id')->references('id')->on('risk_profiles')->nullOnDelete();
                $table->index(['workspace_id', 'status']);
            });
        }

        if (!Schema::hasTable('trade_signals')) {
            Schema::create('trade_signals', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('bot_id')->index();
                $table->uuid('workspace_id')->nullable()->index();
                $table->uuid('user_id')->nullable()->index();
                $table->string('symbol')->index();
                $table->string('side');
                $table->decimal('decision_price', 20, 8);
                $table->timestamp('decision_ts');
                $table->decimal('max_slippage_pct', 10, 4)->default(0.5);
                $table->unsignedInteger('ttl_ms')->default(8000);
                $table->unsignedInteger('fill_timeout_ms')->default(3000);
                $table->string('exec_policy')->default('SAFE');
                $table->string('status')->default('PENDING');
                $table->string('trace_id')->index();
                $table->json('meta_json')->nullable();
                $table->timestamp('executed_at')->nullable();
                $table->timestamps();

                $table->foreign('bot_id')->references('id')->on('bots')->cascadeOnDelete();
                $table->foreign('workspace_id')->references('id')->on('workspaces')->nullOnDelete();
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
                $table->index(['bot_id', 'status']);
            });
        }

        if (!Schema::hasTable('order_fills')) {
            Schema::create('order_fills', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('order_id')->index();
                $table->string('exchange_trade_id')->nullable()->index();
                $table->decimal('fill_price', 20, 8);
                $table->decimal('fill_qty', 20, 8);
                $table->decimal('fee', 20, 8)->default(0);
                $table->string('fee_asset', 16)->nullable();
                $table->timestamp('fill_ts');
                $table->json('raw_json')->nullable();
                $table->string('trace_id')->nullable()->index();
                $table->timestamps();

                $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
                $table->unique(['order_id', 'exchange_trade_id'], 'order_fills_order_trade_unique');
            });
        }

        if (!Schema::hasTable('bot_logs')) {
            Schema::create('bot_logs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('bot_id')->index();
                $table->uuid('workspace_id')->nullable()->index();
                $table->string('level', 16)->default('INFO');
                $table->text('message');
                $table->json('meta_json')->nullable();
                $table->timestamp('ts')->useCurrent();
                $table->string('trace_id')->nullable()->index();
                $table->timestamps();

                $table->foreign('bot_id')->references('id')->on('bots')->cascadeOnDelete();
                $table->foreign('workspace_id')->references('id')->on('workspaces')->nullOnDelete();
                $table->index(['bot_id', 'ts']);
            });
        }

        if (!Schema::hasTable('market_ohlc')) {
            Schema::create('market_ohlc', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('exchange')->default('binance');
                $table->string('symbol')->index();
                $table->string('timeframe')->default('1m');
                $table->decimal('open', 20, 8);
                $table->decimal('high', 20, 8);
                $table->decimal('low', 20, 8);
                $table->decimal('close', 20, 8);
                $table->decimal('volume', 24, 8)->default(0);
                $table->timestamp('ts');
                $table->timestamps();

                $table->unique(['exchange', 'symbol', 'timeframe', 'ts'], 'market_ohlc_unique_point');
            });
        }

        if (!Schema::hasTable('market_prices_cache')) {
            Schema::create('market_prices_cache', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('exchange')->default('binance');
                $table->string('symbol')->index();
                $table->decimal('last_price', 20, 8);
                $table->timestamp('last_ts');
                $table->timestamps();

                $table->unique(['exchange', 'symbol'], 'market_price_cache_exchange_symbol_unique');
            });
        }

        if (!Schema::hasTable('wallet_ledger_entries')) {
            Schema::create('wallet_ledger_entries', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('account_id')->index();
                $table->string('type', 32);
                $table->decimal('amount', 20, 8);
                $table->string('reference_id')->nullable()->index();
                $table->json('meta_json')->nullable();
                $table->timestamp('ts')->useCurrent();
                $table->string('trace_id')->nullable()->index();
                $table->timestamps();

                $table->foreign('account_id')->references('id')->on('wallet_accounts')->cascadeOnDelete();
                $table->index(['account_id', 'ts']);
            });
        }

        Schema::table('exchange_connections', function (Blueprint $table) {
            if (!Schema::hasColumn('exchange_connections', 'user_id')) {
                $table->uuid('user_id')->nullable()->after('workspace_id')->index();
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('exchange_connections', 'exchange')) {
                $table->string('exchange')->nullable()->after('provider');
            }
            if (!Schema::hasColumn('exchange_connections', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('is_enabled');
            }
        });

        DB::table('exchange_connections')->whereNull('exchange')->update(['exchange' => DB::raw('provider')]);
        if (Schema::hasColumn('exchange_connections', 'is_enabled')) {
            DB::table('exchange_connections')->update(['is_active' => DB::raw('is_enabled')]);
        }

        Schema::table('wallet_accounts', function (Blueprint $table) {
            if (!Schema::hasColumn('wallet_accounts', 'user_id')) {
                $table->uuid('user_id')->nullable()->after('workspace_id')->index();
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('wallet_accounts', 'locked_balance')) {
                $table->decimal('locked_balance', 20, 8)->default(0)->after('balance');
            }
        });

        Schema::table('risk_profiles', function (Blueprint $table) {
            if (!Schema::hasColumn('risk_profiles', 'max_daily_loss')) {
                $table->decimal('max_daily_loss', 20, 8)->nullable()->after('name');
            }
            if (!Schema::hasColumn('risk_profiles', 'max_drawdown_pct')) {
                $table->decimal('max_drawdown_pct', 10, 4)->nullable()->after('max_daily_loss');
            }
            if (!Schema::hasColumn('risk_profiles', 'max_open_positions')) {
                $table->unsignedInteger('max_open_positions')->nullable()->after('max_drawdown_pct');
            }
            if (!Schema::hasColumn('risk_profiles', 'max_slippage_pct_default')) {
                $table->decimal('max_slippage_pct_default', 10, 4)->nullable()->after('max_open_positions');
            }
            if (!Schema::hasColumn('risk_profiles', 'circuit_breaker_vol_threshold')) {
                $table->decimal('circuit_breaker_vol_threshold', 10, 4)->nullable()->after('max_slippage_pct_default');
            }
            if (!Schema::hasColumn('risk_profiles', 'cooldown_sec_after_stop')) {
                $table->unsignedInteger('cooldown_sec_after_stop')->nullable()->after('circuit_breaker_vol_threshold');
            }
        });

        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'bot_id')) {
                $table->uuid('bot_id')->nullable()->after('workspace_id')->index();
                $table->foreign('bot_id')->references('id')->on('bots')->nullOnDelete();
            }
            if (!Schema::hasColumn('orders', 'signal_id')) {
                $table->uuid('signal_id')->nullable()->after('bot_id')->index();
                $table->foreign('signal_id')->references('id')->on('trade_signals')->nullOnDelete();
            }
            if (!Schema::hasColumn('orders', 'trace_id')) {
                $table->string('trace_id')->nullable()->after('idempotency_key')->index();
            }
            if (!Schema::hasColumn('orders', 'exchange')) {
                $table->string('exchange')->nullable()->after('integration_id');
            }
            if (!Schema::hasColumn('orders', 'qty')) {
                $table->decimal('qty', 20, 8)->nullable()->after('quantity');
            }
            if (!Schema::hasColumn('orders', 'limit_price')) {
                $table->decimal('limit_price', 20, 8)->nullable()->after('price');
            }
            if (!Schema::hasColumn('orders', 'tif')) {
                $table->string('tif', 8)->nullable()->after('type');
            }
            if (!Schema::hasColumn('orders', 'placed_ts')) {
                $table->timestamp('placed_ts')->nullable()->after('status');
            }
            if (!Schema::hasColumn('orders', 'filled_ts')) {
                $table->timestamp('filled_ts')->nullable()->after('placed_ts');
            }
            if (!Schema::hasColumn('orders', 'slippage_pct')) {
                $table->decimal('slippage_pct', 10, 4)->nullable()->after('filled_ts');
            }
            if (!Schema::hasColumn('orders', 'latency_ms')) {
                $table->unsignedInteger('latency_ms')->nullable()->after('slippage_pct');
            }
        });

        Schema::table('trades', function (Blueprint $table) {
            if (!Schema::hasColumn('trades', 'bot_id')) {
                $table->uuid('bot_id')->nullable()->after('workspace_id')->index();
                $table->foreign('bot_id')->references('id')->on('bots')->nullOnDelete();
            }
            if (!Schema::hasColumn('trades', 'qty')) {
                $table->decimal('qty', 20, 8)->nullable()->after('quantity');
            }
            if (!Schema::hasColumn('trades', 'avg_price')) {
                $table->decimal('avg_price', 20, 8)->nullable()->after('price');
            }
            if (!Schema::hasColumn('trades', 'fees')) {
                $table->decimal('fees', 20, 8)->default(0)->after('fee');
            }
            if (!Schema::hasColumn('trades', 'realized_pnl')) {
                $table->decimal('realized_pnl', 20, 8)->default(0)->after('fees');
            }
            if (!Schema::hasColumn('trades', 'trace_id')) {
                $table->string('trace_id')->nullable()->after('realized_pnl')->index();
            }
            if (!Schema::hasColumn('trades', 'ts')) {
                $table->timestamp('ts')->nullable()->after('executed_at');
            }
        });

        Schema::table('positions', function (Blueprint $table) {
            if (!Schema::hasColumn('positions', 'bot_id')) {
                $table->uuid('bot_id')->nullable()->after('workspace_id')->index();
                $table->foreign('bot_id')->references('id')->on('bots')->nullOnDelete();
            }
            if (!Schema::hasColumn('positions', 'qty')) {
                $table->decimal('qty', 20, 8)->nullable()->after('quantity');
            }
            if (!Schema::hasColumn('positions', 'avg_entry')) {
                $table->decimal('avg_entry', 20, 8)->nullable()->after('avg_price');
            }
            if (!Schema::hasColumn('positions', 'unrealized_pnl')) {
                $table->decimal('unrealized_pnl', 20, 8)->nullable()->after('unrealized_pl');
            }
            if (!Schema::hasColumn('positions', 'realized_pnl')) {
                $table->decimal('realized_pnl', 20, 8)->default(0)->after('unrealized_pnl');
            }
            if (!Schema::hasColumn('positions', 'last_mark_price')) {
                $table->decimal('last_mark_price', 20, 8)->nullable()->after('realized_pnl');
            }
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            if (Schema::hasColumn('positions', 'last_mark_price')) {
                $table->dropColumn('last_mark_price');
            }
            if (Schema::hasColumn('positions', 'realized_pnl')) {
                $table->dropColumn('realized_pnl');
            }
            if (Schema::hasColumn('positions', 'unrealized_pnl')) {
                $table->dropColumn('unrealized_pnl');
            }
            if (Schema::hasColumn('positions', 'avg_entry')) {
                $table->dropColumn('avg_entry');
            }
            if (Schema::hasColumn('positions', 'qty')) {
                $table->dropColumn('qty');
            }
            if (Schema::hasColumn('positions', 'bot_id')) {
                $table->dropConstrainedForeignId('bot_id');
            }
        });

        Schema::table('trades', function (Blueprint $table) {
            if (Schema::hasColumn('trades', 'ts')) {
                $table->dropColumn('ts');
            }
            if (Schema::hasColumn('trades', 'trace_id')) {
                $table->dropColumn('trace_id');
            }
            if (Schema::hasColumn('trades', 'realized_pnl')) {
                $table->dropColumn('realized_pnl');
            }
            if (Schema::hasColumn('trades', 'fees')) {
                $table->dropColumn('fees');
            }
            if (Schema::hasColumn('trades', 'avg_price')) {
                $table->dropColumn('avg_price');
            }
            if (Schema::hasColumn('trades', 'qty')) {
                $table->dropColumn('qty');
            }
            if (Schema::hasColumn('trades', 'bot_id')) {
                $table->dropConstrainedForeignId('bot_id');
            }
        });

        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'latency_ms')) {
                $table->dropColumn('latency_ms');
            }
            if (Schema::hasColumn('orders', 'slippage_pct')) {
                $table->dropColumn('slippage_pct');
            }
            if (Schema::hasColumn('orders', 'filled_ts')) {
                $table->dropColumn('filled_ts');
            }
            if (Schema::hasColumn('orders', 'placed_ts')) {
                $table->dropColumn('placed_ts');
            }
            if (Schema::hasColumn('orders', 'tif')) {
                $table->dropColumn('tif');
            }
            if (Schema::hasColumn('orders', 'limit_price')) {
                $table->dropColumn('limit_price');
            }
            if (Schema::hasColumn('orders', 'qty')) {
                $table->dropColumn('qty');
            }
            if (Schema::hasColumn('orders', 'exchange')) {
                $table->dropColumn('exchange');
            }
            if (Schema::hasColumn('orders', 'trace_id')) {
                $table->dropColumn('trace_id');
            }
            if (Schema::hasColumn('orders', 'signal_id')) {
                $table->dropConstrainedForeignId('signal_id');
            }
            if (Schema::hasColumn('orders', 'bot_id')) {
                $table->dropConstrainedForeignId('bot_id');
            }
        });

        Schema::table('risk_profiles', function (Blueprint $table) {
            $columns = [
                'cooldown_sec_after_stop',
                'circuit_breaker_vol_threshold',
                'max_slippage_pct_default',
                'max_open_positions',
                'max_drawdown_pct',
                'max_daily_loss',
            ];
            foreach ($columns as $column) {
                if (Schema::hasColumn('risk_profiles', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('wallet_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('wallet_accounts', 'locked_balance')) {
                $table->dropColumn('locked_balance');
            }
            if (Schema::hasColumn('wallet_accounts', 'user_id')) {
                $table->dropConstrainedForeignId('user_id');
            }
        });

        Schema::table('exchange_connections', function (Blueprint $table) {
            if (Schema::hasColumn('exchange_connections', 'is_active')) {
                $table->dropColumn('is_active');
            }
            if (Schema::hasColumn('exchange_connections', 'exchange')) {
                $table->dropColumn('exchange');
            }
            if (Schema::hasColumn('exchange_connections', 'user_id')) {
                $table->dropConstrainedForeignId('user_id');
            }
        });

        Schema::dropIfExists('wallet_ledger_entries');
        Schema::dropIfExists('market_prices_cache');
        Schema::dropIfExists('market_ohlc');
        Schema::dropIfExists('bot_logs');
        Schema::dropIfExists('order_fills');
        Schema::dropIfExists('trade_signals');
        Schema::dropIfExists('bots');
    }
};
