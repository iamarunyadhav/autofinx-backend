<?php

namespace Tests\Unit\Prediction;

use App\Models\PredictionObservation;
use PHPUnit\Framework\TestCase;

final class PredictionObservationSchemaTest extends TestCase
{
    public function test_migration_is_pending_and_does_not_run_implicitly(): void
    {
        self::assertFileExists(dirname(__DIR__, 3).'/database/migrations/2026_10_03_000000_create_prediction_observations_table.php');
    }

    public function test_observation_uses_the_table_integer_primary_key(): void
    {
        $model = new PredictionObservation();

        self::assertTrue($model->getIncrementing());
        self::assertSame('int', $model->getKeyType());
        self::assertSame([], $model->uniqueIds());
    }
}
