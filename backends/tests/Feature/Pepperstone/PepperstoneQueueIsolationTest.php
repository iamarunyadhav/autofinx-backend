<?php

namespace Tests\Feature\Pepperstone;

use App\Jobs\RunForexGridBotEvaluationJob;
use App\Jobs\RunPepperstoneBotEvaluationJob;
use App\Jobs\RunRatioBotEvaluationJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Grep-based (not mock-based) proof that RunPepperstoneBotEvaluationJob is
 * on its own dedicated queue, distinct from both Ratio Bot's and Forex
 * Grid's -- mirrors ForexGridQueueIsolationTest's exact approach.
 */
class PepperstoneQueueIsolationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function pepperstone_job_uses_its_own_queue_distinct_from_ratio_bot_and_forex_grid(): void
    {
        $pepperstoneJob = new RunPepperstoneBotEvaluationJob('00000000-0000-0000-0000-000000000000');
        $ratioBotJob = new RunRatioBotEvaluationJob('00000000-0000-0000-0000-000000000000');
        $forexGridJob = new RunForexGridBotEvaluationJob('00000000-0000-0000-0000-000000000000');

        $this->assertSame('pepperstone', $pepperstoneJob->queue);
        $this->assertSame('ratio_bot', $ratioBotJob->queue);
        $this->assertSame('forex_grid', $forexGridJob->queue);
        $this->assertNotSame($pepperstoneJob->queue, $ratioBotJob->queue);
        $this->assertNotSame($pepperstoneJob->queue, $forexGridJob->queue);
    }

    /** @test */
    public function job_source_declares_the_pepperstone_queue_not_ratio_bot_or_forex_grid(): void
    {
        $source = file_get_contents(app_path('Jobs/RunPepperstoneBotEvaluationJob.php'));

        $this->assertStringContainsString("onQueue('pepperstone')", $source);
        $this->assertStringNotContainsString("onQueue('ratio_bot')", $source);
        $this->assertStringNotContainsString("onQueue('forex_grid')", $source);
    }
}
