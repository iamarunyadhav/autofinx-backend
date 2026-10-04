<?php

namespace App\Console\Commands;

use App\Domain\Ml\Shadow\FeatureLabelService;
use App\Domain\Ml\Shadow\MlShadowTrainer;
use Throwable;

final class MlTrainCompareWindowsCommand extends MlShadowCommand
{
    protected $signature = 'ml:train:compare-windows {symbol} {horizon : Horizon in minutes}
        {--windows=12,24,36,60 : Fitting windows in months (0 = all history)}
        {--half-life= : Recency half-life in days (default ml.training.recency_half_life_days; 0 = equal weights)}';

    protected $description = 'Experiment: score several training-history windows on the SAME holdout. Registers/promotes nothing; champion untouched.';

    public function handle(MlShadowTrainer $trainer, FeatureLabelService $features): int
    {
        $symbol = strtoupper((string) $this->argument('symbol'));
        $horizon = (int) $this->argument('horizon');
        if (! in_array($symbol, (array) config('ml.symbols', []), true) || ! in_array($horizon, $features->horizons(), true)) {
            $this->error('Symbol/horizon not configured in config/ml.php (horizons: '.implode(',', $features->horizons()).').');

            return self::FAILURE;
        }
        $windows = array_values(array_unique(array_map('intval', array_filter(array_map('trim', explode(',', (string) $this->option('windows'))), 'strlen'))));
        $halfLife = $this->option('half-life') !== null ? (float) $this->option('half-life') : null;

        try {
            $report = $trainer->compareWindows($symbol, $horizon, $windows, $halfLife);
        } catch (Throwable $exception) {
            $this->error("{$symbol} h{$horizon}: experiment failed - {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info("{$symbol} h{$horizon}: {$report['rows']} rows, half-life {$report['recency_half_life_days']}d, same holdout for every window");
        $this->table(
            ['Window', 'Fitted from', 'Trades', 'Net %', 'Avg net %', 'Win %', 't', 'Folds +', 'Gate'],
            array_map(fn (array $r) => isset($r['error'])
                ? [$this->label($r['window_months']), 'error', '', '', '', '', '', '', mb_substr($r['error'], 0, 60)]
                : [
                    $this->label($r['window_months']), substr($r['fitted_from'], 0, 10),
                    $r['holdout']['trades'] ?? 0, $this->num($r['holdout']['total_net_pct'] ?? null, 2), $this->num($r['holdout']['average_net_pct'] ?? null, 3),
                    $this->num($r['holdout']['net_win_rate_pct'] ?? null, 1), $this->num($r['holdout']['t_stat'] ?? null, 2), $r['positive_folds'],
                    $r['would_pass_gate'] ? 'PASS' : 'fail: '.mb_substr((string) $r['gate_reason'], 0, 40),
                ], $report['results'])
        );
        $this->line('Pick the window with the best avg net AND t-stat AND positive folds, then set AUTOFINX_ML_TRAINING_WINDOW_MONTHS.');

        return self::SUCCESS;
    }

    private function label(int $months): string
    {
        return $months === 0 ? 'all' : $months.'m';
    }

    private function num(mixed $value, int $digits): string
    {
        return $value === null ? '—' : number_format((float) $value, $digits);
    }
}
