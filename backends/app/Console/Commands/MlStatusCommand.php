<?php

namespace App\Console\Commands;

use App\Domain\Ml\Shadow\LearningStatusService;

final class MlStatusCommand extends MlShadowCommand
{
    protected $signature = 'ml:status {--symbol=*} {--json}';

    protected $description = 'Learning-loop progress: candles, rejections, labels, training, champion vs candidate, live evaluation.';

    public function handle(LearningStatusService $status): int
    {
        foreach ($this->symbols() as $symbol) {
            $report = $status->status($symbol);
            if ($this->option('json')) {
                $this->line((string) json_encode($report, JSON_PRETTY_PRINT));

                continue;
            }
            $c = $report['candles'];
            $this->info("{$symbol} ({$report['base_timeframe']}) enabled=".($report['enabled'] ? 'yes' : 'no').' mode=shadow_only');
            $this->line("  candles {$c['collected']} [{$c['earliest_open']} -> {$c['latest_close']}] age {$c['latest_close_age_seconds']}s; rejected {$c['rejected']} ".json_encode($c['rejections_by_reason']));
            foreach ($report['horizons'] as $h) {
                $this->line(sprintf('  h%-3d labels pending %d mature %d | retrain: %s (new %d/%d) | champion %s | last training %s %s',
                    $h['horizon_minutes'], $h['labels']['pending'], $h['labels']['mature'], $h['retrain']['reason'],
                    $h['retrain']['new_mature'], $h['retrain']['required'], $h['champion']['model_version'] ?? 'none',
                    $h['last_training']['status'] ?? '-', $h['last_training']['reason'] ?? ''));
                $f = $h['live_evaluation']['forecast'];
                $d = $h['live_evaluation']['decision'];
                $this->line(sprintf('       live forecast %s n=%d hit=%s mae=%s | decisions %s n=%d win=%s avg_net=%s',
                    $f['status'], $f['samples'], $f['direction_hit_rate_pct'] ?? '-', $f['mae_pct'] ?? '-',
                    $d['status'], $d['samples'], $d['net_win_rate_pct'] ?? '-', $d['average_net_pct'] ?? '-'));
            }
        }

        return self::SUCCESS;
    }
}
