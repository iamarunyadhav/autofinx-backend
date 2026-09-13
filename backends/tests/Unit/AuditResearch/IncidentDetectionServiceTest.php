<?php

namespace Tests\Unit\AuditResearch;

use App\Domain\AuditResearch\IncidentDetectionService;
use App\Domain\AuditResearch\RootCauseCategory;
use App\Domain\Ops\OperationalBreakerService;
use App\Models\AuditIncident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidentDetectionServiceTest extends TestCase
{
    use RefreshDatabase;

    private IncidentDetectionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new IncidentDetectionService(app(OperationalBreakerService::class));
    }

    /** @test */
    public function a_transient_blip_that_clears_quickly_self_recovers_with_no_lingering_open_incident(): void
    {
        $workspaceId = (string) \Illuminate\Support\Str::uuid();

        $incident = $this->service->evaluateSignal(
            $workspaceId, 'ratio_bot', 'bot-1', 'BTCUSDT',
            RootCauseCategory::DATA_STALE, 'Stale feed', 'medium', true,
        );
        $this->assertSame(AuditIncident::STATUS_DETECTED, $incident->status);
        $this->assertSame(1, AuditIncident::count());

        $recovered = $this->service->evaluateSignal(
            $workspaceId, 'ratio_bot', 'bot-1', 'BTCUSDT',
            RootCauseCategory::DATA_STALE, 'Stale feed', 'medium', false,
        );

        $this->assertSame(AuditIncident::STATUS_RECOVERED, $recovered->status);
        $this->assertTrue($recovered->self_recovered);
        // Still exactly one row — recovery updates the existing row, it
        // never spawns a second one.
        $this->assertSame(1, AuditIncident::count());
    }

    /** @test */
    public function a_repeated_condition_escalates_the_existing_incident_instead_of_creating_duplicates(): void
    {
        $workspaceId = (string) \Illuminate\Support\Str::uuid();

        for ($i = 0; $i < 3; $i++) {
            $incident = $this->service->evaluateSignal(
                $workspaceId, 'ratio_bot', 'bot-1', 'BTCUSDT',
                RootCauseCategory::DATA_STALE, 'Stale feed', 'medium', true,
            );
        }

        $this->assertSame(1, AuditIncident::count(), 'repeated occurrences must escalate the same row, not spawn duplicates');
        $this->assertSame(3, $incident->occurrence_count);
        $this->assertSame(AuditIncident::STATUS_RECURRING, $incident->status);

        for ($i = 0; $i < 3; $i++) {
            $incident = $this->service->evaluateSignal(
                $workspaceId, 'ratio_bot', 'bot-1', 'BTCUSDT',
                RootCauseCategory::DATA_STALE, 'Stale feed', 'medium', true,
            );
        }

        $this->assertSame(1, AuditIncident::count());
        $this->assertSame(6, $incident->occurrence_count);
        $this->assertSame(AuditIncident::STATUS_PERSISTENT, $incident->status);
    }

    /** @test */
    public function clearing_when_nothing_is_open_is_a_no_op(): void
    {
        $result = $this->service->evaluateSignal(
            (string) \Illuminate\Support\Str::uuid(), 'ratio_bot', 'bot-1', 'BTCUSDT',
            RootCauseCategory::DATA_STALE, 'Stale feed', 'medium', false,
        );

        $this->assertNull($result);
        $this->assertSame(0, AuditIncident::count());
    }
}
