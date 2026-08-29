<?php

use App\Domain\MarketData\Data\NormalizedQuote;
use App\Domain\MarketData\Support\InstrumentMapper;
use App\Domain\MarketData\Support\MarketClock;
use Illuminate\Support\Carbon;

it('normalizes forex and metal bid ask semantics', function () {
    $quote = NormalizedQuote::fromBidAsk(
        provider: 'mock_forex',
        instrument: 'XAUUSD',
        providerSymbol: 'GOLD',
        marketType: 'METAL',
        bid: 3421.20,
        ask: 3421.45,
        providerEventAtMs: 1000,
        receivedAtMs: 1040,
        processedAtMs: 1044,
    );

    expect($quote->mid)->toBe(3421.325)
        ->and($quote->spread)->toBe(0.25)
        ->and($quote->executionPriceForSide('BUY'))->toBe(3421.45)
        ->and($quote->executionPriceForSide('SELL'))->toBe(3421.20);
});

it('maps provider symbols to canonical instruments', function () {
    $mapper = new InstrumentMapper;

    expect($mapper->canonical('mock_forex', 'GOLD'))->toBe('XAUUSD')
        ->and($mapper->canonical('binance', 'btcusdt'))->toBe('BTCUSDT')
        ->and($mapper->marketType('XAUUSD'))->toBe('METAL')
        ->and($mapper->splitAssets('EURUSD'))->toBe(['EUR', 'USD']);
});

it('does not classify closed forex sessions as stale market time', function () {
    $clock = new MarketClock;

    expect($clock->isMarketExpectedOpen('mock_forex', 'EURUSD', Carbon::parse('2026-08-29 12:00:00', 'UTC')))->toBeFalse()
        ->and($clock->session('mock_forex', 'EURUSD', Carbon::parse('2026-08-29 12:00:00', 'UTC')))->toBe('MARKET_CLOSED')
        ->and($clock->isMarketExpectedOpen('binance', 'BTCUSDT', Carbon::parse('2026-08-29 12:00:00', 'UTC')))->toBeTrue();
});
