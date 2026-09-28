<?php

namespace Tests\Unit\Prediction;

use App\Domain\Prediction\PredictionCounterfactualEngine;
use PHPUnit\Framework\TestCase;

final class PredictionCounterfactualEngineTest extends TestCase
{
    public function test_it_evaluates_buy_sell_and_wait_without_a_trade(): void
    {
        $result = (new PredictionCounterfactualEngine)->evaluate(100, 98, ['fee_pct' => 0.1]);

        self::assertSame('EVALUATED', $result['status']);
        self::assertLessThan(0, $result['buy']['net_return_pct']);
        self::assertGreaterThan(0, $result['sell']['net_return_pct']);
        self::assertSame(2.0, $result['wait']['avoided_loss_pct']);
    }
}
