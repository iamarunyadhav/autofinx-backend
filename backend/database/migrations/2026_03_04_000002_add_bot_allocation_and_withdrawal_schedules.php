<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            if (! Schema::hasColumn('bots', 'allocation_mode')) {
                $table->string('allocation_mode', 16)->default('FIXED')->after('strategy_params');
            }
            if (! Schema::hasColumn('bots', 'allocation_percent')) {
                $table->decimal('allocation_percent', 10, 4)->nullable()->after('allocation_mode');
            }
            if (! Schema::hasColumn('bots', 'trade_allocation_amount')) {
                $table->decimal('trade_allocation_amount', 20, 8)->nullable()->after('allocation_percent');
            }
            if (! Schema::hasColumn('bots', 'trade_allocation_currency')) {
                $table->string('trade_allocation_currency', 10)->nullable()->after('trade_allocation_amount');
            }
        });

        if (! Schema::hasTable('withdrawal_schedules')) {
            Schema::create('withdrawal_schedules', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('workspace_id')->index();
                $table->uuid('user_id')->nullable()->index();
                $table->string('name', 120);
                $table->string('currency', 10)->default('USD');
                $table->string('method', 32)->default('bank_transfer');
                $table->string('cadence', 24)->default('weekly');
                $table->unsignedTinyInteger('day_of_week')->nullable();
                $table->unsignedTinyInteger('day_of_month')->nullable();
                $table->unsignedTinyInteger('hour')->default(0);
                $table->unsignedTinyInteger('minute')->default(0);
                $table->string('timezone', 64)->default('Asia/Colombo');
                $table->string('amount_mode', 32)->default('fixed');
                $table->decimal('amount_value', 20, 8)->default(0);
                $table->decimal('reserve_amount', 20, 8)->default(0);
                $table->decimal('min_payout_amount', 20, 8)->default(0);
                $table->json('destination_meta')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamp('last_run_at')->nullable();
                $table->timestamp('next_run_at')->nullable()->index();
                $table->string('last_status', 32)->nullable();
                $table->text('last_error')->nullable();
                $table->timestamps();

                $table->index(['workspace_id', 'is_active', 'next_run_at'], 'withdrawal_schedules_due_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawal_schedules');

        Schema::table('bots', function (Blueprint $table) {
            foreach ([
                'trade_allocation_currency',
                'trade_allocation_amount',
                'allocation_percent',
                'allocation_mode',
            ] as $column) {
                if (Schema::hasColumn('bots', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

