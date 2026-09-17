<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('quick_trades')) {
            return;
        }

        Schema::table('quick_trades', function (Blueprint $table) {
            if (! Schema::hasColumn('quick_trades', 'gross_pnl')) {
                $table->decimal('gross_pnl', 20, 8)->nullable()->after('profit_loss');
            }
            if (! Schema::hasColumn('quick_trades', 'entry_fee_actual')) {
                $table->decimal('entry_fee_actual', 20, 8)->nullable()->after('fees_total');
            }
            if (! Schema::hasColumn('quick_trades', 'exit_fee_actual')) {
                $table->decimal('exit_fee_actual', 20, 8)->nullable()->after('entry_fee_actual');
            }
            if (! Schema::hasColumn('quick_trades', 'funding_fee_actual')) {
                $table->decimal('funding_fee_actual', 20, 8)->default(0)->nullable()->after('exit_fee_actual');
            }
            if (! Schema::hasColumn('quick_trades', 'fee_source')) {
                // 'estimated' (no real lookup succeeded), 'real' (commission and
                // funding both resolved from the exchange), or 'partial' (one of
                // the two resolved, the other fell back to the estimate).
                $table->string('fee_source', 16)->default('estimated')->after('funding_fee_actual');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('quick_trades')) {
            return;
        }

        Schema::table('quick_trades', function (Blueprint $table) {
            foreach (['fee_source', 'funding_fee_actual', 'exit_fee_actual', 'entry_fee_actual', 'gross_pnl'] as $column) {
                if (Schema::hasColumn('quick_trades', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
