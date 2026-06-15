<?php

namespace App\Support;

use App\Domain\Ops\KillSwitchService;
use App\Models\Ledger;
use App\Models\LedgerTransaction;
use Illuminate\Support\Str;

class RiskGuardService
{
    public function __construct(private readonly KillSwitchService $killSwitch)
    {
    }

    public function enforceOrFail(string $workspaceId): void
    {
        if ($this->killSwitch->isActive($workspaceId)) {
            abort(403, 'Global kill switch active');
        }

        $ledger = Ledger::firstOrCreate(
            ['workspace_id' => $workspaceId],
            ['id' => (string) Str::uuid(), 'balance' => 0, 'meta' => ['starting_balance' => 0]]
        );

        if (!isset($ledger->meta['starting_balance'])) {
            $meta = $ledger->meta ?? [];
            $meta['starting_balance'] = $ledger->balance ?? 0;
            $ledger->meta = $meta;
            $ledger->save();
        }

        $this->checkDailyLoss($ledger);
        $this->checkDrawdown($ledger);
    }

    /**
     * Reset the drawdown baseline for a workspace to the supplied balance (or the
     * current ledger balance). Call this when a live account is connected so the
     * drawdown guard measures against the real account equity instead of a stale
     * seeded baseline — otherwise the very first live order false-trips the guard.
     */
    public function rebaseline(string $workspaceId, ?float $balance = null): void
    {
        $ledger = Ledger::firstOrCreate(
            ['workspace_id' => $workspaceId],
            ['id' => (string) Str::uuid(), 'balance' => 0, 'meta' => ['starting_balance' => 0]]
        );

        if ($balance !== null) {
            $ledger->balance = $balance;
        }

        $meta = $ledger->meta ?? [];
        $meta['starting_balance'] = (float) ($ledger->balance ?? 0);
        $meta['baselined_at'] = now()->toIso8601String();
        $ledger->meta = $meta;
        $ledger->save();
    }

    private function checkDailyLoss(Ledger $ledger): void
    {
        $limit = (float) config('risk.max_daily_loss', 0);
        if ($limit <= 0) {
            return;
        }

        $todayLoss = LedgerTransaction::where('ledger_id', $ledger->id)
            ->whereDate('occurred_at', now()->toDateString())
            ->sum('amount');

        // Buys are negative; treat negative sum as loss
        if ($todayLoss < -1 * $limit) {
            abort(403, 'Daily loss limit reached');
        }
    }

    private function checkDrawdown(Ledger $ledger): void
    {
        $maxDd = (float) config('risk.max_drawdown_percent', 0);
        if ($maxDd <= 0) {
            return;
        }

        $start = (float) ($ledger->meta['starting_balance'] ?? $ledger->balance ?? 0);
        if ($start <= 0) {
            return;
        }

        $ddPercent = (($start - ($ledger->balance ?? 0)) / $start) * 100;
        if ($ddPercent >= $maxDd) {
            abort(403, 'Max drawdown reached');
        }
    }
}
