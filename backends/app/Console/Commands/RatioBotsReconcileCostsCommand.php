<?php

namespace App\Console\Commands;

use App\Domain\RatioBot\RatioBotExecutionCostReconciliationService;
use App\Models\RatioBotConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class RatioBotsReconcileCostsCommand extends Command
{
    protected $signature = 'ratio-bots:reconcile-costs {--bot=} {--limit=20}';

    protected $description = 'Read exchange evidence and retry Ratio Bot fee/funding accounting, including stopped bots.';

    public function handle(RatioBotExecutionCostReconciliationService $service): int
    {
        if (! Schema::hasTable('ratio_bot_execution_cost_records')) {
            $this->warn('Cost ledger migration pending. No exchange requests made.');
            return self::FAILURE;
        }
        $configs = RatioBotConfig::query()->select('ratio_bot_configs.*')
            ->leftJoin('ratio_bot_execution_cost_records as cost_report', function ($join) {
                $join->on('cost_report.ratio_bot_config_id', '=', 'ratio_bot_configs.id')->where('cost_report.source_key', 'report');
            })
            ->where('execution_mode', 'live')
            ->whereExists(function ($query) {
                $query->selectRaw('1')->from('orders')
                    ->whereColumn('orders.workspace_id', 'ratio_bot_configs.workspace_id')
                    ->whereColumn('orders.meta->ratio_bot_config_id', 'ratio_bot_configs.id')
                    ->whereNotNull('orders.exchange_order_id');
            })
            ->when($this->option('bot'), fn ($q, $id) => $q->where('ratio_bot_configs.id', $id))
            // Oldest checked first: disabled/closed bots still get late fees; no starvation.
            ->orderByRaw('CASE WHEN cost_report.updated_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('cost_report.updated_at')->orderBy('ratio_bot_configs.id')
            ->limit(max(1, min(100, (int) $this->option('limit'))))->get();
        $pending = 0;
        foreach ($configs as $config) {
            $report = $service->reconcile($config);
            $source = $report['cost_source'] ?? 'unavailable';
            $pending += $source === 'real' ? 0 : 1;
            $this->line($config->id.': '.$source.($report['reason'] ?? false ? ' — '.$report['reason'] : ''));
        }
        $this->info('Checked '.$configs->count().' bot(s); '.$pending.' require retry/review.');
        return self::SUCCESS;
    }
}
