<?php

namespace App\Domain\Trading;

use App\Domain\Exchange\BinanceFuturesClient;
use App\Domain\Integrations\IntegrationManager;
use App\Models\Integration;
use App\Models\Order;
use App\Models\Position;
use App\Models\Symbol;
use Illuminate\Support\Str;
use RuntimeException;

class FuturesPositionSyncService
{
    public function __construct(
        private readonly FuturesAnomalyService $anomalies,
        private readonly IntegrationManager $integrations,
    ) {
    }

    public function sync(string $workspaceId, Integration $integration, array $config): array
    {
        $client = $this->client($integration, $config);
        $environment = $this->environment($integration, $config);
        $positionModePayload = $client->fetchPositionMode();
        $account = $client->fetchAccountInfo();
        $riskRows = collect($client->fetchPositionRisk());
        $openOrders = collect($client->fetchOpenOrders());
        $isDualSide = (bool) ($positionModePayload['dualSidePosition'] ?? false);

        $positions = $riskRows
            ->filter(fn (array $row) => abs((float) ($row['positionAmt'] ?? 0)) > 0)
            ->map(function (array $row) use ($workspaceId, $config, $isDualSide, $environment) {
                $symbolCode = strtoupper((string) ($row['symbol'] ?? ''));
                $symbol = Symbol::firstOrCreate(['symbol' => $symbolCode], [
                    'id' => (string) Str::uuid(),
                    'type' => 'crypto',
                    'exchange' => 'binance_futures',
                    'metadata' => ['auto_created' => true],
                ]);

                $qty = (float) ($row['positionAmt'] ?? 0);
                $markPrice = (float) ($row['markPrice'] ?? 0);
                $liquidationPrice = (float) ($row['liquidationPrice'] ?? 0);
                $buffer = $this->liquidationBuffer($qty, $markPrice, $liquidationPrice);
                $existing = Position::query()
                    ->where('workspace_id', $workspaceId)
                    ->where('symbol_id', $symbol->id)
                    ->where('market_type', 'futures')
                    ->first();

                $position = Position::updateOrCreate(
                    [
                        'workspace_id' => $workspaceId,
                        'symbol_id' => $symbol->id,
                        'market_type' => 'futures',
                    ],
                    [
                        'id' => $existing?->id ?: (string) Str::uuid(),
                        'asset_class' => 'CRYPTO',
                        'asset_code' => $symbolCode,
                        'quote_currency' => $this->quoteCurrency($symbolCode),
                        'provider' => 'binance',
                        'environment' => $environment,
                        'qty' => $qty,
                        'quantity' => $qty,
                        'avg_entry' => (float) ($row['entryPrice'] ?? 0),
                        'avg_price' => (float) ($row['entryPrice'] ?? 0),
                        'unrealized_pnl' => (float) ($row['unRealizedProfit'] ?? 0),
                        'unrealized_pl' => (float) ($row['unRealizedProfit'] ?? 0),
                        'realized_pnl' => (float) ($existing?->realized_pnl ?? 0),
                        'last_mark_price' => $markPrice,
                        'market_type' => 'futures',
                        'leverage' => (float) ($row['leverage'] ?? 0),
                        'margin_mode' => strtolower((string) ($row['marginType'] ?? 'isolated')),
                        'position_mode' => $isDualSide ? 'hedge' : 'one_way',
                        'liquidation_price' => $liquidationPrice > 0 ? $liquidationPrice : null,
                        'liquidation_buffer_pct' => $buffer,
                        'mark_price_synced_at' => now(),
                    ]
                );

                if ($buffer !== null) {
                    $minBuffer = (float) ($config['min_liquidation_buffer_pct'] ?? 0);
                    if ($minBuffer > 0 && $buffer < $minBuffer) {
                        $this->anomalies->record($workspaceId, 'futures_liquidation_risk', 'Futures liquidation buffer is below the configured minimum.', 'warning', [
                            'symbol' => $symbolCode,
                            'liquidation_buffer_pct' => round($buffer, 4),
                            'threshold_pct' => $minBuffer,
                        ]);
                    }
                }

                return $position;
            })
            ->values();

        $orders = $openOrders->map(function (array $row) use ($workspaceId, $integration, $environment) {
            $symbolCode = strtoupper((string) ($row['symbol'] ?? ''));
            $symbol = Symbol::firstOrCreate(['symbol' => $symbolCode], [
                'id' => (string) Str::uuid(),
                'type' => 'crypto',
                'exchange' => 'binance_futures',
                'metadata' => ['auto_created' => true],
            ]);

            return Order::updateOrCreate(
                [
                    'exchange_order_id' => (string) ($row['orderId'] ?? ''),
                ],
                [
                    'id' => Order::query()->where('exchange_order_id', (string) ($row['orderId'] ?? ''))->value('id') ?: (string) Str::uuid(),
                    'workspace_id' => $workspaceId,
                    'integration_id' => $integration->id,
                    'symbol_id' => $symbol->id,
                    'provider' => 'binance',
                    'exchange' => 'binance_futures',
                    'asset_class' => 'CRYPTO',
                    'asset_code' => $symbolCode,
                    'quote_currency' => $this->quoteCurrency($symbolCode),
                    'side' => strtolower((string) ($row['side'] ?? 'buy')),
                    'type' => strtolower((string) ($row['type'] ?? 'market')),
                    'status' => $this->mapStatus((string) ($row['status'] ?? 'NEW')),
                    'provider_status' => (string) ($row['status'] ?? 'NEW'),
                    'qty' => (float) ($row['origQty'] ?? 0),
                    'quantity' => (float) ($row['origQty'] ?? 0),
                    'requested_qty' => (float) ($row['origQty'] ?? 0),
                    'filled_qty' => (float) ($row['executedQty'] ?? 0),
                    'price' => (float) ($row['avgPrice'] ?? $row['price'] ?? 0),
                    'avg_fill_price' => (float) ($row['avgPrice'] ?? 0),
                    'limit_price' => (float) ($row['price'] ?? 0),
                    'client_order_id' => (string) ($row['clientOrderId'] ?? ''),
                    'reconciliation_state' => Order::RECONCILIATION_PENDING,
                    'provider_synced_at' => now(),
                    'last_sync_source' => 'futures_sync',
                    'meta' => [
                        'mode' => 'futures_'.$environment,
                        'market_type' => 'futures',
                        'environment' => $environment,
                        'reduce_only' => (bool) ($row['reduceOnly'] ?? false),
                        'position_side' => (string) ($row['positionSide'] ?? 'BOTH'),
                        'provider_payload' => $row,
                    ],
                ]
            );
        })->values();

        return [
            'account' => [
                'can_trade' => (bool) ($account['canTrade'] ?? false),
                'total_wallet_balance' => (float) ($account['totalWalletBalance'] ?? 0),
                'available_balance' => (float) ($account['availableBalance'] ?? 0),
                'dual_side_position' => $isDualSide,
            ],
            'positions' => $positions,
            'orders' => $orders,
        ];
    }

