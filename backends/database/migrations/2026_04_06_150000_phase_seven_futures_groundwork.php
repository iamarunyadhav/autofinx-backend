<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('futures_trading_configs')) {
            Schema::create('futures_trading_configs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id')->unique();
                $table->boolean('futures_enabled')->default(false);
                $table->string('futures_mode', 32)->default('disabled');
                $table->json('allowed_futures_symbols')->nullable();
                $table->json('blocked_futures_symbols')->nullable();
                $table->decimal('max_futures_trade_size_pct', 10, 4)->nullable();
                $table->decimal('max_futures_notional', 20, 8)->nullable();
                $table->decimal('max_leverage', 10, 4)->nullable();
                $table->json('allowed_margin_modes')->nullable();
                $table->json('allowed_position_modes')->nullable();
                $table->unsignedInteger('max_open_futures_positions')->nullable();
                $table->decimal('max_futures_daily_loss_pct', 10, 4)->nullable();
                $table->decimal('max_futures_drawdown_pct', 10, 4)->nullable();
                $table->decimal('min_liquidation_buffer_pct', 10, 4)->nullable();
                $table->boolean('require_reduce_only_for_protective_exit')->default(true);
                $table->boolean('require_manual_approval_for_futures')->default(true);
                $table->decimal('futures_confidence_threshold', 10, 4)->nullable();
                $table->boolean('futures_force_testnet')->default(true);
                $table->text('notes')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->timestamps();

                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
                $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            });
        }

        Schema::table('positions', function (Blueprint $table) {
            if (! Schema::hasColumn('positions', 'market_type')) {
                $table->string('market_type', 16)->default('spot')->after('provider')->index();
            }
            if (! Schema::hasColumn('positions', 'environment')) {
                $table->string('environment', 16)->default('live')->after('market_type');
            }
            if (! Schema::hasColumn('positions', 'leverage')) {
                $table->decimal('leverage', 10, 4)->nullable()->after('environment');
            }
            if (! Schema::hasColumn('positions', 'margin_mode')) {
                $table->string('margin_mode', 16)->nullable()->after('leverage');
            }
            if (! Schema::hasColumn('positions', 'position_mode')) {
                $table->string('position_mode', 16)->nullable()->after('margin_mode');
            }
            if (! Schema::hasColumn('positions', 'liquidation_price')) {
                $table->decimal('liquidation_price', 20, 8)->nullable()->after('position_mode');
            }
            if (! Schema::hasColumn('positions', 'liquidation_buffer_pct')) {
                $table->decimal('liquidation_buffer_pct', 10, 4)->nullable()->after('liquidation_price');
            }
            if (! Schema::hasColumn('positions', 'mark_price_synced_at')) {
                $table->timestamp('mark_price_synced_at')->nullable()->after('liquidation_buffer_pct');
            }
        });

        DB::table('positions')
            ->where(function ($query): void {
                $query->whereNull('market_type')->orWhere('market_type', '');
            })
            ->update([
                'market_type' => 'spot',
                'environment' => DB::raw("COALESCE(NULLIF(environment, ''), 'live')"),
            ]);

        try {
            Schema::table('positions', function (Blueprint $table) {
                $table->dropUnique('positions_workspace_id_symbol_id_unique');
            });
        } catch (\Throwable) {
            // Existing environments may already have the revised unique constraint.
        }

        try {
            Schema::table('positions', function (Blueprint $table) {
                $table->unique(['workspace_id', 'symbol_id', 'market_type'], 'positions_workspace_symbol_market_unique');
            });
        } catch (\Throwable) {
            // Ignore if already present.
        }
    }

    public function down(): void
    {
        try {
            Schema::table('positions', function (Blueprint $table) {
                $table->dropUnique('positions_workspace_symbol_market_unique');
            });
        } catch (\Throwable) {
            // Ignore.
        }

        try {
            Schema::table('positions', function (Blueprint $table) {
                $table->unique(['workspace_id', 'symbol_id'], 'positions_workspace_id_symbol_id_unique');
            });
        } catch (\Throwable) {
            // Ignore.
        }

        Schema::table('positions', function (Blueprint $table) {
            foreach ([
                'mark_price_synced_at',
                'liquidation_buffer_pct',
                'liquidation_price',
                'position_mode',
                'margin_mode',
                'leverage',
                'environment',
                'market_type',
            ] as $column) {
                if (Schema::hasColumn('positions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('futures_trading_configs');
    }
};
