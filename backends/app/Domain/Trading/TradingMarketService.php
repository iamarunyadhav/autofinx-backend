<?php

namespace App\Domain\Trading;

use App\Domain\Trading\Providers\LiveTradeProviderRegistry;
use App\Models\Integration;
use App\Models\MarketCandle;
use App\Models\MarketTick;
use App\Models\Symbol;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class TradingMarketService
{
    public function __construct(
        private readonly TimeframeRegistry $timeframes,
        private readonly LiveTradeProviderRegistry $providers,
        private readonly TradingIntegrationResolver $integrations,
    ) {
    }

    public function resolveSymbol(string $symbolCode): Symbol
    {
        $normalized = strtoupper(trim($symbolCode));

        return Symbol::firstOrCreate(
            ['symbol' => $normalized],
            [
                'id' => (string) Str::uuid(),
                'type' => $this->inferSymbolType($normalized),
                'exchange' => 'paper',
                'metadata' => ['display' => str_replace('_', ' ', $normalized)],
            ]
        );
    }

    public function candles(string $symbolCode, string $timeframe = '1m', int $limit = 120, array $context = []): array
    {
        $normalizedTimeframe = $this->timeframes->normalize($timeframe);

        if ($this->isLiveContext($context)) {
            $integration = $this->liveIntegration($context);

            return $this->providers
                ->for((string) ($context['provider'] ?? 'binance'))
                ->candles($integration, strtoupper(trim($symbolCode)), $normalizedTimeframe, $limit);
        }

        $symbol = $this->resolveSymbol($symbolCode);

        $this->ensureSyntheticSeries($symbol, $normalizedTimeframe, max(40, $limit));

        return MarketCandle::where('symbol_id', $symbol->id)
            ->where('timeframe', $normalizedTimeframe)
            ->orderByDesc('opens_at')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (MarketCandle $candle) => [
                'timestamp' => CarbonImmutable::parse($candle->opens_at)->getTimestampMs(),
                'open' => (float) $candle->open,
                'high' => (float) $candle->high,
                'low' => (float) $candle->low,
                'close' => (float) $candle->close,
                'volume' => (float) $candle->volume,
                'opens_at' => CarbonImmutable::parse($candle->opens_at)->toIso8601String(),
            ])
            ->all();
    }

    public function latestQuote(string $symbolCode, string $timeframe = '1m', array $context = []): array
    {
        $normalizedTimeframe = $this->timeframes->normalize($timeframe);

        if ($this->isLiveContext($context)) {
            $integration = $this->liveIntegration($context);

            return $this->providers
                ->for((string) ($context['provider'] ?? 'binance'))
                ->latestQuote($integration, strtoupper(trim($symbolCode)));
        }

        $symbol = $this->resolveSymbol($symbolCode);

        $this->ensureSyntheticSeries($symbol, $normalizedTimeframe, 60);

        $tick = MarketTick::where('symbol_id', $symbol->id)
            ->orderByDesc('occurred_at')
            ->first();

        if (! $tick) {
            $latestCandle = MarketCandle::where('symbol_id', $symbol->id)
                ->where('timeframe', $normalizedTimeframe)
                ->latest('opens_at')
                ->first();

            $price = (float) ($latestCandle?->close ?? $this->startingPrice($symbol->symbol));
            $tick = MarketTick::create([
                'id' => (string) Str::uuid(),
                'symbol_id' => $symbol->id,
                'price' => $price,
                'bid' => $price * 0.9996,
                'ask' => $price * 1.0004,
                'volume' => 50,
                'occurred_at' => now(),
                'metadata' => ['synthetic' => true],
            ]);
        }

        return [
            'symbol' => $symbol->symbol,
            'price' => (float) $tick->price,
            'bid' => (float) ($tick->bid ?? $tick->price),
            'ask' => (float) ($tick->ask ?? $tick->price),
            'volume' => (float) ($tick->volume ?? 0),
            'occurred_at' => CarbonImmutable::parse($tick->occurred_at)->toIso8601String(),
        ];
    }

    public function priceAtOrAfter(string $symbolCode, CarbonImmutable $moment, string $timeframe = '1m', array $context = []): float
    {
        if ($this->isLiveContext($context)) {
            return (float) $this->latestQuote($symbolCode, $timeframe, $context)['price'];
        }

        $symbol = $this->resolveSymbol($symbolCode);
        $normalizedTimeframe = $this->timeframes->normalize($timeframe);
        $this->ensureSyntheticSeries($symbol, $normalizedTimeframe, 80);

        $tick = MarketTick::where('symbol_id', $symbol->id)
            ->where('occurred_at', '>=', $moment)
            ->orderBy('occurred_at')
            ->first();

        if ($tick) {
            return (float) $tick->price;
        }

        $candle = MarketCandle::where('symbol_id', $symbol->id)
            ->where('timeframe', $normalizedTimeframe)
            ->where('opens_at', '>=', $moment)
            ->orderBy('opens_at')
            ->first();

        if ($candle) {
            return (float) $candle->close;
        }

        return (float) $this->latestQuote($symbolCode, $normalizedTimeframe)['price'];
    }

    private function ensureSyntheticSeries(Symbol $symbol, string $timeframe, int $limit): void
    {
        $seconds = $this->timeframes->seconds($timeframe);
        $now = CarbonImmutable::now();
        $start = $now->subSeconds(($limit - 1) * $seconds);

        $existing = MarketCandle::where('symbol_id', $symbol->id)
            ->where('timeframe', $timeframe)
            ->whereBetween('opens_at', [$start, $now])
            ->orderBy('opens_at')
            ->get()
            ->keyBy(fn (MarketCandle $candle) => CarbonImmutable::parse($candle->opens_at)->toIso8601String());

        $price = $existing->last()?->close ?? $this->startingPrice($symbol->symbol);
        $phase = (abs(crc32($symbol->symbol . $timeframe)) % 360) / 57.2958;

        for ($i = 0; $i < $limit; $i++) {
            $opensAt = $start->addSeconds($i * $seconds);
            $key = $opensAt->toIso8601String();
            if ($existing->has($key)) {
                $price = (float) $existing->get($key)->close;
                continue;
            }

            $open = (float) $price;
            $wave = sin(($i / 4) + $phase) * max($open, 1) * 0.0014;
            $bias = cos(($i / 7) + $phase) * max($open, 1) * 0.0009;
            $close = max(0.0001, $open + $wave + $bias);
            $high = max($open, $close) + (abs($wave) * 0.35) + max($open, 1) * 0.0003;
            $low = min($open, $close) - (abs($bias) * 0.35) - max($open, 1) * 0.0003;
            $volume = 50 + (($i + abs(crc32($symbol->symbol))) % 80);

            MarketCandle::create([
                'id' => (string) Str::uuid(),
                'symbol_id' => $symbol->id,
                'timeframe' => $timeframe,
                'opens_at' => $opensAt,
                'open' => round($open, 8),
                'high' => round($high, 8),
                'low' => round(max(0.0001, $low), 8),
                'close' => round($close, 8),
                'volume' => $volume,
            ]);

            $price = $close;
        }

        $latestCandle = MarketCandle::where('symbol_id', $symbol->id)
            ->where('timeframe', $timeframe)
            ->latest('opens_at')
            ->first();

        $latestTick = MarketTick::where('symbol_id', $symbol->id)->latest('occurred_at')->first();
        if (! $latestTick || CarbonImmutable::parse($latestTick->occurred_at)->lt($now->subSeconds($seconds))) {
            $lastPrice = (float) ($latestCandle?->close ?? $price);
            MarketTick::create([
                'id' => (string) Str::uuid(),
                'symbol_id' => $symbol->id,
                'price' => $lastPrice,
                'bid' => $lastPrice * 0.9996,
                'ask' => $lastPrice * 1.0004,
                'volume' => (float) ($latestCandle?->volume ?? 50),
                'occurred_at' => $now,
                'metadata' => ['synthetic' => true],
            ]);
        }
    }

    private function inferSymbolType(string $symbol): string
    {
        return match (true) {
            str_contains($symbol, 'BTC') || str_contains($symbol, 'ETH') => 'crypto',
            str_contains($symbol, 'EUR') || str_contains($symbol, 'USD') => 'fx',
            default => 'metals',
        };
    }

    private function startingPrice(string $symbol): float
    {
        return match (true) {
            str_contains($symbol, 'BTC') => 63850.0,
            str_contains($symbol, 'ETH') => 3220.0,
            str_contains($symbol, 'EURUSD') => 1.0824,
            str_contains($symbol, 'SILVER') => 32.1800,
            str_contains($symbol, 'GOLD') => 5080.2500,
            default => 100.0,
        };
    }

    private function isLiveContext(array $context): bool
    {
        return strtolower((string) ($context['mode'] ?? 'paper')) === 'live';
    }

    private function liveIntegration(array $context): ?Integration
    {
        $workspaceId = (string) ($context['workspace_id'] ?? '');
        if ($workspaceId === '') {
            return null;
        }

        try {
            return $this->integrations->resolve(
                $workspaceId,
                $context['provider'] ?? null,
                $context['integration_id'] ?? null
            );
        } catch (\Throwable) {
            return null;
        }
    }
}
