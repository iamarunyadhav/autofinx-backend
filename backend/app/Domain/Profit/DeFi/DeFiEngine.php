<?php

namespace App\Domain\Profit\DeFi;

use App\Models\DefiPosition;
use Illuminate\Support\Str;

class DeFiEngine
{
    public function simulate(string $workspaceId, string $protocol = 'sim', string $asset = 'USDC', float $apyBps = 500): DefiPosition
    {
        $pos = DefiPosition::firstOrCreate(
            ['workspace_id' => $workspaceId, 'protocol' => $protocol, 'asset' => $asset],
            ['id' => (string) Str::uuid(), 'amount' => 0, 'apy_bps' => $apyBps]
        );
        return $pos;
    }
}
