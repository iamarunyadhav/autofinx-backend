<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit Research: server connection + diagnostics subsystem. Stores SSH
 * login details for a RESTRICTED, READ-ONLY diagnostic user the operator
 * sets up on their own server -- never root, never a general-purpose
 * command runner. See App\Domain\AuditResearch\ServerDiagnostics for the
 * fixed whitelist of commands this can ever execute.
 *
 * `credential_encrypted` holds either the SSH private key OR the password
 * (depending on `auth_method`), encrypted with the exact same mechanism as
 * exchange connections (App\Support\ExchangeSecretVault) -- widened to TEXT
 * up front (see 2026_06_15_010000_widen_exchange_connection_secret_columns
 * for why a VARCHAR(255) column was too small for an encrypted private key).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->string('name');
            $table->string('host');
            $table->unsignedInteger('port')->default(22);
            $table->string('ssh_username');
            $table->string('auth_method', 16);
            // key | password
            $table->text('credential_encrypted')->nullable();
            $table->string('status', 24)->default('not_configured');
            // unknown | connected | disconnected | degraded | unauthorized | not_configured
            $table->timestamp('last_checked_at')->nullable();
            $table->json('last_check_result')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_connections');
    }
};
