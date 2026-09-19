<?php

namespace App\Domain\Nexus;

use App\Models\NexusEntry;
use App\Models\NexusModelCall;
use App\Models\NexusRun;
use Illuminate\Support\Facades\Storage;

final class NexusReportService
{
    public function __construct(private readonly NexusStore $store, private readonly NexusCapabilityRegistryService $capabilities) {}

    public function bundle(string $workspace): array
    {
        $candidates = NexusEntry::where('workspace_id', $workspace)->where('kind', 'candidate')->latest()->limit(200)->get();

        return [
            'schema_version' => 1, 'workspace_id' => $workspace, 'generated_at' => now()->toIso8601String(),
            'project_goal' => 'Observe, research, discover and recommend evidence-backed AutoFinX improvements.',
            'architecture' => '15-minute dispatch; isolated research/training workers; operator-reviewed proposals.',
            'current_capabilities' => $this->capabilities->snapshot(),
            'existing_improvements' => $candidates->filter(fn ($c) => $c->payload['type'] === 'EXISTING_IMPROVEMENT')->values()->toArray(),
            'what_autofinx_should_build_next' => $candidates->filter(fn ($c) => $c->payload['type'] !== 'EXISTING_IMPROVEMENT')->values()->toArray(),
            'accepted_decisions' => $candidates->where('status', 'APPROVED')->values()->toArray(),
            'rejected_decisions' => $candidates->where('status', 'REJECTED')->values()->toArray(),
            'research_candidates' => $candidates->where('evidence_level', 'C')->values()->toArray(),
            'prototype_candidates' => $candidates->where('evidence_level', 'B')->values()->toArray(),
            'validated_candidates' => $candidates->where('evidence_level', 'A')->values()->toArray(),
            'system_performance' => NexusRun::where('workspace_id', $workspace)->latest()->limit(20)->get()->toArray(),
            'model_performance' => ['live_validation' => 'UNAVAILABLE', 'training' => 'ADVISORY_ONLY'],
            'cost' => $this->cost($workspace), 'safety_state' => $this->store->safety(),
            'known_limitations' => ['Bounded export: latest 200 candidates and 20 runs.',
                'No automatic code implementation, live tuning, model training or trading.',
                'Research hypotheses require independent experimental evidence.'],
        ];
    }

    public function cost(string $workspace): array
    {
        return ['reserved_today_usd' => (float) NexusModelCall::where('workspace_id', $workspace)->where('created_at', '>=', now()->startOfDay())->sum('reserved_usd'),
            'calls_today' => NexusModelCall::where('workspace_id', $workspace)->where('created_at', '>=', now()->startOfDay())->count(),
            'daily_budget_usd' => config('nexus.llm.daily_budget'), 'monthly_budget_usd' => config('nexus.llm.monthly_budget'),
            'accounting' => 'Conservative reservations, not provider invoices. Global limits apply across workspaces.'];
    }

    public function publish(string $workspace, bool $weekly = false): NexusEntry
    {
        $key = $weekly ? now()->format('o-W') : now()->format('Y-m-d');
        $kind = $weekly ? 'weekly-report' : 'daily-report';

        return $this->store->put($workspace, $kind, $key, $weekly ? 'Weekly Innovation Report' : 'Daily Intelligence Report', $this->bundle($workspace), 'GENERATED');
    }

    public function exportKnowledge(string $workspace): string
    {
        $path = 'autofinx-nexus/'.$workspace.'/knowledge/autofinx-nexus-knowledge-latest.json';
        if (! Storage::disk('local')->put($path, json_encode($this->bundle($workspace), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT))) {
            throw new \RuntimeException('Nexus knowledge export could not be stored.');
        }

        return $path;
    }

    public function markdown(array $bundle): string
    {
        $text = "# AutoFinX Nexus\n\nGenerated: ".($bundle['generated_at'] ?? now()->toIso8601String())."\n";
        foreach (['existing_improvements' => 'Existing AutoFinX improvements', 'what_autofinx_should_build_next' => 'What AutoFinX should build next'] as $key => $title) {
            $text .= "\n## {$title}\n\n";
            foreach ($bundle[$key] ?? [] as $item) {
                $text .= '- '.$item['title'].' — Evidence '.$item['evidence_level'].' / '.$item['status']."\n";
                $text .= '  '.($item['payload']['idea'] ?? '')."\n";
            }
        }

        return $text."\n## Evidence and context\n\n```json\n".json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n```\n";
    }
}
