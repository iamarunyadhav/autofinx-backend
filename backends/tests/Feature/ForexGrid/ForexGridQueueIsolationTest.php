<?php

namespace Tests\Feature\ForexGrid;

use App\Jobs\RunForexGridBotEvaluationJob;
use App\Jobs\RunRatioBotEvaluationJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Grep-based (not mock-based) proof that RunForexGridBotEvaluationJob is on
 * its own dedicated queue, distinct from Ratio Bot's -- verified by
 * constructing both jobs and reading the queue each assigns itself via
 * onQueue() in its constructor.
 */
class ForexGridQueueIsolationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function forex_grid_job_uses_its_own_queue_distinct_from_ratio_bot(): void
    {
        $forexGridJob = new RunForexGridBotEvaluationJob('00000000-0000-0000-0000-000000000000');
        $ratioBotJob = new RunRatioBotEvaluationJob('00000000-0000-0000-0000-000000000000');

        $this->assertSame('forex_grid', $forexGridJob->queue);
        $this->assertSame('ratio_bot', $ratioBotJob->queue);
        $this->assertNotSame($forexGridJob->queue, $ratioBotJob->queue);
    }

    /** @test */
    public function job_source_declares_the_forex_grid_queue_not_ratio_bot(): void
    {
        $source = file_get_contents(app_path('Jobs/RunForexGridBotEvaluationJob.php'));

        $this->assertStringContainsString("onQueue('forex_grid')", $source);
        $this->assertStringNotContainsString("onQueue('ratio_bot')", $source);
    }
}
