<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_trading_configs', function (Blueprint $table) {
            if (! Schema::hasColumn('ai_trading_configs', 'provider')) {
                $table->string('provider', 32)->default('heuristic')->after('market_type');
            }
            if (! Schema::hasColumn('ai_trading_configs', 'provider_model')) {
                $table->string('provider_model', 128)->nullable()->after('provider');
            }
            if (! Schema::hasColumn('ai_trading_configs', 'cooldown_minutes')) {
                $table->unsignedInteger('cooldown_minutes')->default(0)->after('min_confidence');
            }
            if (! Schema::hasColumn('ai_trading_configs', 'max_pending_decisions_per_symbol')) {
                $table->unsignedInteger('max_pending_decisions_per_symbol')->default(1)->after('cooldown_minutes');
            }
            if (! Schema::hasColumn('ai_trading_configs', 'max_decisions_per_hour_per_symbol')) {
                $table->unsignedInteger('max_decisions_per_hour_per_symbol')->default(12)->after('max_pending_decisions_per_symbol');
            }
        });

        Schema::create('ai_decision_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ai_decision_id')->index();
            $table->uuid('workspace_id')->index();
            $table->uuid('actor_id')->nullable()->index();
            $table->string('actor_type', 32)->nullable();
            $table->string('event_type', 64)->index();
            $table->json('payload_json')->nullable();
            $table->timestamps();

            $table->foreign('ai_decision_id')->references('id')->on('ai_decisions')->cascadeOnDelete();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_decision_events');

        Schema::table('ai_trading_configs', function (Blueprint $table) {
            foreach ([
                'provider_model',
                'provider',
                'cooldown_minutes',
                'max_pending_decisions_per_symbol',
                'max_decisions_per_hour_per_symbol',
            ] as $column) {
                if (Schema::hasColumn('ai_trading_configs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
