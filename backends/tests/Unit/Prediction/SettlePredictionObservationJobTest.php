<?php
namespace Tests\Unit\Prediction;
use App\Jobs\SettlePredictionObservationJob;
use PHPUnit\Framework\TestCase;
final class SettlePredictionObservationJobTest extends TestCase
{
    public function test_job_carries_explicit_closed_price(): void
    {
        $job = new SettlePredictionObservationJob(42, 101.25);
        self::assertSame(42, $job->observationId);
        self::assertSame(101.25, $job->closedExpiryPrice);
    }
}
