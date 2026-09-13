<?php

namespace App\Domain\AuditResearch\ServerDiagnostics;

/**
 * A small, fixed whitelist of read-only diagnostic commands. This is the
 * ENTIRE surface area of what a server_connections credential can ever be
 * used to run -- nothing here is built from workspace/user-supplied input,
 * every value is a literal string. `ServerDiagnosticsService::runCheck()`
 * looks a command up by key ONLY and rejects any key not in this list; it
 * never concatenates anything into a shell command.
 */
final class DiagnosticCheckRegistry
{
    private const CHECKS = [
        'process_health' => 'pgrep -a php | grep -E "queue:work|live-feed|kline-stream" || echo "no matching processes"',
        'disk_usage' => 'df -h / 2>&1',
        'memory_usage' => 'free -m 2>&1',
        'supervisor_status' => 'supervisorctl status 2>&1 || echo "supervisorctl not available"',
        'uptime' => 'uptime 2>&1',
        'load_average' => 'cat /proc/loadavg 2>&1 || echo "loadavg not available"',
        'redis_ping' => 'redis-cli ping 2>&1 || echo "redis-cli not available"',
        'failed_jobs_count' => 'echo "failed_jobs check requires app-side query, not SSH"',
    ];

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        return self::CHECKS;
    }

    public static function has(string $checkKey): bool
    {
        return array_key_exists($checkKey, self::CHECKS);
    }

    public static function commandFor(string $checkKey): string
    {
        if (! self::has($checkKey)) {
            throw new \InvalidArgumentException("Unknown diagnostic check key: {$checkKey}");
        }

        return self::CHECKS[$checkKey];
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::CHECKS);
    }
}
