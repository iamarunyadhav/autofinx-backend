<?php

namespace App\Domain\AuditResearch\ServerDiagnostics;

use App\Models\ServerConnection;
use App\Support\ExchangeSecretVault;

/**
 * Orchestrates server diagnostics: looks a check up by key against the
 * fixed DiagnosticCheckRegistry whitelist ONLY, runs it via the injected
 * SshExecutor (real phpseclib3 transport, or a sandbox-mode canned-but-
 * realistic response -- mirrors OandaClient's sandbox-mode pattern so this
 * is fully unit-testable without a real server), and persists
 * last_checked_at / last_check_result / status on the connection.
 *
 * Never builds a shell command from anything other than a literal string
 * pulled from DiagnosticCheckRegistry -- runCheck() rejects unknown keys
 * before ever touching the SSH transport.
 */
class ServerDiagnosticsService
{
    /**
     * Host substrings that mark a connection as a test/placeholder target
     * -- deliberately broad so any obviously-fake host used in a demo or
     * test environment never triggers a real network connection attempt.
     */
    private const SANDBOX_HOST_NEEDLES = [
        'example.com',
        'example.org',
        'example.net',
        'test',
        'sandbox',
        'placeholder',
        'localhost',
        '127.0.0.1',
        '192.0.2.', // TEST-NET-1, reserved for documentation/example use
        '.invalid',
    ];

    public function __construct(
        private readonly ExchangeSecretVault $vault,
        private readonly SshExecutor $executor,
    ) {
    }

    public function testConnection(ServerConnection $connection): array
    {
        if ($this->isSandbox($connection)) {
            $result = $this->sandboxProbe();
        } else {
            $plaintext = $this->vault->decrypt($connection->credential_encrypted);
            $result = $plaintext
                ? $this->executor->testConnection($connection, $plaintext)
                : ['connected' => false, 'authenticated' => false, 'error' => 'No credential configured for this connection.'];
        }

        $status = $this->statusFromResult($result);
        $checkResult = array_merge($result, [
            'checked_at' => now()->toIso8601String(),
            'mode' => $this->isSandbox($connection) ? 'sandbox' : 'live',
        ]);

        $connection->update([
            'status' => $status,
            'last_checked_at' => now(),
            'last_check_result' => $checkResult,
        ]);

        return ['status' => $status] + $checkResult;
    }

    public function runCheck(ServerConnection $connection, string $checkKey): array
    {
        if (! DiagnosticCheckRegistry::has($checkKey)) {
            throw new \InvalidArgumentException("Unknown diagnostic check key: {$checkKey}");
        }

        $command = DiagnosticCheckRegistry::commandFor($checkKey);

        if ($this->isSandbox($connection)) {
            $result = $this->sandboxOutputFor($checkKey);
        } else {
            $plaintext = $this->vault->decrypt($connection->credential_encrypted);
            $result = $plaintext
                ? $this->executor->run($connection, $plaintext, $command)
                : ['connected' => false, 'authenticated' => false, 'output' => null, 'error' => 'No credential configured for this connection.'];
        }

        $status = $this->statusFromResult($result);
        $checkResult = array_merge($result, [
            'check_key' => $checkKey,
            'checked_at' => now()->toIso8601String(),
            'mode' => $this->isSandbox($connection) ? 'sandbox' : 'live',
        ]);

        $connection->update([
            'status' => $status,
            'last_checked_at' => now(),
            'last_check_result' => $checkResult,
        ]);

        return $checkResult;
    }

    public function isSandbox(ServerConnection $connection): bool
    {
        if (config('services.server_diagnostics.force_sandbox', false)) {
            return true;
        }

        $host = strtolower(trim((string) $connection->host));
        foreach (self::SANDBOX_HOST_NEEDLES as $needle) {
            if ($host === '' || str_contains($host, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function statusFromResult(array $result): string
    {
        if (! ($result['connected'] ?? false)) {
            return ServerConnection::STATUS_DISCONNECTED;
        }

        if (! ($result['authenticated'] ?? false)) {
            return ServerConnection::STATUS_UNAUTHORIZED;
        }

        if (! empty($result['error'])) {
            return ServerConnection::STATUS_DEGRADED;
        }

        return ServerConnection::STATUS_CONNECTED;
    }

    private function sandboxProbe(): array
    {
        return [
            'connected' => true,
            'authenticated' => true,
            'error' => null,
        ];
    }

    private function sandboxOutputFor(string $checkKey): array
    {
        $canned = [
            'process_health' => "php artisan queue:work --queue=ratio_bot   [pid 18422]\nphp artisan live-feed:run              [pid 18430]\nphp artisan kline-stream:run            [pid 18441]",
            'disk_usage' => "Filesystem      Size  Used Avail Use% Mounted on\n/dev/sda1        50G   18G   30G  38% /",
            'memory_usage' => "              total        used        free      shared  buff/cache   available\nMem:           7822        2456        1122         210        4243        5001",
            'supervisor_status' => "autofinx-ratio-bot               RUNNING   pid 18422, uptime 2:14:03\nautofinx-scheduler               RUNNING   pid 18401, uptime 4:02:11",
            'uptime' => " 12:34:56 up 10 days,  4:12,  1 user,  load average: 0.15, 0.20, 0.18",
            'load_average' => "0.15 0.20 0.18 1/423 18512",
            'redis_ping' => 'PONG',
            'failed_jobs_count' => 'failed_jobs check requires app-side query, not SSH',
        ];

        return [
            'connected' => true,
            'authenticated' => true,
            'output' => $canned[$checkKey] ?? "sandbox output for {$checkKey}",
            'error' => null,
        ];
    }
}
