<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'currency')) {
                $table->string('currency', 10)->default('USD')->after('amount');
            }
            if (!Schema::hasColumn('invoices', 'invoice_number')) {
                $table->string('invoice_number')->nullable()->unique()->after('id');
            }
            if (!Schema::hasColumn('invoices', 'pdf_url')) {
                $table->string('pdf_url')->nullable()->after('metadata');
            }
            if (!Schema::hasColumn('invoices', 'plan_id')) {
                $table->uuid('plan_id')->nullable()->after('workspace_id');
                $table->foreign('plan_id')->references('id')->on('plans')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'plan_id')) {
                $table->dropForeign(['plan_id']);
                $table->dropColumn('plan_id');
            }
            if (Schema::hasColumn('invoices', 'pdf_url')) {
                $table->dropColumn('pdf_url');
            }
            if (Schema::hasColumn('invoices', 'invoice_number')) {
                $table->dropColumn('invoice_number');
            }
            if (Schema::hasColumn('invoices', 'currency')) {
                $table->dropColumn('currency');
            }
        });
    }
};
