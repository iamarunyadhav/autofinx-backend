<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('exchange_symbol_metadata')) {
            Schema::create('exchange_symbol_metadata', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('exchange')->index();
                $table->string('symbol')->index();
                $table->string('status')->nullable();
                $table->string('base_asset')->nullable();
                $table->string('quote_asset')->nullable();
                $table->decimal('min_qty', 20, 8)->nullable();
                $table->decimal('max_qty', 20, 8)->nullable();
                $table->decimal('step_size', 20, 8)->nullable();
                $table->decimal('min_notional', 20, 8)->nullable();
                $table->decimal('tick_size', 20, 8)->nullable();
                $table->timestamp('last_synced_at')->nullable()->index();
                $table->json('raw_json')->nullable();
                $table->timestamps();

                $table->unique(['exchange', 'symbol'], 'exchange_symbol_metadata_exchange_symbol_unique');
            });
        }

        if (! Schema::hasTable('live_execution_events')) {
            Schema::create('live_execution_events', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id')->nullable()->index();
                $table->uuid('order_id')->nullable()->index();
                $table->uuid('integration_id')->nullable()->index();
                $table->string('provider')->nullable()->index();
                $table->string('exchange')->nullable()->index();
                $table->string('symbol')->nullable()->index();
                $table->string('event_type')->index();
                $table->string('severity')->default('info')->index();
                $table->string('source')->nullable()->index();
                $table->string('correlation_id')->nullable()->index();
                $table->json('payload_json')->nullable();
                $table->timestamp('occurred_at')->index();
                $table->timestamps();

                $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
            });
        }

        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'client_order_id')) {
                $table->string('client_order_id')->nullable()->after('exchange_order_id')->index();
            }
            if (! Schema::hasColumn('orders', 'provider_status')) {
                $table->string('provider_status')->nullable()->after('status')->index();
            }
            if (! Schema::hasColumn('orders', 'provider_synced_at')) {
                $table->timestamp('provider_synced_at')->nullable()->after('filled_ts')->index();
            }
            if (! Schema::hasColumn('orders', 'last_reconciled_at')) {
                $table->timestamp('last_reconciled_at')->nullable()->after('provider_synced_at')->index();
            }
            if (! Schema::hasColumn('orders', 'last_sync_source')) {
                $table->string('last_sync_source')->nullable()->after('last_reconciled_at');
            }
            if (! Schema::hasColumn('orders', 'reconciliation_state')) {
                $table->string('reconciliation_state')->nullable()->after('last_sync_source')->index();
            }
            if (! Schema::hasColumn('orders', 'uncertain_at')) {
                $table->timestamp('uncertain_at')->nullable()->after('reconciliation_state')->index();
            }
            if (! Schema::hasColumn('orders', 'sync_error_count')) {
                $table->unsignedInteger('sync_error_count')->default(0)->after('uncertain_at');
            }
        });

        Schema::table('execution_attempts', function (Blueprint $table) {
            if (! Schema::hasColumn('execution_attempts', 'trace_id')) {
                $table->string('trace_id')->nullable()->after('attempt_no')->index();
            }
            if (! Schema::hasColumn('execution_attempts', 'client_order_id')) {
                $table->string('client_order_id')->nullable()->after('trace_id')->index();
            }
            if (! Schema::hasColumn('execution_attempts', 'provider_order_id')) {
                $table->string('provider_order_id')->nullable()->after('client_order_id')->index();
            }
            if (! Schema::hasColumn('execution_attempts', 'latency_ms')) {
                $table->unsignedInteger('latency_ms')->nullable()->after('response_json');
            }
            if (! Schema::hasColumn('execution_attempts', 'error_message')) {
                $table->text('error_message')->nullable()->after('latency_ms');
            }
        });

        Schema::table('execution_attempts', function (Blueprint $table) {
            $table->unique(['order_id', 'attempt_no'], 'execution_attempts_order_attempt_unique');
        });
    }

    public function down(): void
    {
        Schema::table('execution_attempts', function (Blueprint $table) {
            if (Schema::hasColumn('execution_attempts', 'error_message')) {
                $table->dropColumn('error_message');
            }
            if (Schema::hasColumn('execution_attempts', 'latency_ms')) {
                $table->dropColumn('latency_ms');
            }
            if (Schema::hasColumn('execution_attempts', 'provider_order_id')) {
                $table->dropColumn('provider_order_id');
            }
            if (Schema::hasColumn('execution_attempts', 'client_order_id')) {
                $table->dropColumn('client_order_id');
            }
            if (Schema::hasColumn('execution_attempts', 'trace_id')) {
                $table->dropColumn('trace_id');
            }
            $table->dropUnique('execution_attempts_order_attempt_unique');
        });

        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'sync_error_count')) {
                $table->dropColumn('sync_error_count');
            }
            if (Schema::hasColumn('orders', 'uncertain_at')) {
                $table->dropColumn('uncertain_at');
            }
            if (Schema::hasColumn('orders', 'reconciliation_state')) {
                $table->dropColumn('reconciliation_state');
            }
            if (Schema::hasColumn('orders', 'last_sync_source')) {
                $table->dropColumn('last_sync_source');
            }
            if (Schema::hasColumn('orders', 'last_reconciled_at')) {
                $table->dropColumn('last_reconciled_at');
            }
            if (Schema::hasColumn('orders', 'provider_synced_at')) {
                $table->dropColumn('provider_synced_at');
            }
            if (Schema::hasColumn('orders', 'provider_status')) {
                $table->dropColumn('provider_status');
            }
            if (Schema::hasColumn('orders', 'client_order_id')) {
                $table->dropColumn('client_order_id');
            }
        });

        Schema::dropIfExists('live_execution_events');
        Schema::dropIfExists('exchange_symbol_metadata');
    }
};
