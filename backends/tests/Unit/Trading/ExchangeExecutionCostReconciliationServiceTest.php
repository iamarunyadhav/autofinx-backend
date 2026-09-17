<?php

use App\Domain\Trading\ExchangeExecutionCostReconciliationService;

test('one missing commission preserves the other actual commission and reports partial', function () {
    $result = (new ExchangeExecutionCostReconciliationService)->resolveFees(0.03, null, null, 0.1, 0.2);
    expect($result)->toBe([0.03, 0.2, 0.0, 0.03, null, null, 'partial']);
});

test('verified zero fees are real and funding credits increase net profit', function () {
    $service = new ExchangeExecutionCostReconciliationService;
    expect($service->resolveFees(0.0, 0.0, 0.0, 1, 1)[6])->toBe('real');
    expect($service->netPnl(10, 2, -3))->toBe(11.0);
});

test('missing fee evidence remains estimated', function () {
    expect((new ExchangeExecutionCostReconciliationService)->resolveFees(null, null, null, 1, 2))
        ->toBe([1.0, 2.0, 0.0, null, null, null, 'estimated']);
});
