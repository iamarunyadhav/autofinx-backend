<?php

namespace App\Domain\Ml\Training;

use App\Models\SchedulerRun;

/**
 * Last scheduler_runs row for every scheduled ml:* command. Note
 * `queue:maintain-backlog` prunes scheduler_runs older than 2 hours, so a daily
 * command (ml:retrain:check) is usually "never" here; that is not an alert.
 */
class TrainingPipelineHeartbeats
{
    /** command => expected period in seconds */
    public const COMMANDS = [
        'ml:candles:sync' => 60, 'ml:shadow:predict' => 60, 'ml:autopilot:run' => 60,
        'ml:labels:mature' => 300, 'ml:shadow:evaluate' => 300,
        'ml:trade-feedback:classify' => 3600, 'ml:funding:sync' => 3600,
        'ml:retrain:check' => 86400, 'ml:training:reap' => 120,
    ];

    /** @return list<array{command:string, last_started_at:?string, last_status:?string, age_seconds:?int, state:string}> */
    public function all(): array
    {
        $now = now()->getTimestamp();
        $rows = [];
        foreach (self::COMMANDS as $command => $period) {
            $run = SchedulerRun::query()->where('command', $command)->orderByDesc('started_at')->first(['command', 'started_at', 'status']);
            $age = $run?->started_at ? max(0, $now - $run->started_at->getTimestamp()) : null;
            $rows[] = [
                'command' => $command,
                'last_started_at' => $run?->started_at?->toIso8601String(),
                'last_status' => $run?->status,
                'age_seconds' => $age,
                'state' => $age === null ? 'never' : ($age > max(180, $period * 3) ? 'late' : 'ok'),
            ];
        }

        return $rows;
    }
}
