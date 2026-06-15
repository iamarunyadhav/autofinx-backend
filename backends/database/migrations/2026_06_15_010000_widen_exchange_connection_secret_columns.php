<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Encrypted API credentials (Laravel Crypt + base64 JSON envelope) exceed the
     * original VARCHAR(255) columns, causing "Data too long for column" on save.
     * Widen them to TEXT so connections can be stored.
     */
    public function up(): void
    {
        if (! Schema::hasTable('exchange_connections')) {
            return;
        }

        Schema::table('exchange_connections', function (Blueprint $table) {
            foreach (['api_key_encrypted', 'api_secret_encrypted', 'passphrase_encrypted'] as $column) {
                if (Schema::hasColumn('exchange_connections', $column)) {
                    $table->text($column)->nullable()->change();
                }
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('exchange_connections')) {
            return;
        }

        Schema::table('exchange_connections', function (Blueprint $table) {
            foreach (['api_key_encrypted', 'api_secret_encrypted', 'passphrase_encrypted'] as $column) {
                if (Schema::hasColumn('exchange_connections', $column)) {
                    $table->string($column)->nullable()->change();
                }
            }
        });
    }
};
