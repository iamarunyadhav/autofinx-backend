<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Extend venues with fee/latency/slippage
        if (Schema::hasTable('venues')) {
            Schema::table('venues', function (Blueprint $table) {
                if (!Schema::hasColumn('venues', 'fee_maker')) {
                    $table->decimal('fee_maker', 8, 4)->default(0.1)->after('enabled');
                }
                if (!Schema::hasColumn('venues', 'fee_taker')) {
                    $table->decimal('fee_taker', 8, 4)->default(0.1)->after('fee_maker');
                }
                if (!Schema::hasColumn('venues', 'slippage_default')) {
                    $table->decimal('slippage_default', 8, 4)->default(0.05)->after('fee_taker');
                }
                if (!Schema::hasColumn('venues', 'latency_ms_last')) {
                    $table->integer('latency_ms_last')->nullable()->after('slippage_default');
                }
            });
        }

        if (!Schema::hasTable('routing_policies')) {
            Schema::create('routing_policies', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id')->index();
                $table->string('mode')->default('best_price');
                $table->boolean('allow_split_orders')->default(false);
                $table->integer('max_venues_per_order')->default(1);
                $table->decimal('min_balance_usd', 14, 4)->default(0);
                $table->boolean('enabled')->default(true);
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('execution_plans')) {
            Schema::create('execution_plans', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id')->index();
                $table->uuid('strategy_instance_id')->nullable()->index();
                $table->string('intent')->default('trade');
                $table->string('symbol');
                $table->string('side');
                $table->decimal('requested_qty', 20, 8)->default(0);
                $table->json('planned_routes_json')->nullable();
                $table->string('status')->default('planned');
                $table->string('reason')->nullable();
                $table->timestamps();
                $table->index(['workspace_id','status']);
            });
        }

        if (!Schema::hasTable('guardrails')) {
            Schema::create('guardrails', function (Blueprint $table) {
                $table->uuid('workspace_id')->primary();
                $table->decimal('max_slippage_pct', 8, 4)->default(1);
                $table->decimal('max_spread_pct', 8, 4)->default(1);
                $table->integer('max_open_orders')->default(20);
                $table->integer('loss_streak_cooldown_minutes')->default(30);
                $table->decimal('max_daily_loss_pct', 8, 4)->default(5);
                $table->decimal('trailing_stop_pct', 8, 4)->default(3);
                $table->decimal('take_profit_pct', 8, 4)->default(5);
                $table->decimal('volatility_block_pct', 8, 4)->default(10);
                $table->integer('circuit_breaker_failures')->default(3);
                $table->uuid('updated_by')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('portfolio_targets')) {
            Schema::create('portfolio_targets', function (Blueprint $table) {
                $table->uuid('workspace_id')->primary();
                $table->json('targets_json');
                $table->integer('rebalance_interval_minutes')->default(1440);
                $table->boolean('enabled')->default(false);
                $table->timestamp('last_rebalanced_at')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('assistant_actions')) {
            Schema::create('assistant_actions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id')->index();
                $table->uuid('user_id')->index();
                $table->string('session_id')->nullable();
                $table->string('tool_name');
                $table->json('request_json')->nullable();
                $table->json('preview_json')->nullable();
                $table->string('status')->default('proposed');
                $table->boolean('requires_2fa')->default(false);
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('executed_at')->nullable();
                $table->uuid('audit_id')->nullable();
                $table->timestamps();
            });
        }

        // Extend arbitrage_opportunities if table exists
        if (Schema::hasTable('arbitrage_opportunities')) {
            Schema::table('arbitrage_opportunities', function (Blueprint $table) {
                if (!Schema::hasColumn('arbitrage_opportunities', 'type')) {
                    $table->string('type')->default('cross_exchange');
                }
                if (!Schema::hasColumn('arbitrage_opportunities', 'legs_json')) {
                    $table->json('legs_json')->nullable();
                }
                if (!Schema::hasColumn('arbitrage_opportunities', 'estimated_profit_usd')) {
                    $table->decimal('estimated_profit_usd', 18, 4)->nullable();
                }
                if (!Schema::hasColumn('arbitrage_opportunities', 'estimated_profit_pct')) {
                    $table->decimal('estimated_profit_pct', 10, 4)->nullable();
                }
                if (!Schema::hasColumn('arbitrage_opportunities', 'fee_estimate_usd')) {
                    $table->decimal('fee_estimate_usd', 18, 4)->nullable();
                }
                if (!Schema::hasColumn('arbitrage_opportunities', 'slippage_estimate_usd')) {
                    $table->decimal('slippage_estimate_usd', 18, 4)->nullable();
                }
                if (!Schema::hasColumn('arbitrage_opportunities', 'status')) {
                    $table->string('status')->default('new')->index();
                }
                if (!Schema::hasColumn('arbitrage_opportunities', 'captured_at')) {
                    $table->timestamp('captured_at')->nullable()->index();
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_actions');
        Schema::dropIfExists('portfolio_targets');
        Schema::dropIfExists('guardrails');
        Schema::dropIfExists('execution_plans');
        Schema::dropIfExists('routing_policies');

        if (Schema::hasTable('venues')) {
            Schema::table('venues', function (Blueprint $table) {
                foreach (['fee_maker','fee_taker','slippage_default','latency_ms_last'] as $col) {
                    if (Schema::hasColumn('venues', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
