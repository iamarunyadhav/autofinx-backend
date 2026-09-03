<?php

namespace Tests\Unit\Market;

use App\Console\Commands\MarketWsConsumerCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * MarketWsConsumerCommand's WS daemon used to resolve its active-bot symbol
 * list exactly once at process start (resolveSymbols()), so a bot created on
 * a new symbol got no live data until the daemon happened to reconnect (or
 * was manually restarted). The fix periodically re-resolves and diffs the
 * symbol set to decide whether to force a reconnect; symbolsDiffer() is the
 * comparison at the heart of that decision, so it must be order- and
 * case-insensitive and ignore duplicates — otherwise a re-resolution that
 * returns the same logical set in a different order would cause needless
 * reconnect churn.
 */
class MarketWsConsumerSymbolDiffTest extends TestCase
{
    #[DataProvider('pairs')]
    public function test_symbols_differ(array $current, array $fresh, bool $expectedDiffer): void
    {
        $method = new ReflectionMethod(MarketWsConsumerCommand::class, 'symbolsDiffer');
        $method->setAccessible(true);

        $command = new MarketWsConsumerCommand;

        $this->assertSame($expectedDiffer, $method->invoke($command, $current, $fresh));
    }

    public static function pairs(): array
    {
        return [
            'identical sets' => [['BTCUSDT', 'ETHUSDT'], ['BTCUSDT', 'ETHUSDT'], false],
            'different order is not a diff' => [['BTCUSDT', 'ETHUSDT'], ['ETHUSDT', 'BTCUSDT'], false],
            'lowercase input is normalized' => [['BTCUSDT'], ['btcusdt'], false],
            'duplicate entries are ignored' => [['BTCUSDT', 'BTCUSDT'], ['BTCUSDT'], false],
            'new symbol added is a diff' => [['BTCUSDT'], ['BTCUSDT', 'SOLUSDT'], true],
            'symbol removed is a diff' => [['BTCUSDT', 'SOLUSDT'], ['BTCUSDT'], true],
            'empty vs non-empty is a diff' => [[], ['BTCUSDT'], true],
        ];
    }
}
