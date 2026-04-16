<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('wallet_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->string('name')->default('Primary Wallet');
            $table->string('currency', 10)->default('USD');
            $table->string('type')->default('cash'); // cash | exchange | custodian | defi
            $table->string('status')->default('active');
            $table->decimal('balance', 20, 8)->default(0);
            $table->decimal('available', 20, 8)->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->unique(['workspace_id', 'currency', 'type'], 'wallet_accounts_workspace_currency_type_unique');
        });

        Schema::create('portfolio_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->timestamp('captured_at')->useCurrent();
            $table->decimal('total_value', 24, 8)->default(0);
            $table->json('positions')->nullable();
            $table->json('metrics')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->index(['workspace_id', 'captured_at']);
        });

        Schema::create('market_ticks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('symbol_id');
            $table->decimal('price', 20, 8);
            $table->decimal('bid', 20, 8)->nullable();
            $table->decimal('ask', 20, 8)->nullable();
            $table->decimal('volume', 24, 8)->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('symbol_id')->references('id')->on('symbols')->cascadeOnDelete();
            $table->index(['symbol_id', 'occurred_at']);
        });

        Schema::table('ledger_transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('ledger_transactions', 'wallet_account_id')) {
                $table->uuid('wallet_account_id')->nullable()->after('ledger_id');
                $table->foreign('wallet_account_id')->references('id')->on('wallet_accounts')->nullOnDelete();
                $table->index('wallet_account_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ledger_transactions', function (Blueprint $table) {
            if (Schema::hasColumn('ledger_transactions', 'wallet_account_id')) {
                $table->dropForeign(['wallet_account_id']);
                $table->dropColumn('wallet_account_id');
            }
        });

        Schema::dropIfExists('market_ticks');
        Schema::dropIfExists('portfolio_snapshots');
        Schema::dropIfExists('wallet_accounts');
    }
};
