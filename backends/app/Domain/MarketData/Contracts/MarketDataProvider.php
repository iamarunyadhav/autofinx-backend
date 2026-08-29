<?php

namespace App\Domain\MarketData\Contracts;

use App\Domain\MarketData\Data\NormalizedQuote;
use App\Domain\MarketData\Data\ProviderHealth;
use DateTimeInterface;

interface MarketDataProvider
{
    public function connect(): void;
    public function disconnect(): void;
    public function subscribeQuotes(array $instruments): void;
    public function subscribeTrades(array $instruments): void;
    public function subscribeCandles(array $instruments, array $timeframes): void;
    public function latestQuote(string $instrument): ?NormalizedQuote;
    public function historicalCandles(string $instrument, string $timeframe, DateTimeInterface $from, DateTimeInterface $to): iterable;
    public function health(): ProviderHealth;
}
