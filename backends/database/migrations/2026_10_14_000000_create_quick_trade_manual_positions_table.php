<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quick Trade "Manual SL/TP" positions. A self-contained table on purpose: it is
 * NOT quick_trades (whose rows are all time-expiring and are read by the 10 s
 * settler, the software stop-loss monitor, the reconciler, ML shadow, research and
 * the exposure cap). Manual positions have no expiry and must never be visible to
 * any of those readers.
 *
 * `active_key` is "{workspace_id}:{symbol}" while the position is non-terminal and
 * NULL once terminal; the UNIQUE index (NULLs are distinct in MySQL/SQLite) is the
 * DB-level guarantee of at most one active manual position per workspace+symbol.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('quick_trade_manual_positions')) {
            return;
        }

        Schema::create('quick_trade_manual_positions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->uuid('created_by')->nullable();
            $table->uuid('integration_id')->nullable();
            $table->uuid('wallet_account_id')->nullable();
            $table->string('idempotency_key', 120);
            $table->string('mode', 24)->default('manual_sl_tp');
            $table->string('environment', 12)->default('live');
            $table->string('symbol', 32);
            $table->string('direction', 5);            // long | short
            $table->string('entry_side', 4);           // buy | sell
            $table->string('position_mode', 8)->default('one_way'); // one_way | hedge
            $table->string('margin_type', 12)->nullable();
            $table->string('status', 32);
            $table->string('active_key', 80)->nullable();
            $table->string('protection_status', 16)->default('NONE'); // NONE|PENDING|PROTECTED|FAILED|LOST|UNKNOWN
            $table->string('exit_reason', 32)->nullable();

            $table->decimal('stake_amount', 24, 8);
            $table->decimal('leverage', 8, 2);
            $table->decimal('requested_quantity', 28, 10)->nullable();
            $table->decimal('executed_quantity', 28, 10)->nullable();
            $table->decimal('entry_reference_price', 24, 10)->nullable();
            $table->decimal('entry_price', 24, 10)->nullable();
            $table->decimal('entry_slippage_pct', 12, 6)->nullable();
            $table->decimal('estimated_liquidation_price', 24, 10)->nullable();
            $table->decimal('exchange_liquidation_price', 24, 10)->nullable();

            $table->decimal('sl_pct', 8, 4);
            $table->decimal('sl_trigger_price', 24, 10)->nullable();
            $table->decimal('tp_pct', 8, 4)->nullable();
            $table->decimal('tp_trigger_price', 24, 10)->nullable();

            $table->string('entry_client_order_id', 48)->nullable();
            $table->string('entry_order_id', 40)->nullable();
            $table->string('sl_client_algo_id', 48)->nullable();
            $table->string('sl_algo_id', 40)->nullable();
            $table->string('sl_status', 16)->default('NONE');
            $table->timestamp('sl_verified_at')->nullable();
            $table->string('tp_client_algo_id', 48)->nullable();
            $table->string('tp_algo_id', 40)->nullable();
            $table->string('tp_status', 16)->default('NONE');
            $table->timestamp('tp_verified_at')->nullable();
            $table->unsignedSmallInteger('close_attempts')->default(0);
            $table->string('close_client_order_id', 48)->nullable();
            $table->string('close_order_id', 40)->nullable();
            $table->string('closed_by', 16)->nullable();   // user | system | exchange

            $table->decimal('exit_price', 24, 10)->nullable();
            $table->decimal('entry_fee', 24, 8)->nullable();
            $table->decimal('exit_fee', 24, 8)->nullable();
            $table->decimal('funding_fee', 24, 8)->nullable();   // positive = cost
            $table->decimal('gross_pnl', 24, 8)->nullable();
            $table->decimal('net_pnl', 24, 8)->nullable();
            $table->string('pnl_source', 12)->nullable();        // real | partial | estimated

            $table->boolean('critical_alert')->default(false);
            $table->boolean('emergency_close_confirmed')->default(false);
            $table->string('last_error', 500)->nullable();
            $table->unsignedSmallInteger('reconcile_failures')->default(0);
            $table->json('last_exchange_state')->nullable();

            $table->timestamp('opened_at')->nullable();
            $table->timestamp('protected_at')->nullable();
            $table->timestamp('closing_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('wallet_settled_at')->nullable();
            $table->timestamp('last_reconciled_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'idempotency_key'], 'qtm_positions_idem_unique');
            $table->unique('active_key', 'qtm_positions_active_key_unique');
            $table->index(['status', 'updated_at'], 'qtm_positions_status_idx');
            $table->index(['workspace_id', 'symbol', 'status'], 'qtm_positions_ws_symbol_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quick_trade_manual_positions');
    }
};
