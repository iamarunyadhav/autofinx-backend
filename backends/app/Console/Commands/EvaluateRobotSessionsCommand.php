<?php

namespace App\Console\Commands;

use App\Domain\Trading\RobotSessionService;
use Illuminate\Console\Command;

class EvaluateRobotSessionsCommand extends Command
{
    protected $signature = 'robot-sessions:evaluate {--session=} {--limit=50}';

    protected $description = 'Evaluate running paper robot sessions and place quick trades when signals align.';

    public function handle(RobotSessionService $sessions): int
    {
        $results = $sessions->evaluateActiveSessions(
            $this->option('session') ?: null,
            (int) $this->option('limit')
        );

        $this->info('Evaluated robot sessions: ' . count($results));

        return self::SUCCESS;
    }
}
