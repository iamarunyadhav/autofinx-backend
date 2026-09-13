<?php

namespace Tests\Unit\AuditResearch;

use App\Domain\AuditResearch\Export\PdfAuditExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Same sample-report fixture shape as ExportersTest, extended to the fourth
 * (PDF) exporter -- barryvdh/laravel-dompdf installed successfully in this
 * environment (see composer.lock: dompdf/dompdf v3.1.6), so this renders a
 * real PDF, not a stub.
 */
class PdfAuditExporterTest extends TestCase
{
    use RefreshDatabase;

    private function sampleReport(): array
    {
        return [
            'session' => ['id' => 's1', 'name' => 'Test Session'],
            'executive_summary' => ['total_events' => 2, 'total_trades' => 1],
            'trading_summary' => ['trade_count' => 1],
            'prediction_analysis' => [
                ['horizon_seconds' => 3600, 'hit_rate_pct' => 60.0, 'sample_size' => 10, 'computed_at' => 'now'],
            ],
            'incident_analysis' => [
                ['severity' => 'high', 'title' => 'Stale feed', 'root_cause_category' => 'DATA_STALE', 'occurrence_count' => 2, 'self_recovered' => true],
            ],
            'financial_impact' => ['realized_pnl' => 12.5],
            'health' => ['blockers' => []],
            'blockers' => [],
            'recommendations' => ['Increase polling frequency.'],
            'events' => [],
            'decisions' => [],
            'leaked' => ['api_key' => 'sk-should-not-appear', 'password' => 'hunter2', 'auth_token' => 'abc123'],
        ];
    }

    /** @test */
    public function it_produces_non_empty_valid_looking_pdf_bytes(): void
    {
        $pdf = app(PdfAuditExporter::class)->export($this->sampleReport());

        $this->assertNotEmpty($pdf);
        $this->assertStringStartsWith('%PDF-', $pdf);
    }

    /** @test */
    public function it_never_emits_a_secret_shaped_field_in_the_rendered_pdf(): void
    {
        $pdf = app(PdfAuditExporter::class)->export($this->sampleReport());

        foreach (['sk-should-not-appear', 'hunter2', 'abc123'] as $secret) {
            $this->assertStringNotContainsString($secret, $pdf);
        }
    }
}
