<?php

namespace App\Console\Commands;

use App\Models\Schedule;
use App\Support\SchedulerEngine;
use Illuminate\Console\Command;

class ScheduleSimulateCommand extends Command
{
    protected $signature = 'schedule:simulate {scheduleId}';
    protected $description = 'Simulate a single schedule execution decision now.';

    public function handle(): int
    {
        $schedule = Schedule::findOrFail($this->argument('scheduleId'));
        $result = SchedulerEngine::evaluate($schedule);
        $this->info(json_encode($result, JSON_PRETTY_PRINT));
        return Command::SUCCESS;
    }
}
