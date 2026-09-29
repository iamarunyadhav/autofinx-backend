<?php

namespace Tests\Feature\Prediction;

use Tests\TestCase;

final class FreshPredictionDatasetDashboardEndpointTest extends TestCase
{
    public function test_fresh_dataset_dashboard_is_not_public(): void
    {
        $this->getJson('/api/v1/quick-trade/prediction-fresh-datasets')->assertStatus(401);
    }
}
