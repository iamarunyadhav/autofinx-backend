<?php

use App\Jobs\CheckQuickTradeStopLossJob;
use Illuminate\Support\Facades\Bus;

test('the --once flag runs a single tick and exits without looping', function () {
    Bus::fake();

    $this->artisan('quick-trade:monitor-stop-loss --once')
        ->assertExitCode(0);

    Bus::assertDispatchedTimes(CheckQuickTradeStopLossJob::class, 1);
});
