<?php

namespace App\Domain\Exchange;

use App\Models\ExchangeSymbolMetadata;
use App\Models\ExchangeConnection;
use App\Models\Integration;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

class BinanceSpotSymbolService
{
    public function __construct(
        private readonly ExchangeClientFactory $clients,
    ) {
    }

    public function validateForOrder(
        Integration $integration,
        string $symbol,
        float $qty,
        ?float $price,
        float $referencePrice,
        string $type = 'MARKET'
    ): array {
        $symbol = BinanceSymbolNormalizer::normalize($symbol);
        $rules = $this->metadataForIntegration($integration, $symbol);

        if (strtoupper((string) ($rules->status ?? 'TRADING')) !== 'TRADING') {
            throw new RuntimeException("Binance symbol {$symbol} is not in TRADING status.");
        }

        $normalizedQty = $this->normalizeDecimal($qty, (float) ($rules->step_size ?? 0));
        if ($normalizedQty <= 0) {
            throw new RuntimeException('Order quantity becomes zero after Binance step-size normalization.');
        }

        $minQty = (float) ($rules->min_qty ?? 0);
        $maxQty = (float) ($rules->max_qty ?? 0);
        if ($minQty > 0 && $normalizedQty < $minQty) {
            throw new RuntimeException("Order quantity is below Binance minQty for {$symbol}.");
        }
        if ($maxQty > 0 && $normalizedQty > $maxQty) {
            throw new RuntimeException("Order quantity exceeds Binance maxQty for {$symbol}.");
        }

        $normalizedPrice = $price;
        if ($price !== null) {
            $normalizedPrice = $this->normalizeDecimal($price, (float) ($rules->tick_size ?? 0));
            if ($normalizedPrice <= 0) {
                throw new RuntimeException('Order price becomes zero after Binance tick-size normalization.');
            }
        }

        $basisPrice = $normalizedPrice ?: $referencePrice;
        $minNotional = (float) ($rules->min_notional ?? 0);
        if ($basisPrice > 0 && $minNotional > 0 && ($normalizedQty * $basisPrice) < $minNotional) {
            throw new RuntimeException("Order notional is below Binance minNotional for {$symbol}.");
        }

        return [
            'symbol' => $symbol,
            'qty' => $normalizedQty,
            'price' => $normalizedPrice,
            'rules' => [
                'status' => $rules->status,
                'base_asset' => $rules->base_asset,
                'quote_asset' => $rules->quote_asset,
                'min_qty' => (float) ($rules->min_qty ?? 0),
                'max_qty' => (float) ($rules->max_qty ?? 0),
                'step_size' => (float) ($rules->step_size ?? 0),
                'min_notional' => (float) ($rules->min_notional ?? 0),
                'tick_size' => (float) ($rules->tick_size ?? 0),
                'validated_type' => strtoupper($type),
            ],
        ];
    }

    public function validateForConnection(
        ExchangeConnection $connection,
        string $symbol,
        float $qty,
        ?float $price,
        float $referencePrice,
        string $type = 'MARKET'
    ): array {
        $symbol = BinanceSymbolNormalizer::normalize($symbol);
        $rules = $this->metadataForConnection($connection, $symbol);

        if (strtoupper((string) ($rules->status ?? 'TRADING')) !== 'TRADING') {
            throw new RuntimeException("Binance symbol {$symbol} is not in TRADING status.");
        }

        $normalizedQty = $this->normalizeDecimal($qty, (float) ($rules->step_size ?? 0));
        if ($normalizedQty <= 0) {
            throw new RuntimeException('Order quantity becomes zero after Binance step-size normalization.');
        }

        $minQty = (float) ($rules->min_qty ?? 0);
        $maxQty = (float) ($rules->max_qty ?? 0);
        if ($minQty > 0 && $normalizedQty < $minQty) {
            throw new RuntimeException("Order quantity is below Binance minQty for {$symbol}.");
        }
        if ($maxQty > 0 && $normalizedQty > $maxQty) {
            throw new RuntimeException("Order quantity exceeds Binance maxQty for {$symbol}.");
        }

        $normalizedPrice = $price;
        if ($price !== null) {
            $normalizedPrice = $this->normalizeDecimal($price, (float) ($rules->tick_size ?? 0));
            if ($normalizedPrice <= 0) {
                throw new RuntimeException('Order price becomes zero after Binance tick-size normalization.');
            }
        }

        $basisPrice = $normalizedPrice ?: $referencePrice;
        $minNotional = (float) ($rules->min_notional ?? 0);
        if ($basisPrice > 0 && $minNotional > 0 && ($normalizedQty * $basisPrice) < $minNotional) {
            throw new RuntimeException("Order notional is below Binance minNotional for {$symbol}.");
        }

        return [
            'symbol' => $symbol,
            'qty' => $normalizedQty,
            'price' => $normalizedPrice,
            'rules' => [
                'status' => $rules->status,
                'base_asset' => $rules->base_asset,
                'quote_asset' => $rules->quote_asset,
                'min_qty' => (float) ($rules->min_qty ?? 0),
                'max_qty' => (float) ($rules->max_qty ?? 0),
                'step_size' => (float) ($rules->step_size ?? 0),
                'min_notional' => (float) ($rules->min_notional ?? 0),
                'tick_size' => (float) ($rules->tick_size ?? 0),
                'validated_type' => strtoupper($type),
            ],
        ];
    }

