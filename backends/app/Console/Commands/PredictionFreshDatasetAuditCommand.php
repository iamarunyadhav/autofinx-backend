<?php

namespace App\Console\Commands;

use App\Domain\Prediction\FreshData\FreshPredictionDatasetAuditService;
use App\Models\PredictionFreshDataset;
use Illuminate\Console\Command;

final class PredictionFreshDatasetAuditCommand extends Command
{
    protected $signature = 'prediction:fresh-dataset:audit {dataset : Dataset UUID or name} {--json : Emit JSON}';
    protected $description = 'Audit isolated fresh prediction candles before any training is allowed.';

    public function handle(FreshPredictionDatasetAuditService $audits): int
    {
        $token = (string) $this->argument('dataset');
        $dataset = PredictionFreshDataset::query()->where('id', $token)->orWhere('name', $token)->first();
        if (! $dataset) { $this->error('Fresh prediction dataset not found.'); return self::FAILURE; }
        $report = $audits->report($dataset);
        if ($this->option('json')) { $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)); return self::SUCCESS; }
        $this->table(['Symbol', 'Timeframe', 'Rows', 'Required', 'Gaps', 'Misaligned', 'State'], array_map(static fn (array $pair): array => [$pair['symbol'], $pair['timeframe'], $pair['rows'], $pair['minimum_rows'], $pair['gaps'], $pair['misaligned'], $pair['state']], $report['pairs']));
        $this->line("Ready pairs: {$report['ready_pairs']}/{$report['total_pairs']} (shadow-only; never executes trades).");
        return self::SUCCESS;
    }
}
