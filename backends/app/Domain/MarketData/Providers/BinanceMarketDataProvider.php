<?php

namespace App\Domain\MarketData\Providers;

use App\Domain\MarketData\Contracts\MarketDataProvider;
use App\Domain\MarketData\Data\NormalizedQuote;
use App\Domain\MarketData\Data\ProviderHealth;
use App\Domain\MarketData\Enums\FeedState;
use DateTimeInterface;

class BinanceMarketDataProvider implements MarketDataProvider
{
    public function connect(): void {}
    public function disconnect(): void {}
    public function subscribeQuotes(array $instruments): void {}
    public function subscribeTrades(array $instruments): void {}
    public function subscribeCandles(array $instruments, array $timeframes): void {}
    public function latestQuote(string $instrument): ?NormalizedQuote { return null; }
    public function historicalCandles(string $instrument, string $timeframe, DateTimeInterface $from, DateTimeInterface $to): iterable { return []; }
    public function health(): ProviderHealth
    {
        return new ProviderHealth('binance', FeedState::LIVE, (array) config('market_data.providers.binance.capabilities', []));
    }
}
