<?php

use App\Domain\Trading\ExecutionQualityService;
use App\Models\Order;
use App\Models\OrderFill;
use Carbon\CarbonImmutable;

test('it summarizes execution price slippage fees and fill latency', function () {
    $placedAt = CarbonImmutable::parse('2026-03-30 10:00:00', 'UTC');
    $fills = collect([
        new OrderFill([
            'fill_price' => 100.5,
            'fill_qty' => 1.0,
            'fee' => 0.1,
            'fee_asset' => 'USDT',
            'fill_ts' => $placedAt->addSeconds(10),
        ]),
        new OrderFill([
            'fill_price' => 101.0,
            'fill_qty' => 0.5,
            'fee' => 0.05,
            'fee_asset' => 'USDT',
            'fill_ts' => $placedAt->addSeconds(25),
        ]),
    ]);

    $order = new Order([
        'side' => 'buy',
        'price' => 100.0,
        'requested_qty' => 2.0,
        'status' => Order::STATUS_PARTIAL,
        'latency_ms' => 420,
    ]);
    $order->placed_ts = $placedAt;

    $summary = app(ExecutionQualityService::class)->summarize($order, $fills);

    expect($summary['avg_execution_price'])->toBe(100.66666667)
        ->and($summary['filled_qty'])->toBe(1.5)
        ->and($summary['fill_count'])->toBe(2)
        ->and($summary['fee_total'])->toBe(0.15)
        ->and($summary['slippage_pct'])->toBe(0.6667)
        ->and($summary['submission_latency_ms'])->toBe(420)
        ->and($summary['fill_latency_ms'])->toBe(25000.0)
        ->and($summary['partial_fill'])->toBeTrue();
});

test('it exposes reject and cancel metadata even without fills', function () {
    $order = new Order([
        'side' => 'sell',
        'status' => Order::STATUS_REJECTED,
        'raw_json' => ['msg' => 'Filter failure'],
        'meta' => ['cancel_reason' => 'manual_operator_cancel'],
    ]);

    $summary = app(ExecutionQualityService::class)->summarize($order, []);

    expect($summary['avg_execution_price'])->toBeNull()
        ->and($summary['slippage_pct'])->toBeNull()
        ->and($summary['reject_reason'])->toBe('Filter failure')
        ->and($summary['cancel_reason'])->toBe('manual_operator_cancel')
        ->and($summary['partial_fill'])->toBeFalse();
});
