<?php

namespace App\Console\Commands;

use App\Domain\Ml\Training\MlDoctor;
use Illuminate\Console\Command;

/**
 * Checks the ML shadow pipeline end to end and, with --fix, applies only the
 * safe repairs (see MlDoctor). Exit code: 0 clean, 1 warnings, 2 failures.
 * It never touches live trading and never kills or restarts anything.
 */
final class MlDoctorCommand extends Command
{
    protected $signature = 'ml:doctor {--fix : Apply the safe repairs (stuck TRAINING rows, dead runs, orphaned locks)}';

    protected $description = 'Check the ML shadow pipeline (config, processes, registry, locks, queue, data, host) and optionally repair what is safe.';

    public function handle(MlDoctor $doctor): int
    {
        $findings = $doctor->run((bool) $this->option('fix'));

        $worst = 0;
        $counts = [MlDoctor::OK => 0, MlDoctor::INFO => 0, MlDoctor::FIXED => 0, MlDoctor::WARN => 0, MlDoctor::FAIL => 0];
        $section = null;
        foreach ($findings as $finding) {
            if ($finding['section'] !== $section) {
                $section = $finding['section'];
                $this->newLine();
                $this->line("== {$section}");
            }
            $counts[$finding['level']]++;
            $worst = max($worst, match ($finding['level']) {
                MlDoctor::WARN => 1,
                MlDoctor::FAIL => 2,
                default => 0,
            });
            $tag = match ($finding['level']) {
                MlDoctor::OK => '<fg=green> OK  </>',
                MlDoctor::FIXED => '<fg=cyan>FIXED</>',
                MlDoctor::INFO => 'INFO ',
                MlDoctor::WARN => '<fg=yellow>WARN </>',
                default => '<fg=red>FAIL </>',
            };
            $this->line("  {$tag} {$finding['message']}");
        }

        $this->newLine();
        $this->line(sprintf('ml:doctor: %d OK, %d fixed, %d warn, %d fail%s', $counts[MlDoctor::OK], $counts[MlDoctor::FIXED], $counts[MlDoctor::WARN], $counts[MlDoctor::FAIL],
            $this->option('fix') ? '' : ' (read-only; add --fix to repair)'));

        return $worst;
    }
}
