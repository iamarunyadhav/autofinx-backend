<?php

use App\Domain\Intelligence\AiRecommendationSchedulerService;
use App\Jobs\DispatchAiDecisionCycleJob;

it('dispatch ai decision cycle job delegates scheduling to the recommendation scheduler service', function () {
    $scheduler = Mockery::mock(AiRecommendationSchedulerService::class);
    $scheduler->shouldReceive('dispatchEligible')
        ->once()
        ->withNoArgs()
        ->andReturn([
            'dispatched' => 1,
            'skipped' => 0,
            'reasons' => [],
            'dry_run' => false,
        ]);

    app()->instance(AiRecommendationSchedulerService::class, $scheduler);

    app(DispatchAiDecisionCycleJob::class)->handle(app(AiRecommendationSchedulerService::class));
});
