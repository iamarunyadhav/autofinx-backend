<?php

namespace Tests\Unit\AuditResearch;

use App\Domain\AuditResearch\Export\CsvAuditExporter;
use App\Domain\AuditResearch\Export\JsonAuditExporter;
use App\Domain\AuditResearch\Export\MarkdownAuditExporter;
use Tests\TestCase;

class ExportersTest extends TestCase
{
    private function sampleReport(): array
    {
        return [
            'session' => ['id' => 's1', 'name' => 'Test Session'],
            'executive_summary' => ['total_events' => 2, 'total_trades' => 1],
            'trading_summary' => ['trade_count' => 1],
            'prediction_analysis' => [
                ['horizon_seconds' => 3600, 'hit_rate_pct' => 60.0, 'sample_size' => 10, 'computed_at' => 'now'],
            ],
            'incident_analysis' => [],
            'financial_impact' => ['realized_pnl' => 12.5],
            'health' => ['blockers' => []],
            'blockers' => [],
            'recommendations' => [],
            'events' => [
                ['id' => 'e1', 'occurred_at' => 'now', 'event_type' => 'market_tick_received', 'event_category' => 'market', 'severity' => 'info', 'correlation_id' => 'c1', 'symbol' => 'BTCUSDT', 'market_price' => 50000],
            ],
            'decisions' => [
                ['id' => 'd1', 'correlation_id' => 'c1', 'decision' => 'BUY', 'evaluated_price' => 50000, 'execution_status' => 'filled', 'reason' => 'ok', 'decided_at' => 'now'],
            ],
            // Fields shaped like secrets to prove the scrub runs even if a
            // caller somehow passes one through.
            'leaked' => ['api_key' => 'sk-should-not-appear', 'password' => 'hunter2', 'auth_token' => 'abc123'],
        ];
    }

    /** @test */
    public function csv_export_is_non_empty_and_well_formed(): void
    {
        $csv = (new CsvAuditExporter())->export($this->sampleReport());

        $this->assertNotEmpty($csv);
        $this->assertStringContainsString('market_tick_received', $csv);
        $this->assertStringContainsString('BUY', $csv);
    }

    /** @test */
    public function json_export_is_non_empty_and_well_formed(): void
    {
        $json = (new JsonAuditExporter())->export($this->sampleReport());

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertSame('Test Session', $decoded['session']['name']);
    }

    /** @test */
    public function markdown_export_is_non_empty_and_well_formed(): void
    {
        $md = (new MarkdownAuditExporter())->export($this->sampleReport());

        $this->assertNotEmpty($md);
        $this->assertStringContainsString('# Audit Research Report', $md);
        $this->assertStringContainsString('## Events', $md);
        $this->assertStringContainsString('## Decisions / Trades', $md);
    }

    /** @test */
    public function none_of_the_three_exporters_ever_emit_a_secret_shaped_field(): void
    {
        $report = $this->sampleReport();

        $csv = (new CsvAuditExporter())->export($report);
        $json = (new JsonAuditExporter())->export($report);
        $md = (new MarkdownAuditExporter())->export($report);

        foreach (['sk-should-not-appear', 'hunter2', 'abc123'] as $secret) {
            $this->assertStringNotContainsString($secret, $csv);
            $this->assertStringNotContainsString($secret, $json);
            $this->assertStringNotContainsString($secret, $md);
        }
    }
}
