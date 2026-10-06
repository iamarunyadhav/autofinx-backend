<?php

namespace App\Domain\Ml\Training;

/**
 * Host gauges for the dashboard, from /proc and disk_free_space only (no
 * shell, no exec). Everything is null-safe: on a non-Linux host (the Windows
 * dev box) cpu/load/memory/swap are null and health is "ok" with a reason.
 */
class TrainingSystemStats
{
    public function __construct(private readonly string $procRoot = '/proc', private readonly ?string $diskPath = null) {}

    /** @return array<string, mixed> */
    public function host(): array
    {
        $linux = is_readable($this->procRoot.'/meminfo') && is_readable($this->procRoot.'/loadavg');
        $mem = $linux ? $this->meminfo() : null;

        return [
            'cores' => $linux ? $this->cores() : null,
            'load' => $linux ? $this->load() : null,
            'memory' => $mem ? $this->memory($mem) : null,
            'swap' => $mem ? $this->swap($mem) : null,
            'disk' => $this->disk(),
        ];
    }

    /**
     * @param  array<string, mixed>  $host
     * @return array{level: string, reasons: list<string>}
     */
    public function health(array $host): array
    {
        if ($host['load'] === null && $host['memory'] === null) {
            return ['level' => 'ok', 'reasons' => ['Host metrics are only available on Linux (/proc); not reported on this platform.']];
        }
        $t = $this->thresholds();
        $level = 0;
        $reasons = [];
        $raise = function (int $to, string $reason) use (&$level, &$reasons): void {
            $level = max($level, $to);
            $reasons[] = $reason;
        };

        if ($host['load'] !== null && ($host['cores'] ?? 0) > 0) {
            $perCore = round($host['load']['m5'] / $host['cores'], 2);
            if ($perCore >= $t['load_per_core_critical']) {
                $raise(2, "Load is {$perCore} per core (5 min average), critical from {$t['load_per_core_critical']}.");
            } elseif ($perCore >= $t['load_per_core_warn']) {
                $raise(1, "Load is {$perCore} per core (5 min average), warning from {$t['load_per_core_warn']}.");
            }
        }
        if ($host['swap'] !== null && ($host['swap']['total_mb'] ?? 0) > 0) {
            $used = $host['swap']['used_pct'];
            if ($used >= $t['swap_used_pct_critical']) {
                $raise(2, "Swap is {$used}% used, critical from {$t['swap_used_pct_critical']}%.");
            } elseif ($used >= $t['swap_used_pct_warn']) {
                $raise(1, "Swap is {$used}% used, warning from {$t['swap_used_pct_warn']}%.");
            }
        }
        if ($host['memory'] !== null) {
            $used = $host['memory']['used_pct'];
            if ($used >= $t['memory_used_pct_critical']) {
                $raise(2, "Memory is {$used}% used, critical from {$t['memory_used_pct_critical']}%.");
            } elseif ($used >= $t['memory_used_pct_warn']) {
                $raise(1, "Memory is {$used}% used, warning from {$t['memory_used_pct_warn']}%.");
            }
        }
        if ($host['disk'] !== null && $host['disk']['free_gb'] < $t['disk_free_gb_warn']) {
            $raise(1, "Only {$host['disk']['free_gb']} GB of disk is free (warning below {$t['disk_free_gb_warn']} GB).");
        }

        return ['level' => ['ok', 'warning', 'critical'][$level], 'reasons' => $reasons];
    }

    /** @return array<string, int|float> */
    public function thresholds(): array
    {
        $t = (array) config('ml.training.system_thresholds', []);

        return [
            'load_per_core_warn' => (float) ($t['load_per_core_warn'] ?? 1.5), 'load_per_core_critical' => (float) ($t['load_per_core_critical'] ?? 3.0),
            'swap_used_pct_warn' => (float) ($t['swap_used_pct_warn'] ?? 50), 'swap_used_pct_critical' => (float) ($t['swap_used_pct_critical'] ?? 80),
            'memory_used_pct_warn' => (float) ($t['memory_used_pct_warn'] ?? 85), 'memory_used_pct_critical' => (float) ($t['memory_used_pct_critical'] ?? 95),
            'disk_free_gb_warn' => (float) ($t['disk_free_gb_warn'] ?? 5),
        ];
    }

    private function cores(): ?int
    {
        $cpuinfo = @file_get_contents($this->procRoot.'/cpuinfo');
        if ($cpuinfo === false) {
            return null;
        }
        $count = preg_match_all('/^processor\s*:/m', $cpuinfo);

        return $count > 0 ? $count : null;
    }

    /** @return array{m1: float, m5: float, m15: float}|null */
    private function load(): ?array
    {
        $parts = preg_split('/\s+/', trim((string) @file_get_contents($this->procRoot.'/loadavg'))) ?: [];
        if (count($parts) < 3 || ! is_numeric($parts[0])) {
            return null;
        }

        return ['m1' => (float) $parts[0], 'm5' => (float) $parts[1], 'm15' => (float) $parts[2]];
    }

    /** @return array<string, int>|null kB values */
    private function meminfo(): ?array
    {
        $raw = @file_get_contents($this->procRoot.'/meminfo');
        if ($raw === false || ! preg_match_all('/^(\w+):\s+(\d+)\s*kB/m', $raw, $m, PREG_SET_ORDER)) {
            return null;
        }
        $info = [];
        foreach ($m as $row) {
            $info[$row[1]] = (int) $row[2];
        }

        return isset($info['MemTotal']) ? $info : null;
    }

    /** @param array<string, int> $info */
    private function memory(array $info): array
    {
        $total = $info['MemTotal'];
        $available = $info['MemAvailable'] ?? (($info['MemFree'] ?? 0) + ($info['Buffers'] ?? 0) + ($info['Cached'] ?? 0));
        $available = min($total, max(0, $available));

        return [
            'total_mb' => (int) round($total / 1024), 'available_mb' => (int) round($available / 1024),
            'used_pct' => $total > 0 ? round(($total - $available) / $total * 100, 1) : 0.0,
        ];
    }

    /** @param array<string, int> $info */
    private function swap(array $info): array
    {
        $total = $info['SwapTotal'] ?? 0;
        $used = max(0, $total - ($info['SwapFree'] ?? 0));

        return [
            'total_mb' => (int) round($total / 1024), 'used_mb' => (int) round($used / 1024),
            'used_pct' => $total > 0 ? round($used / $total * 100, 1) : 0.0,
        ];
    }

    /** @return array{path: string, free_gb: float, used_pct: float}|null */
    private function disk(): ?array
    {
        $path = $this->diskPath ?? storage_path();
        $free = @disk_free_space($path);
        $total = @disk_total_space($path);
        if ($free === false || $total === false || $total <= 0) {
            return null;
        }

        return ['path' => $path, 'free_gb' => round($free / 1073741824, 1), 'used_pct' => round(($total - $free) / $total * 100, 1)];
    }
}
