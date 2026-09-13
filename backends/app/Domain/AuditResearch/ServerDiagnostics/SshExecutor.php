<?php

namespace App\Domain\AuditResearch\ServerDiagnostics;

use App\Models\ServerConnection;

/**
 * Boundary interface between ServerDiagnosticsService and the actual SSH
 * transport. Exists so the service, controller and tests never depend on
 * phpseclib directly -- and so the "library not installed" gap (if it ever
 * regresses) is a single, clear failure point.
 */
interface SshExecutor
{
    /**
     * Open a connection, authenticate, and run exactly one fixed command.
     * Implementations must NEVER interpolate anything into $command --
     * it is always one of the literal strings from DiagnosticCheckRegistry.
     *
     * @return array{connected: bool, authenticated: bool, output: ?string, error: ?string}
     */
    public function run(ServerConnection $connection, string $plaintextCredential, string $command): array;

    /**
     * A lightweight connect + auth probe with no command execution, used to
     * back the "Test Connection" button.
     *
     * @return array{connected: bool, authenticated: bool, error: ?string}
     */
    public function testConnection(ServerConnection $connection, string $plaintextCredential): array;
}
