<?php

namespace Services\ExecutionService\Adapters;

use App\Models\ExchangeConnection;
use Illuminate\Contracts\Container\Container;
use App\Support\ExchangeSecretVault;

class ExchangeAdapterFactory
{
    public function __construct(private Container $app) {}

    public function makeFromConnection(ExchangeConnection $connection): ExchangeAdapter
    {
        $vault = app(ExchangeSecretVault::class);
        $provider = strtolower($connection->provider);
        $params = [
            'apiKey' => $vault->decrypt($connection->api_key_encrypted),
            'apiSecret' => $vault->decrypt($connection->api_secret_encrypted),
            'isTestnet' => $connection->is_testnet,
            'isSandbox' => $connection->is_testnet,
        ];

        return match ($provider) {
            'binance' => $this->app->makeWith(BinanceExchangeAdapter::class, $params),
            'coinbase', 'coinbasepro', 'coinbase_pro' => $this->app->makeWith(CoinbaseExchangeAdapter::class, $params),
            default => $this->app->makeWith(BinanceExchangeAdapter::class, $params), // fallback to binance signature shape
        };
    }
}
