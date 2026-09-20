<?php

namespace App\Domain\Fast2X;

use Illuminate\Support\Facades\File;

/**
 * Spawns/monitors/terminates the per-workspace `fast2x:run {workspace_id}`
 * OS process. Unlike Future Trade's live feed (one global daemon for every
 * workspace, see FutureTradeLiveFeedManager), Fast2X needs one daemon PER
 * workspace that has it enabled, so state is tracked per workspace_id
 * (its own meta/log file set) rather than a single shared meta file.
 *
 * Mirrors FutureTradeLiveFeedManager's cross-platform spawn/terminate
 * approach (nohup+proc_open fallback on Unix, Start-Process on Windows) —
 * see that class for the original pattern this was copied from.
 */
class Fast2XDaemonManager
{
    public function isProcessRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $output = $this->runShellCommand('tasklist /FI "PID eq '.$pid.'" /FO CSV /NH');

            return is_string($output) && str_contains($output, '"'.$pid.'"');
        }

        if (\function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        $output = $this->runShellCommand('ps -p '.$pid.' -o pid=');

        return trim((string) $output) !== '';
    }

    /**
     * @return array<string, mixed>|null
     */
    public function loadMeta(string $workspaceId): ?array
    {
        $path = $this->metaPath($workspaceId);
        if (! File::exists($path)) {
            return null;
        }

        $decoded = json_decode((string) File::get($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Current OS-level status for this workspace's daemon: whether the last
     * PID we recorded is still alive. This is deliberately a separate check
     * from the DB heartbeat — a dead PID means "definitely crashed"; a live
     * PID with a stale heartbeat means "hung", which the caller decides how
     * to handle (this class only knows about the process, not trading state).
     */
    public function isRunning(string $workspaceId): bool
    {
        $meta = $this->loadMeta($workspaceId);
        $pid = (int) ($meta['pid'] ?? 0);

        return $pid > 0 && $this->isProcessRunning($pid);
    }

    public function terminate(string $workspaceId): void
    {
        $meta = $this->loadMeta($workspaceId);
        $pid = (int) ($meta['pid'] ?? 0);
        if ($pid <= 0) {
            $this->clearMeta($workspaceId);

            return;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $this->runShellCommand('taskkill /PID '.$pid.' /T /F');
        } elseif (\function_exists('posix_kill')) {
            @posix_kill($pid, SIGTERM);
            usleep(500000);
            if ($this->isProcessRunning($pid)) {
                @posix_kill($pid, SIGKILL);
            }
        } else {
            $this->runShellCommand('kill -TERM '.$pid);
        }

        $this->clearMeta($workspaceId);
    }

    /**
     * Starts (or restarts) `fast2x:run {workspaceId}` as a detached OS
     * process and records its PID. Returns the recorded meta, or null if
     * this host cannot spawn background processes at all (shared hosting
     * with shell_exec/exec/proc_open disabled) — the caller should log that
     * as "manual restart required" rather than silently doing nothing.
     *
     * @return array<string, mixed>|null
     */
    public function spawn(string $workspaceId): ?array
    {
        if (! $this->canSpawnDaemon()) {
            return null;
        }

        $this->ensureRuntimePaths();

        $pid = PHP_OS_FAMILY === 'Windows'
            ? $this->spawnWindows($workspaceId)
            : $this->spawnUnix($workspaceId);

        if ($pid <= 0) {
            return null;
        }

        $meta = [
            'pid' => $pid,
            'workspace_id' => $workspaceId,
            'command' => $this->commandLine($workspaceId),
            'started_at' => now()->toIso8601String(),
            'log_path' => $this->outLogPath($workspaceId),
            'error_log_path' => $this->errLogPath($workspaceId),
        ];
        $this->storeMeta($workspaceId, $meta);

        return $meta;
    }

    public function canSpawnDaemon(): bool
    {
        return \function_exists('shell_exec')
            || \function_exists('exec')
            || (PHP_OS_FAMILY !== 'Windows' && \function_exists('proc_open'));
    }

    private function spawnWindows(string $workspaceId): int
    {
        $php = str_replace("'", "''", $this->phpBinary());
        $artisan = str_replace("'", "''", base_path('artisan'));
        $outLog = str_replace("'", "''", $this->outLogPath($workspaceId));
        $errLog = str_replace("'", "''", $this->errLogPath($workspaceId));

        $args = [$artisan, 'fast2x:run', $workspaceId];
        $argList = implode(',', array_map(
            static fn (string $arg) => "'".str_replace("'", "''", $arg)."'",
            $args
        ));

        $script = "\$p = Start-Process -FilePath '{$php}' -ArgumentList {$argList} -WorkingDirectory '".str_replace("'", "''", base_path())."' -WindowStyle Hidden -RedirectStandardOutput '{$outLog}' -RedirectStandardError '{$errLog}' -PassThru; \$p.Id";
        $command = 'powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -Command "'.str_replace('"', '\"', $script).'"';
        $output = $this->runShellCommand($command);

        return (int) trim((string) $output);
    }

    private function spawnUnix(string $workspaceId): int
    {
        $php = escapeshellarg($this->phpBinary());
        $artisan = escapeshellarg(base_path('artisan'));
        $wsArg = escapeshellarg($workspaceId);
        $outLog = escapeshellarg($this->outLogPath($workspaceId));
        $errLog = escapeshellarg($this->errLogPath($workspaceId));
        $cwd = escapeshellarg(base_path());

        $shellCmd = "nohup {$php} {$artisan} fast2x:run {$wsArg} >> {$outLog} 2>> {$errLog} & echo \$!";

        if (\function_exists('shell_exec')) {
            $output = $this->runShellCommand('bash -lc '.escapeshellarg("cd {$cwd} && {$shellCmd}"));

            return (int) trim((string) $output);
        }

        if (\function_exists('exec')) {
            $result = [];
            \exec('bash -c '.escapeshellarg($shellCmd), $result);

            return (int) trim(implode('', $result));
        }

        if (\function_exists('proc_open')) {
            return $this->spawnUnixViaProc($shellCmd);
        }

        return 0;
    }

    private function spawnUnixViaProc(string $shellCmd): int
    {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];
        $pipes = [];
        $process = \proc_open(['/bin/bash', '-c', $shellCmd], $descriptors, $pipes, base_path());

        if (! \is_resource($process)) {
            return 0;
        }

        $pidStr = \fgets($pipes[1]);
        \fclose($pipes[1]);
        \proc_close($process);

        return (int) trim((string) $pidStr);
    }

    private function runShellCommand(string $command): ?string
    {
        if (\function_exists('shell_exec')) {
            return \shell_exec($command);
        }

        if (\function_exists('exec')) {
            $output = [];
            \exec($command, $output);

            return $output !== [] ? implode("\n", $output) : null;
        }

        return null;
    }

    private function ensureRuntimePaths(): void
    {
        File::ensureDirectoryExists(dirname($this->metaPath('placeholder')));
        File::ensureDirectoryExists(dirname($this->outLogPath('placeholder')));
    }

    private function metaPath(string $workspaceId): string
    {
        return storage_path('app/runtime/fast2x/'.$this->safeName($workspaceId).'.json');
    }

    private function outLogPath(string $workspaceId): string
    {
        return storage_path('logs/fast2x/'.$this->safeName($workspaceId).'.log');
    }

    private function errLogPath(string $workspaceId): string
    {
        return storage_path('logs/fast2x/'.$this->safeName($workspaceId).'.error.log');
    }

    private function safeName(string $workspaceId): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '_', $workspaceId) ?: 'unknown';
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function storeMeta(string $workspaceId, array $meta): void
    {
        $this->ensureRuntimePaths();
        File::put($this->metaPath($workspaceId), json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function clearMeta(string $workspaceId): void
    {
        $path = $this->metaPath($workspaceId);
        if (File::exists($path)) {
            File::delete($path);
        }
    }

    private function commandLine(string $workspaceId): string
    {
        return trim(sprintf('%s %s fast2x:run %s', $this->phpBinary(), base_path('artisan'), $workspaceId));
    }

    private function phpBinary(): string
    {
        $configured = trim((string) config('fast2x.php_binary', ''));
        if ($configured !== '') {
            return $configured;
        }

        $binary = PHP_BINARY ?: 'php';
        $basename = strtolower(basename(str_replace('\\', '/', $binary)));
        if (! in_array($basename, ['php-fpm', 'php-fpm.exe', 'php-cgi', 'php-cgi.exe'], true)) {
            return $binary;
        }

        foreach (['/opt/cpanel/ea-php84/root/usr/bin/php', '/opt/cpanel/ea-php83/root/usr/bin/php', '/usr/local/bin/ea-php82', '/usr/local/bin/php', '/usr/bin/php'] as $path) {
            if (is_file($path) && is_executable($path)) {
                return $path;
            }
        }

        return 'php';
    }
}