    private function client(Integration $integration, array $config): BinanceFuturesClient
    {
        $creds = $this->integrations->decryptSecrets($integration->secrets_encrypted);
        if ($creds === []) {
            throw new RuntimeException('Binance futures credentials are missing.');
        }

        return new BinanceFuturesClient(
            apiKey: (string) ($creds['api_key'] ?? $creds['key'] ?? ''),
            apiSecret: (string) ($creds['api_secret'] ?? $creds['secret'] ?? ''),
            baseUrl: (string) (($this->environment($integration, $config) === 'testnet')
                ? config('services.binance.futures_testnet_api_url', 'https://testnet.binancefuture.com')
                : config('services.binance.futures_api_url', 'https://fapi.binance.com')),
        );
    }

    private function environment(Integration $integration, array $config): string
    {
        $integrationConfig = (array) ($integration->config_json ?? []);
        $environment = strtolower((string) ($integration->environment ?? $integrationConfig['environment'] ?? ''));
        if ($environment === 'live' || $environment === 'testnet') {
            return $environment;
        }

        return (bool) ($config['futures_force_testnet'] ?? true) ? 'testnet' : 'live';
    }

    private function liquidationBuffer(float $qty, float $markPrice, float $liquidationPrice): ?float
    {
        if ($markPrice <= 0 || $liquidationPrice <= 0 || abs($qty) <= 0) {
            return null;
        }

        if ($qty < 0) {
            return (($liquidationPrice - $markPrice) / $markPrice) * 100;
        }

        return (($markPrice - $liquidationPrice) / $markPrice) * 100;
    }

    private function mapStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'NEW' => Order::STATUS_NEW,
            'PARTIALLY_FILLED' => Order::STATUS_PARTIAL,
            'FILLED' => Order::STATUS_FILLED,
            'CANCELED', 'CANCELLED', 'EXPIRED' => Order::STATUS_CANCELLED,
            'REJECTED' => Order::STATUS_REJECTED,
            default => strtoupper($status),
        };
    }

    private function quoteCurrency(string $symbol): string
    {
        foreach (['USDT', 'USDC', 'BUSD', 'USD'] as $quote) {
            if (str_ends_with($symbol, $quote)) {
                return $quote;
            }
        }

        return 'USDT';
    }
}
