<?php

namespace App\Console\Commands;

use App\Domain\FutureTrade\FutureTradeBotService;
use App\Models\FutureTradeBotConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class FutureTradeDiagnosticsCommand extends Command
{
    protected $signature = 'future-trade:diagnostics {workspace_id?} {--persist} {--json}';

    protected $description = 'Inspect FutureTrade runtime, wallet, scheduler, and server blockers for one workspace or all enabled workspaces.';

    public function handle(FutureTradeBotService $bot): int
    {
        $workspaceId = $this->argument('workspace_id');
        $persist = (bool) $this->option('persist');
        $lock = Cache::lock('autofinx:runtime:future-trade-diagnostics'.($workspaceId ? ':'.$workspaceId : ''), 300);
        if (! $lock->get()) {
            $this->warn('[FT DIAGNOSTICS SKIPPED] another diagnostics run is already active');

            return self::SUCCESS;
        }

        try {
            if ($workspaceId) {
                $configs = collect([$bot->getOrCreateConfig((string) $workspaceId)]);
            } else {
                $configs = FutureTradeBotConfig::query()
                    ->where('enabled', true)
                    ->get();
            }

            if ($configs->isEmpty()) {
                $this->warn('No enabled FutureTrade workspaces found.');

                return self::SUCCESS;
            }

            foreach ($configs as $config) {
                try {
                    $diagnostics = $bot->diagnosticsPayload($config->fresh('state'), $persist);

                    if ((bool) $this->option('json')) {
                        $this->line(json_encode($diagnostics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                        continue;
                    }

                    $this->line(sprintf(
                        '[%s] %s | bot blockers=%d | server=%s | wallet=%s | trade=%s',
                        $config->workspace_id,
                        strtoupper((string) ($diagnostics['state'] ?? 'unknown')),
                        count((array) ($diagnostics['botBlockers'] ?? [])),
                        (string) data_get($diagnostics, 'server.state', 'unknown'),
                        (string) data_get($diagnostics, 'wallet.state', 'unknown'),
                        (string) data_get($diagnostics, 'tradeEligibility.decision', 'unknown'),
                    ));
                    $this->line('  root cause: '.(string) data_get($diagnostics, 'tradeEligibility.summary', '-'));
                    $this->line('  signal source: '.(string) data_get($diagnostics, 'tradeEligibility.signalSource', '-')
                        .' | webhook event: '.(string) data_get($diagnostics, 'tradeEligibility.latestWebhookEvent', '-')
                        .' | reason: '.(string) data_get($diagnostics, 'tradeEligibility.latestWebhookReason', '-'));

                    $blockers = (array) data_get($diagnostics, 'tradeEligibility.blockers', []);
                    $warnings = (array) data_get($diagnostics, 'tradeEligibility.warnings', []);
                    if ($blockers !== []) {
                        $this->line('  blockers: '.implode(', ', $blockers));
                    }
                    if ($warnings !== []) {
                        $this->line('  warnings: '.implode(', ', $warnings));
                    }
                } catch (\Throwable $e) {
                    $this->error(sprintf('[%s] diagnostics failed: %s', $config->workspace_id, $e->getMessage()));
                }
            }

            return self::SUCCESS;
        } finally {
            optional($lock)->release();
        }
    }
}
