<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('integrations')) {
            return;
        }

        if (! Schema::hasColumn('integrations', 'environment')) {
            Schema::table('integrations', function (Blueprint $table) {
                $table->string('environment', 16)->default('live')->after('provider_key');
            });
        }

        // Backfill the column from the existing config_json.environment value.
        try {
            DB::statement("UPDATE integrations SET environment = COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(config_json, '$.environment')), ''), 'live')");
        } catch (\Throwable) {
            DB::table('integrations')->whereNull('environment')->update(['environment' => 'live']);
        }

        // Relax the per-provider unique key so testnet and live can coexist.
        Schema::table('integrations', function (Blueprint $table) {
            try {
                $table->dropUnique(['workspace_id', 'provider_key']);
            } catch (\Throwable) {
                // Index already dropped / named differently — ignore.
            }
        });

        Schema::table('integrations', function (Blueprint $table) {
            try {
                $table->unique(['workspace_id', 'provider_key', 'environment']);
            } catch (\Throwable) {
                // Unique already present — ignore.
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('integrations')) {
            return;
        }

        Schema::table('integrations', function (Blueprint $table) {
            try {
                $table->dropUnique(['workspace_id', 'provider_key', 'environment']);
            } catch (\Throwable) {
            }
            try {
                $table->unique(['workspace_id', 'provider_key']);
            } catch (\Throwable) {
            }
        });

        if (Schema::hasColumn('integrations', 'environment')) {
            Schema::table('integrations', function (Blueprint $table) {
                $table->dropColumn('environment');
            });
        }
    }
};
