<?php

namespace Tests\Feature\Prediction;

use Tests\TestCase;

final class PredictionAccuracyReportEndpointTest extends TestCase
{
    public function test_unauthenticated_accuracy_report_is_protected(): void
    {
        $this->postJson('/api/v1/quick-trade/prediction-accuracy-report', [])->assertStatus(401);
    }
}
