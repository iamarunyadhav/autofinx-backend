<?php

namespace App\Console\Commands;

use App\Domain\Ops\TradingRuntimeHealthService;
use Illuminate\Console\Command;

class QuickTradesDoctorCommand extends Command
{
    protected $signature = 'quick-trades:doctor {--workspace=} {--json}';

    protected $description = 'Inspect Quick Trade runtime readiness and expired open trades.';

    public function handle(TradingRuntimeHealthService $runtime): int
    {
        $workspaceId = $this->option('workspace') ? (string) $this->option('workspace') : null;
        $snapshot = $runtime->snapshot($workspaceId);
        $quick = $snapshot['quick_trade'] ?? [];

        if ($this->option('json')) {
            $this->line(json_encode([
                'overall' => $snapshot['overall'] ?? 'red',
                'workspace_id' => $workspaceId,
                'quick_trade' => $quick,
                'reasons' => $snapshot['reasons'] ?? [],
            ], JSON_PRETTY_PRINT));

            return (($quick['settler'] ?? 'red') === 'green' && (int) ($quick['open_expired_count'] ?? 0) === 0)
                ? self::SUCCESS
                : self::FAILURE;
        }

        $this->info('Quick Trade Doctor');
        $this->line('Overall: '.($snapshot['overall'] ?? 'red'));
        $this->line('Settler: '.($quick['settler'] ?? 'red'));
        $this->line('Settler age: '.(($quick['settler_age_seconds'] ?? null) === null ? 'unknown' : $quick['settler_age_seconds'].'s'));
        $this->line('Expired open trades: '.(int) ($quick['open_expired_count'] ?? 0));

        foreach (($snapshot['reasons'] ?? []) as $reason) {
            if (str_starts_with((string) $reason, 'quick_trade')) {
                $this->warn(' - '.$reason);
            }
        }

        return (($quick['settler'] ?? 'red') === 'green' && (int) ($quick['open_expired_count'] ?? 0) === 0)
            ? self::SUCCESS
            : self::FAILURE;
    }
}
