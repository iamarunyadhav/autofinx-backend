<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Throwable;

class RuntimeRepairCommand extends Command
{
    protected $signature = 'runtime:repair
        {--attempts=1 : Number of repair attempts}
        {--sleep=3 : Seconds between attempts}
        {--no-script : Use Artisan fallback instead of the shell script}';

    protected $description = 'Check and repair AutoFinX runtime workers, queues, market feed, and robot evaluator.';

    public function handle(): int
    {
        $attempts = max(1, (int) $this->option('attempts'));
        $sleep = max(1, (int) $this->option('sleep'));
        $script = base_path('scripts/robot-runtime-doctor.sh');

        if (! (bool) $this->option('no-script') && is_file($script)) {
            @chmod($script, 0755);
            $process = new Process(['bash', $script, '--repair', '--attempts='.$attempts, '--sleep='.$sleep], base_path(), null, null, 120);
            $process->run(function ($type, $buffer): void {
                $this->output->write($buffer);
            });

            return $process->isSuccessful() ? self::SUCCESS : self::FAILURE;
        }

        $failed = false;
        foreach ([
            ['queue:restart', []],
            ['market:ws-consume', ['--once' => true, '--market-type' => 'futures', '--store-ohlc' => true, '--ohlc-intervals' => '1m,5m,15m', '--no-dispatch' => true]],
            ['robot-sessions:evaluate', ['--limit' => 50]],
            ['future-trade:runtime-status', []],
        ] as [$command, $args]) {
            try {
                $this->line('$ php artisan '.$command);
                Artisan::call($command, $args);
                $this->output->write(Artisan::output());
            } catch (Throwable $e) {
                $failed = true;
                $this->error($command.' failed: '.$e->getMessage());
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
