<?php

namespace Tests\Unit\Prediction;

use App\Domain\Prediction\PaperPredictionSimulator;
use PHPUnit\Framework\TestCase;

final class PaperPredictionSimulatorTest extends TestCase
{
    public function test_it_simulates_buy_without_broker_execution(): void
    {
        $result = (new PaperPredictionSimulator)->simulate(['decision' => 'BUY'], 100, 105, ['fee_pct' => 0.1]);

        self::assertSame('SIMULATED', $result['status']);
        self::assertTrue($result['execution'] === false);
        self::assertSame(4.8, $result['net_pnl_pct']);
    }

    public function test_wait_has_no_simulated_position(): void
    {
        $result = (new PaperPredictionSimulator)->simulate(['decision' => 'WAIT'], 100, 80);

        self::assertSame(0.0, $result['net_pnl_pct']);
        self::assertFalse($result['execution']);
    }
}
