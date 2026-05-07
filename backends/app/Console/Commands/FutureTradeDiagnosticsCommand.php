<?php

namespace App\Console\Commands;

use App\Domain\FutureTrade\FutureTradeBotService;
use App\Models\FutureTradeBotConfig;
use Illuminate\Console\Command;

class FutureTradeDiagnosticsCommand extends Command
{
    protected $signature = 'future-trade:diagnostics {workspace_id?} {--persist}';
    protected $description = 'Inspect FutureTrade runtime, wallet, scheduler, and server blockers for one workspace or all enabled workspaces.';

    public function handle(FutureTradeBotService $bot): int
    {
        $workspaceId = $this->argument('workspace_id');
        $persist = (bool) $this->option('persist');

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
                $this->line(sprintf(
                    '[%s] %s | bot blockers=%d | server=%s | wallet=%s',
                    $config->workspace_id,
                    strtoupper((string) ($diagnostics['state'] ?? 'unknown')),
                    count((array) ($diagnostics['botBlockers'] ?? [])),
                    (string) data_get($diagnostics, 'server.state', 'unknown'),
                    (string) data_get($diagnostics, 'wallet.state', 'unknown'),
                ));
            } catch (\Throwable $e) {
                $this->error(sprintf('[%s] diagnostics failed: %s', $config->workspace_id, $e->getMessage()));
            }
        }

        return self::SUCCESS;
    }
}
