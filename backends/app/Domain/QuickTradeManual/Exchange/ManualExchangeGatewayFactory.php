<?php

namespace App\Domain\QuickTradeManual\Exchange;

use App\Domain\Exchange\BinanceFuturesClient;
use App\Models\Integration;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/**
 * Builds the exchange gateway for an Integration. Credentials are decoded exactly the way
 * BinanceTimedFuturesProvider::futuresClient() does it (no second decryption path) and the
 * base URL follows the same config_json.environment rule, so manual positions hit the same
 * venue Time-Based Quick Trade would. Bound as a plain class so tests can swap it.
 */
class ManualExchangeGatewayFactory
{
    public function environmentOf(Integration $integration): string
    {
        $config = (array) ($integration->config_json ?? []);

        return strtolower((string) ($config['environment'] ?? 'live')) === 'testnet' ? 'testnet' : 'live';
    }

    public function make(Integration $integration): ManualExchangeGateway
    {
        if (strtolower((string) $integration->provider_key) !== 'binance') {
            throw new RuntimeException('Manual SL/TP mode supports Binance futures only.');
        }

        $credentials = $integration->secrets_encrypted
            ? (json_decode(Crypt::decryptString($integration->secrets_encrypted), true) ?? [])
            : [];
        $apiKey = (string) ($credentials['api_key'] ?? '');
        $apiSecret = (string) ($credentials['api_secret'] ?? '');
        if ($apiKey === '' || $apiSecret === '') {
            throw new RuntimeException('Binance API credentials are missing.');
        }

        $environment = $this->environmentOf($integration);
        $baseUrl = $environment === 'testnet'
            ? (string) config('services.binance.futures_testnet_api_url', 'https://testnet.binancefuture.com')
            : (string) config('services.binance.futures_api_url', 'https://fapi.binance.com');

        return new BinanceManualGateway(
            new BinanceFuturesClient($apiKey, $apiSecret, $baseUrl, 'quick_trade_manual'),
            $environment,
        );
    }
}
