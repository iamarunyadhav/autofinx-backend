<?php

namespace App\Domain\Fast2X;

use App\Models\Fast2XConfig;
use App\Models\Fast2XTrade;

class TradeLedgerService
{
    public function record(Fast2XConfig $config, array $data): Fast2XTrade
    {
        return $config->trades()->create(array_merge([
            'bot_name' => 'Fast2X',
            'symbol' => $config->normalizedSymbol(),
            'mode' => strtoupper($config->executionMode()),
            'status' => 'FILLED',
        ], $data));
    }
}
