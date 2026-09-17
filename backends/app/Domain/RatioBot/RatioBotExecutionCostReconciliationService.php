<?php

namespace App\Domain\RatioBot;

use App\Domain\Exchange\BinanceFuturesClient;
use App\Domain\Exchange\ExchangeClientFactory;
use App\Domain\Integrations\IntegrationManager;
use App\Domain\Trading\FuturesConfigService;
use App\Models\ExchangeConnection;
use App\Models\Integration;
use App\Models\Order;
use App\Models\RatioBotConfig;
use App\Models\RatioBotEvent;
use App\Models\RatioBotExecutionCostRecord as Record;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/** Independent, repeatable accounting projection; never replays execution/settlement. */
class RatioBotExecutionCostReconciliationService
{
    public function __construct(
        private readonly RatioBotCostProjector $projector,
        private readonly IntegrationManager $integrations,
        private readonly ExchangeClientFactory $clients,
        private readonly FuturesConfigService $futuresConfigs,
    ) {}

    public function reconcile(RatioBotConfig $config): array
    {
        if (! Schema::hasTable('ratio_bot_execution_cost_records')) {
            return ['cost_source' => 'unavailable', 'reason' => 'Cost ledger migration pending.'];
        }
        $lock = Cache::lock('ratio-bot-costs:'.$config->id, 600);
        if (! $lock->get()) {
            return ['cost_source' => 'unavailable', 'reason' => 'Reconciliation already running.'];
        }
        try {
            $orders = Order::query()->where('workspace_id', $config->workspace_id)
                ->where('meta->ratio_bot_config_id', (string) $config->id)
                ->whereNotNull('exchange_order_id')->orderBy('placed_ts')->get();
            if ($orders->isEmpty()) {
                return $this->saveReport($config, ['cost_source' => 'unavailable', 'reason' => 'No exchange order evidence.']);
            }
            $futures = $config->marketType() === 'futures';
            $symbol = $config->normalizedSymbol();
            $quote = strtoupper((string) ($orders->first()->quote_currency ?: 'USDT'));
            $known = [];
            foreach ($orders as $order) {
                if ($order->asset_code !== $symbol) {
                    throw new RuntimeException('Bot symbol changed; historical account scope requires review.');
                }
                $known[(string) $order->exchange_order_id] = ['qty' => (float) $order->filled_qty,
                    'reference_price' => data_get($order->meta, 'evaluated_price')];
            }

            if (! $futures) {
                $trades = [];
                $scopes = $orders->map(fn ($order) => $order->integration_id
                    ? 'integration:'.$order->integration_id
                    : 'connection:'.data_get($order->meta, 'connection_id', $config->connection_id))->unique();
                if ($scopes->count() !== 1) {
                    throw new RuntimeException('Spot history spans multiple accounts; commission attribution requires review.');
                }
                foreach ($orders as $order) {
                    if ((float) $order->filled_qty <= 0) {
                        continue;
                    }
                    // Direct read deliberately bypasses wallet/position-booking reconciliation:
                    // a late commission must not book the same fill a second time.
                    if ($order->integration_id) {
                        $integration = Integration::where('workspace_id', $config->workspace_id)->findOrFail($order->integration_id);
                        $scope = hash('sha256', $integration->secrets_encrypted.json_encode($integration->config_json));
                        $client = $this->clients->makeBinanceFromIntegration($integration);
                    } else {
                        $connection = ExchangeConnection::where('workspace_id', $config->workspace_id)
                            ->findOrFail(data_get($order->meta, 'connection_id', $config->connection_id));
                        $scope = hash('sha256', $connection->api_key_encrypted.'|'.(int) $connection->is_testnet);
                        $client = $this->clients->makeBinanceFromConnection($connection);
                    }
                    $savedScope = $this->payload($config, 'spot_account')['scope'] ?? $scope;
                    if ($savedScope !== $scope) {
                        throw new RuntimeException('Spot account credentials changed; historical evidence requires review.');
                    }
                    $rows = $client->fetchAccountingOrderTrades($symbol, (string) $order->exchange_order_id);
                    foreach ($rows as $row) {
                        if ((string) ($row['orderId'] ?? '') !== (string) $order->exchange_order_id) {
                            throw new RuntimeException('Commission response belongs to another order.');
                        }
                    }
                    $trades = array_merge($trades, $rows);
                }
                $report = $this->projector->project($trades, [], $known, $symbol, $quote, null, true, false);
                if (isset($scope)) {
                    $this->put($config, 'spot_account', ['scope' => $scope]);
                }
                return $this->saveReport($config, $report);
            }

            $integrationIds = $orders->pluck('integration_id')->filter()->unique();
            if ($integrationIds->count() !== 1) {
                throw new RuntimeException('Historical orders span missing/multiple exchange accounts.');
            }
            $integration = Integration::where('workspace_id', $config->workspace_id)->findOrFail($integrationIds->first());
            $workspaceConfig = $this->futuresConfigs->effective((string) $config->workspace_id);
            $creds = $this->integrations->decryptSecrets($integration->secrets_encrypted);
            $baseUrl = ($workspaceConfig['futures_force_testnet'] ?? true)
                ? config('services.binance.futures_testnet_api_url', 'https://testnet.binancefuture.com')
                : config('services.binance.futures_api_url', 'https://fapi.binance.com');
            $accountScope = hash('sha256', $baseUrl.'|'.($creds['api_key'] ?? $creds['key'] ?? ''));
            $client = new BinanceFuturesClient((string) ($creds['api_key'] ?? $creds['key'] ?? ''),
                (string) ($creds['api_secret'] ?? $creds['secret'] ?? ''), (string) $baseUrl);
            $checkpoint = $this->payload($config, 'checkpoint');
            if ($checkpoint && ($checkpoint['account_scope'] ?? '') !== $accountScope) {
                throw new RuntimeException('Exchange account/environment changed; ledger cannot be mixed.');
            }
            // Keep replaced/triggered stop IDs even after state tracker is cleared.
            foreach (RatioBotEvent::where('ratio_bot_config_id', $config->id)
                ->whereIn('event_type', [RatioBotEvent::EVENT_EXCHANGE_STOP_ORDER_PLACED, RatioBotEvent::EVENT_EXCHANGE_STOP_ORDER_REPLACED])
                ->get() as $event) {
                $id = data_get($event->context, 'exchange_order_id');
                if ($id !== null) {
                    $known[(string) $id] ??= ['qty' => null, 'reference_price' => data_get($event->context, 'stop_price')];
                }
            }
            $first = ($orders->first()->placed_ts ?? $orders->first()->created_at)->getTimestampMs();
            $retentionFloor = now()->subMonths(3)->addDay()->getTimestampMs();
            $start = max($first, (int) ($checkpoint['through'] ?? $first) - 604800000);
            if ($start < $retentionFloor) {
                throw new RuntimeException('History gap exceeds exchange retention; historical import required.');
            }
            $before = $this->positionQty($client, $symbol);
            $end = now()->getTimestampMs();
            $newTrades = [];
            $newIncome = [];
            // Catch up every missed interval, rather than charging today's rate for old funding.
            for ($from = $start; $from <= $end; $from = $to + 1) {
                $to = min($end, $from + 604799999);
                foreach ($client->fetchAccountingTrades($symbol, $from, $to) as $row) {
                    $this->assertWindow($row, $from, $to);
                    $newTrades[(string) ($row['id'] ?? '')] = $row;
                }
                foreach ($client->fetchAccountingFunding($symbol, $from, $to) as $row) {
                    $this->assertWindow($row, $from, $to);
                    $newIncome[(string) ($row['tranId'] ?? '')] = $row;
                }
            }
            $after = $this->positionQty($client, $symbol);
            if ($before !== $after) {
                throw new RuntimeException('Account position changed during reconciliation; retry with a stable snapshot.');
            }
            $trades = $this->events($config, 'trade:');
            $income = $this->events($config, 'funding:');
            $trades = array_replace($trades, $newTrades);
            $income = array_replace($income, $newIncome);
            $report = $this->projector->project(array_values($trades), array_values($income), $known,
                $symbol, $quote, $after, true, true);
            $report['history_from'] = $checkpoint['from'] ?? $first;
            $report['history_through'] = $end;
            $report['account_scope'] = $accountScope;
            // Commit evidence and cursor together. A failed page never advances coverage.
            return DB::transaction(function () use ($config, $newTrades, $newIncome, $report, $accountScope, $first, $end) {
                foreach ($newTrades as $id => $row) {
                    $this->put($config, 'trade:'.$id, $row);
                }
                foreach ($newIncome as $id => $row) {
                    $this->put($config, 'funding:'.$id, $row);
                }
                $this->put($config, 'checkpoint', ['account_scope' => $accountScope, 'from' => $report['history_from'] ?? $first, 'through' => $end]);
                return $this->saveReport($config, $report);
            });
        } catch (Throwable $e) {
            // Keep prior evidence but invalidate its finality; never show stale net as actual.
            $previous = $this->payload($config, 'report');
            return $this->saveReport($config, array_merge($previous, [
                'cost_source' => 'unavailable', 'fee_source' => 'unavailable', 'funding_source' => 'unavailable',
                'net_pnl_actual' => null, 'gross_pnl_actual' => null, 'reason' => $e->getMessage(),
            ]));
        } finally {
            $lock->release();
        }
    }

