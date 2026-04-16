<?php

namespace App\Domain\Trading\Adapters;

use Illuminate\Support\Facades\Http;

class CoinbaseAdapter
{
    public function __construct(private array $credentials)
    {
    }

    public function fetchTicker(string $symbol): ?array
    {
        $resp = Http::get('https://api.exchange.coinbase.com/products/'.strtoupper($symbol).'/ticker');
        return $resp->successful() ? $resp->json() : null;
    }

    public function balances(): array
    {
        // placeholder read-only
        return [];
    }
}
