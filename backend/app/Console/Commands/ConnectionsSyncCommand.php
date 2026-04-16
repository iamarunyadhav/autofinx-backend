<?php

namespace App\Console\Commands;

use App\Support\ExchangeSyncService;
use Illuminate\Console\Command;

class ConnectionsSyncCommand extends Command
{
    protected $signature = 'connections:sync 
        {--workspace= : Restrict sync to a workspace UUID} 
        {--connection= : Restrict sync to a single connection UUID}';

    protected $description = 'Sync enabled exchange connections and refresh balances/positions.';

    public function handle(ExchangeSyncService $syncService): int
    {
        $workspace = $this->option('workspace') ?: null;
        $connection = $this->option('connection') ?: null;

        $results = $syncService->syncAll($workspace, $connection);

        if (empty($results)) {
            $this->info('No enabled connections found for sync.');
            return Command::SUCCESS;
        }

        $this->table(
            ['Workspace', 'Connection', 'Currencies', 'Positions', 'Synced At'],
            collect($results)->map(function ($result) {
                return [
                    $result['workspace_id'],
                    $result['connection_id'],
                    collect($result['balances'])->pluck('currency')->join(', '),
                    collect($result['positions'])->pluck('symbol')->join(', '),
                    $result['synced_at'],
                ];
            })
        );

        return Command::SUCCESS;
    }
}
