<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_tokens', function (Blueprint $table): void {
            if (! Schema::hasColumn('api_tokens', 'last_used_at')) {
                $table->timestamp('last_used_at')->nullable()->after('scopes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('api_tokens', function (Blueprint $table): void {
            if (Schema::hasColumn('api_tokens', 'last_used_at')) {
                $table->dropColumn('last_used_at');
            }
        });
    }
};
