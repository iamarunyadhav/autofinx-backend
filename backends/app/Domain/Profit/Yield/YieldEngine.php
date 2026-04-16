<?php

namespace App\Domain\Profit\Yield;

use App\Models\YieldPosition;
use App\Models\Ledger;
use App\Models\LedgerTransaction;
use Illuminate\Support\Str;

class YieldEngine
{
    public function accrue(string $workspaceId, float $aprBps = 800, string $asset = 'USDT'): YieldPosition
    {
        $position = YieldPosition::firstOrCreate(
            ['workspace_id' => $workspaceId, 'asset' => $asset],
            ['id' => (string) Str::uuid(), 'amount' => 0, 'apr_bps' => $aprBps]
        );

        $yieldPerDay = ($position->amount * ($position->apr_bps / 10000)) / 365;
        if ($yieldPerDay <= 0) {
            return $position;
        }

        $ledger = Ledger::firstOrCreate(['workspace_id' => $workspaceId], ['id' => (string) Str::uuid(), 'balance' => 0]);
        $ledger->balance += $yieldPerDay;
        $ledger->save();

        LedgerTransaction::create([
            'id' => (string) Str::uuid(),
            'ledger_id' => $ledger->id,
            'type' => 'yield_income',
            'amount' => $yieldPerDay,
            'meta' => ['asset' => $asset, 'apr_bps' => $position->apr_bps],
            'occurred_at' => now(),
        ]);

        return $position;
    }
}
