<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('future_trade_logical_trades')) {
            Schema::create('future_trade_logical_trades', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('future_trade_bot_config_id')->index();
                $table->string('workspace_id', 64)->nullable()->index();
                $table->string('symbol', 32);
                $table->string('side', 16); // LONG | SHORT
                $table->string('status', 24)->default('OPEN'); // OPEN | CLOSING | CLOSED | CANCELLED | ERROR | RECONCILIATION_REQUIRED
                $table->string('mode', 16)->default('PAPER'); // PAPER | LIVE_TESTNET | LIVE
                $table->string('signal_key', 191)->nullable();
                $table->string('candle_time', 32)->nullable();
                $table->string('timeframe', 16)->nullable();

                $table->string('entry_client_order_id', 64)->nullable();
                $table->string('entry_exchange_order_id', 64)->nullable();
                $table->decimal('entry_price', 24, 10)->nullable();
                $table->decimal('quantity', 24, 10)->default(0);
                $table->decimal('leverage', 8, 2)->default(1);

                $table->string('exit_client_order_id', 64)->nullable();
                $table->string('exit_exchange_order_id', 64)->nullable();
                $table->decimal('exit_price', 24, 10)->nullable();

                $table->decimal('gross_pnl', 24, 10)->nullable();
                $table->decimal('fees', 24, 10)->default(0);
                $table->decimal('net_pnl', 24, 10)->nullable();
                $table->string('close_reason', 96)->nullable();

                $table->timestamp('opened_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->timestamp('reconciled_at')->nullable();
                $table->string('runtime_instance_id', 64)->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->index(['symbol', 'status']);
                $table->index(['future_trade_bot_config_id', 'status']);
                $table->index(['mode']);
                $table->index(['entry_client_order_id']);
                $table->index(['exit_client_order_id']);
                $table->unique(['future_trade_bot_config_id', 'signal_key'], 'flt_config_signal_key_unique');
            });
        }

        if (! Schema::hasTable('future_trade_logical_trade_events')) {
            Schema::create('future_trade_logical_trade_events', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('future_trade_logical_trade_id')->nullable()->index();
                $table->uuid('future_trade_bot_config_id')->nullable()->index();
                $table->string('symbol', 32)->nullable();
                $table->string('event_type', 64); // SIGNAL_RAW | SIGNAL_CONFIRMED | ENTRY_SUBMITTED | ENTRY_FILLED | EXIT_SUBMITTED | EXIT_FILLED | RECONCILED | ERROR | BLOCKED
                $table->string('client_order_id', 64)->nullable();
                $table->string('exchange_order_id', 64)->nullable();
                $table->string('exchange_status', 32)->nullable();
                $table->decimal('price', 24, 10)->nullable();
                $table->decimal('quantity', 24, 10)->nullable();
                $table->json('payload')->nullable();
                $table->timestamps();

                $table->index(['client_order_id']);
                $table->index(['event_type']);
            });
        }

        if (! Schema::hasTable('future_trade_runtime_instances')) {
            Schema::create('future_trade_runtime_instances', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('runtime_instance_id', 64)->unique();
                $table->string('command', 64);
                $table->integer('pid')->nullable();
                $table->string('hostname', 128)->nullable();
                $table->string('lock_key', 191)->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('last_heartbeat_at')->nullable();
                $table->timestamp('stopped_at')->nullable();
                $table->string('status', 32)->default('RUNNING');
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->index(['command', 'status']);
                $table->index(['last_heartbeat_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('future_trade_runtime_instances');
        Schema::dropIfExists('future_trade_logical_trade_events');
        Schema::dropIfExists('future_trade_logical_trades');
    }
};
