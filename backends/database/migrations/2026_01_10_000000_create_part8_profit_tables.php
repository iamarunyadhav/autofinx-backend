<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->string('type');
            $table->string('provider_key');
            $table->string('display_name');
            $table->string('status')->default('disabled');
            $table->json('config_json')->nullable();
            $table->longText('secrets_encrypted')->nullable();
            $table->timestamp('last_health_at')->nullable();
            $table->string('last_health_status')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id','provider_key']);
        });

        Schema::create('module_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->string('module_key')->index();
            $table->string('mode')->default('off');
            $table->json('settings_json')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id','module_key']);
        });

        Schema::create('arbitrage_opportunities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->string('symbol');
            $table->string('buy_venue');
            $table->string('sell_venue');
            $table->decimal('spread_pct', 10, 4);
            $table->json('meta')->nullable();
            $table->string('status')->default('simulated');
            $table->timestamps();
        });

        Schema::create('yield_positions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->string('asset');
            $table->decimal('apr_bps', 10, 2)->default(0);
            $table->decimal('amount', 20, 8)->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('defi_positions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->string('protocol');
            $table->string('asset');
            $table->decimal('apy_bps', 10, 2)->default(0);
            $table->decimal('amount', 20, 8)->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('profit_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->date('date_bucket')->index();
            $table->decimal('pnl_realized', 20, 8)->default(0);
            $table->decimal('pnl_unrealized', 20, 8)->default(0);
            $table->decimal('fees', 20, 8)->default(0);
            $table->decimal('win_rate', 10, 4)->default(0);
            $table->decimal('drawdown', 10, 4)->default(0);
            $table->integer('trade_count')->default(0);
            $table->json('per_strategy_json')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id','date_bucket']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profit_snapshots');
        Schema::dropIfExists('defi_positions');
        Schema::dropIfExists('yield_positions');
        Schema::dropIfExists('arbitrage_opportunities');
        Schema::dropIfExists('module_settings');
        Schema::dropIfExists('integrations');
    }
};
