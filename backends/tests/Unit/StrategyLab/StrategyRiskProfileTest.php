<?php

use App\Domain\StrategyLab\StrategyRiskProfile;

it('calculates fixed long and short protection levels', function (): void {
    $risk = new StrategyRiskProfile();
    $long = $risk->levels(100, 'BUY', 2, ['mode' => 'fixed', 'stop_loss_pct' => 1, 'take_profit_pct' => 2, 'trailing_pct' => 0.8]);
    expect($long['stop_loss'])->toBe(99.0)->and($long['take_profit'])->toBe(102.0)->and($long['risk_reward'])->toBe(2.0);
    $short = $risk->levels(100, 'SELL', 2, ['mode' => 'fixed', 'stop_loss_pct' => 1, 'take_profit_pct' => 2, 'trailing_pct' => 0.8]);
    expect($short['stop_loss'])->toBe(101.0)->and($short['take_profit'])->toBe(98.0);
});

it('derives dynamic distances from ATR and caps quantity by notional', function (): void {
    $risk = new StrategyRiskProfile();
    $levels = $risk->levels(100, 'BUY', 2, ['mode' => 'atr', 'stop_loss_atr' => 2, 'take_profit_atr' => 3, 'trailing_atr' => 1]);
    expect($levels['stop_loss_pct'])->toBe(4.0)->and($levels['take_profit_pct'])->toBe(6.0)->and($levels['trailing_pct'])->toBe(2.0);
    expect($risk->quantity(1000, 1, 100, 96, 500))->toBe(2.5);
});

it('rejects invalid risk input', function (): void {
    expect(fn () => (new StrategyRiskProfile())->levels(100, 'BUY', 0, ['mode' => 'atr', 'stop_loss_atr' => 2, 'take_profit_atr' => 3]))->toThrow(InvalidArgumentException::class);
});