    public function metadataForIntegration(Integration $integration, string $symbol): ExchangeSymbolMetadata
    {
        $symbol = BinanceSymbolNormalizer::normalize($symbol);
        $cached = ExchangeSymbolMetadata::query()
            ->where('exchange', 'binance')
            ->where('symbol', $symbol)
            ->first();

        if ($cached && $cached->last_synced_at && $cached->last_synced_at->gt(now()->subHours(6))) {
            return $cached;
        }

        $client = $this->clients->makeBinanceFromIntegration($integration);
        $payload = $client->fetchExchangeInfo($symbol);
        $symbolRow = collect((array) ($payload['symbols'] ?? []))
            ->first(fn (array $row) => strtoupper((string) ($row['symbol'] ?? '')) === $symbol);

        if (! is_array($symbolRow)) {
            throw new RuntimeException("Binance symbol {$symbol} is not available for spot trading.");
        }

        $filters = collect((array) ($symbolRow['filters'] ?? []))
            ->keyBy(fn (array $filter) => (string) ($filter['filterType'] ?? ''));
        $lot = $filters->get('LOT_SIZE', []);
        $priceFilter = $filters->get('PRICE_FILTER', []);
        $minNotional = $filters->get('MIN_NOTIONAL', $filters->get('NOTIONAL', []));

        return ExchangeSymbolMetadata::updateOrCreate(
            [
                'exchange' => 'binance',
                'symbol' => $symbol,
            ],
            [
                'id' => $cached?->id ?: (string) Str::uuid(),
                'status' => (string) ($symbolRow['status'] ?? 'UNKNOWN'),
                'base_asset' => (string) ($symbolRow['baseAsset'] ?? ''),
                'quote_asset' => (string) ($symbolRow['quoteAsset'] ?? ''),
                'min_qty' => $this->floatOrNull($lot['minQty'] ?? null),
                'max_qty' => $this->floatOrNull($lot['maxQty'] ?? null),
                'step_size' => $this->floatOrNull($lot['stepSize'] ?? null),
                'min_notional' => $this->floatOrNull($minNotional['minNotional'] ?? null),
                'tick_size' => $this->floatOrNull($priceFilter['tickSize'] ?? null),
                'last_synced_at' => now(),
                'raw_json' => $symbolRow,
            ]
        );
    }

    public function metadataForConnection(ExchangeConnection $connection, string $symbol): ExchangeSymbolMetadata
    {
        $symbol = BinanceSymbolNormalizer::normalize($symbol);
        $cached = ExchangeSymbolMetadata::query()
            ->where('exchange', 'binance')
            ->where('symbol', $symbol)
            ->first();

        if ($cached && $cached->last_synced_at && $cached->last_synced_at->gt(now()->subHours(6))) {
            return $cached;
        }

        $client = $this->clients->makeBinanceFromConnection($connection);
        $payload = $client->fetchExchangeInfo($symbol);
        $symbolRow = collect((array) ($payload['symbols'] ?? []))
            ->first(fn (array $row) => strtoupper((string) ($row['symbol'] ?? '')) === $symbol);

        if (! is_array($symbolRow)) {
            throw new RuntimeException("Binance symbol {$symbol} is not available for spot trading.");
        }

        $filters = collect((array) ($symbolRow['filters'] ?? []))
            ->keyBy(fn (array $filter) => (string) ($filter['filterType'] ?? ''));
        $lot = $filters->get('LOT_SIZE', []);
        $priceFilter = $filters->get('PRICE_FILTER', []);
        $minNotional = $filters->get('MIN_NOTIONAL', $filters->get('NOTIONAL', []));

        return ExchangeSymbolMetadata::updateOrCreate(
            [
                'exchange' => 'binance',
                'symbol' => $symbol,
            ],
            [
                'id' => $cached?->id ?: (string) Str::uuid(),
                'status' => (string) ($symbolRow['status'] ?? 'UNKNOWN'),
                'base_asset' => (string) ($symbolRow['baseAsset'] ?? ''),
                'quote_asset' => (string) ($symbolRow['quoteAsset'] ?? ''),
                'min_qty' => $this->floatOrNull($lot['minQty'] ?? null),
                'max_qty' => $this->floatOrNull($lot['maxQty'] ?? null),
                'step_size' => $this->floatOrNull($lot['stepSize'] ?? null),
                'min_notional' => $this->floatOrNull($minNotional['minNotional'] ?? null),
                'tick_size' => $this->floatOrNull($priceFilter['tickSize'] ?? null),
                'last_synced_at' => now(),
                'raw_json' => $symbolRow,
            ]
        );
    }

    private function floatOrNull(mixed $value): ?float
    {
        $float = (float) $value;

        return $float > 0 ? $float : null;
    }

    private function normalizeDecimal(float $value, float $step): float
    {
        if ($step <= 0) {
            return round($value, 8);
        }

        $steps = floor(($value + 1e-12) / $step);

        return round($steps * $step, 8);
    }
}