    private function positionQty(BinanceFuturesClient $client, string $symbol): ?float
    {
        $rows = $client->fetchPositionRisk($symbol);
        foreach ($rows as $row) {
            if (($row['symbol'] ?? '') === $symbol && ($row['positionSide'] ?? '') === 'BOTH'
                && isset($row['positionAmt']) && is_numeric($row['positionAmt']) && is_finite((float) $row['positionAmt'])) {
                return (float) $row['positionAmt'];
            }
        }
        return null; // Missing/hedged snapshot cannot prove exclusive funding ownership.
    }

    private function assertWindow(array $row, int $from, int $to): void
    {
        if (! isset($row['time']) || ! is_numeric($row['time']) || $row['time'] < $from || $row['time'] > $to) {
            throw new RuntimeException('Exchange history record outside requested window.');
        }
    }

    private function events(RatioBotConfig $config, string $prefix): array
    {
        return Record::where('ratio_bot_config_id', $config->id)->where('source_key', 'like', $prefix.'%')
            ->get()->mapWithKeys(fn ($r) => [substr($r->source_key, strlen($prefix)) => $r->payload])->all();
    }

    private function payload(RatioBotConfig $config, string $key): array
    {
        return Record::where('ratio_bot_config_id', $config->id)->where('source_key', $key)->first()?->payload ?? [];
    }

    private function put(RatioBotConfig $config, string $key, array $payload): void
    {
        Record::updateOrCreate(['ratio_bot_config_id' => $config->id, 'source_key' => $key], ['payload' => $payload]);
    }

    private function saveReport(RatioBotConfig $config, array $report): array
    {
        $report['checked_at'] = now()->toIso8601String();
        $this->put($config, 'report', $report);
        return $report;
    }
}
