<?php

namespace Tests\Unit\Prediction;

use PHPUnit\Framework\TestCase;

final class PredictionObservationSchemaTest extends TestCase
{
    public function test_migration_is_pending_and_does_not_run_implicitly(): void
    {
        self::assertFileExists(dirname(__DIR__, 3).'/database/migrations/2026_10_03_000000_create_prediction_observations_table.php');
    }
}
