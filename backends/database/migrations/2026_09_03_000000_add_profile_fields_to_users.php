<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ProfileSettings.tsx (Settings page) has always called
 * supabase.from('profiles').select()/.upsert() — 'profiles' is not in
 * TableController's table whitelist and there is no profiles table at all,
 * so the Settings > Profile save has never actually persisted anything.
 * Adds the fields it collects (full_name, phone, timezone,
 * preferred_language) directly on `users`, the natural home given this is
 * one profile per user, not a separate table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'phone')) {
                $table->string('phone')->nullable()->after('email');
            }
            if (! Schema::hasColumn('users', 'timezone')) {
                $table->string('timezone')->nullable()->after('phone');
            }
            if (! Schema::hasColumn('users', 'preferred_language')) {
                $table->string('preferred_language', 10)->nullable()->after('timezone');
            }
            if (! Schema::hasColumn('users', 'avatar_url')) {
                $table->string('avatar_url')->nullable()->after('preferred_language');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            foreach (['phone', 'timezone', 'preferred_language', 'avatar_url'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
