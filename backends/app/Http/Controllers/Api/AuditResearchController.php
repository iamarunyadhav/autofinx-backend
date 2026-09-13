<?php

namespace App\Http\Controllers\Api;

use App\Domain\AuditResearch\AuditReportBuilder;
use App\Domain\AuditResearch\BuildVersionResolver;
use App\Domain\AuditResearch\Export\CsvAuditExporter;
use App\Domain\AuditResearch\Export\JsonAuditExporter;
use App\Domain\AuditResearch\Export\MarkdownAuditExporter;
use App\Domain\AuditResearch\Export\PdfAuditExporter;
use App\Domain\AuditResearch\BuildComparisonService;
use App\Domain\AuditResearch\ProductionReadinessService;
use App\Domain\AuditResearch\PredictionAccuracyService;
use App\Http\Controllers\Controller;
use App\Models\AuditDecisionSnapshot;
use App\Models\AuditEvent;
use App\Models\AuditIncident;
use App\Models\AuditSession;
use App\Models\RatioBotConfig;
use App\Models\RatioBotDecision;
use App\Models\WorkspaceMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * IDOR-safe from day one — same WorkspaceMember membership pattern as every
 * other controller in this app (see RatioBotController::assertBotOwnership).
 */
class AuditResearchController extends Controller
{
    public function index(Request $request, string $workspaceId): JsonResponse
    {
        $this->assertWorkspaceMembership($request, $workspaceId);

        $sessions = AuditSession::query()
            ->where('workspace_id', $workspaceId)
            ->orderByDesc('created_at')
            ->get();

        return response()->json($sessions);
    }

    public function store(Request $request, string $workspaceId): JsonResponse
    {
        $this->assertWorkspaceMembership($request, $workspaceId);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'bot_type' => 'nullable|string|max:32',
            'bot_instance_id' => 'nullable|uuid',
            'environment' => 'nullable|string|in:local,sandbox,testnet,live',
            'symbol' => 'nullable|string|max:32',
            'purpose' => 'nullable|string',
            'mode' => 'nullable|string|in:standard,detailed,deep_diagnostic',
        ]);

        if (! empty($data['bot_instance_id'])) {
            $bot = RatioBotConfig::where('id', $data['bot_instance_id'])
                ->where('workspace_id', $workspaceId)
                ->first();
            abort_unless($bot, 422, 'bot_instance_id does not belong to this workspace.');
        }

        $session = AuditSession::create([
            'workspace_id' => $workspaceId,
            'name' => $data['name'],
            'bot_type' => $data['bot_type'] ?? 'ratio_bot',
            'bot_instance_id' => $data['bot_instance_id'] ?? null,
            'environment' => $data['environment'] ?? 'sandbox',
            'symbol' => $data['symbol'] ?? null,
            'purpose' => $data['purpose'] ?? null,
            'mode' => $data['mode'] ?? AuditSession::MODE_STANDARD,
            'status' => AuditSession::STATUS_RUNNING,
            'started_at' => now(),
            'build_version' => BuildVersionResolver::resolve(),
        ]);

        return response()->json($session, 201);
    }

    public function show(Request $request, AuditSession $session): JsonResponse
    {
        $this->assertWorkspaceMembership($request, (string) $session->workspace_id);

        return response()->json($session);
    }

    public function stop(Request $request, AuditSession $session): JsonResponse
    {
        $this->assertWorkspaceMembership($request, (string) $session->workspace_id);

        $session->update([
            'status' => AuditSession::STATUS_STOPPED,
            'ended_at' => now(),
        ]);

        return response()->json($session->fresh());
    }

    /**
     * Polling-friendly, matching this app's own established "live" UI
     * convention (React Query refetchInterval, no realtime broadcasting
     * anywhere in this app — see reference/conventions.md). ?since_id= or
     * ?since= (ISO timestamp) act as a cursor.
     */
    public function events(Request $request, AuditSession $session): JsonResponse
    {
        $this->assertWorkspaceMembership($request, (string) $session->workspace_id);

        $query = AuditEvent::query()->where('audit_session_id', $session->id);

        if ($request->filled('since_id')) {
            $cursor = AuditEvent::find($request->query('since_id'));
            if ($cursor) {
                $query->where('occurred_at', '>', $cursor->occurred_at);
            }
        } elseif ($request->filled('since')) {
            $query->where('occurred_at', '>', $request->query('since'));
        }

        $events = $query->orderBy('occurred_at')->limit(500)->get();

        return response()->json([
            'events' => $events,
            'next_since_id' => $events->last()?->id,
        ]);
    }

    public function decisions(Request $request, AuditSession $session): JsonResponse
    {
        $this->assertWorkspaceMembership($request, (string) $session->workspace_id);

        if (! $session->bot_instance_id) {
            return response()->json(['decisions' => []]);
        }

        $decisions = RatioBotDecision::query()
            ->where('ratio_bot_config_id', $session->bot_instance_id)
            ->where('decided_at', '>=', $session->started_at ?? $session->created_at)
            ->when($session->ended_at, fn ($q) => $q->where('decided_at', '<=', $session->ended_at))
            ->orderBy('decided_at')
            ->get();

        $snapshots = AuditDecisionSnapshot::query()
            ->whereIn('ratio_bot_decision_id', $decisions->pluck('id'))
            ->get()
            ->keyBy('ratio_bot_decision_id');

        $decisions = $decisions->map(function (RatioBotDecision $decision) use ($snapshots) {
            $payload = $decision->toArray();
            $payload['rule_evaluation'] = $snapshots->get($decision->id);

            return $payload;
        })->values();

        return response()->json(['decisions' => $decisions]);
    }

    public function incidents(Request $request, AuditSession $session): JsonResponse
    {
        $this->assertWorkspaceMembership($request, (string) $session->workspace_id);

        $incidents = AuditIncident::query()
            ->where('workspace_id', $session->workspace_id)
            ->when($session->bot_instance_id, fn ($q) => $q->where('bot_instance_id', $session->bot_instance_id))
            ->orderByDesc('last_detected_at')
            ->get();

        return response()->json(['incidents' => $incidents]);
    }

    public function report(Request $request, AuditSession $session, AuditReportBuilder $builder): JsonResponse
    {
        $this->assertWorkspaceMembership($request, (string) $session->workspace_id);

        return response()->json($builder->buildForSession($session));
    }

    public function exportCsv(Request $request, AuditSession $session, AuditReportBuilder $builder, CsvAuditExporter $exporter): Response
    {
        $this->assertWorkspaceMembership($request, (string) $session->workspace_id);

        $csv = $exporter->export($builder->buildForSession($session));

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="audit-session-' . $session->id . '.csv"',
        ]);
    }

    public function exportJson(Request $request, AuditSession $session, AuditReportBuilder $builder, JsonAuditExporter $exporter): Response
    {
        $this->assertWorkspaceMembership($request, (string) $session->workspace_id);

        $json = $exporter->export($builder->buildForSession($session));

        return response($json, 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="audit-session-' . $session->id . '.json"',
        ]);
    }

    public function exportMarkdown(Request $request, AuditSession $session, AuditReportBuilder $builder, MarkdownAuditExporter $exporter): Response
    {
        $this->assertWorkspaceMembership($request, (string) $session->workspace_id);

        $markdown = $exporter->export($builder->buildForSession($session));

        return response($markdown, 200, [
            'Content-Type' => 'text/markdown',
            'Content-Disposition' => 'attachment; filename="audit-session-' . $session->id . '.md"',
        ]);
    }

    public function exportPdf(Request $request, AuditSession $session, AuditReportBuilder $builder, PdfAuditExporter $exporter): Response
    {
        $this->assertWorkspaceMembership($request, (string) $session->workspace_id);

        $pdf = $exporter->export($builder->buildForSession($session));

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="audit-session-' . $session->id . '.pdf"',
        ]);
    }

    public function compare(Request $request, string $workspaceId, BuildComparisonService $service): JsonResponse
    {
        $this->assertWorkspaceMembership($request, $workspaceId);

        $data = $request->validate([
            'session_a' => 'required|uuid',
            'session_b' => 'required|uuid',
        ]);

        $sessionA = AuditSession::findOrFail($data['session_a']);
        $sessionB = AuditSession::findOrFail($data['session_b']);

        abort_unless((string) $sessionA->workspace_id === $workspaceId, 403, 'session_a does not belong to this workspace.');
        abort_unless((string) $sessionB->workspace_id === $workspaceId, 403, 'session_b does not belong to this workspace.');

        return response()->json($service->compare($sessionA, $sessionB));
    }

    public function readiness(Request $request, string $workspaceId, ProductionReadinessService $service): JsonResponse
    {
        $this->assertWorkspaceMembership($request, $workspaceId);

        $botInstanceId = $request->query('bot_instance_id');

        return response()->json($service->evaluate($workspaceId, $botInstanceId ?: null));
    }

    public function workspaceIncidents(Request $request, string $workspaceId): JsonResponse
    {
        $this->assertWorkspaceMembership($request, $workspaceId);

        $incidents = AuditIncident::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('status', AuditIncident::OPEN_STATUSES)
            ->orderByDesc('last_detected_at')
            ->get();

        return response()->json(['incidents' => $incidents]);
    }

    public function predictionAccuracy(Request $request, string $workspaceId, PredictionAccuracyService $service): JsonResponse
    {
        $this->assertWorkspaceMembership($request, $workspaceId);

        return response()->json(['bots' => $service->forWorkspace($workspaceId)]);
    }

    private function assertWorkspaceMembership(Request $request, string $workspaceId): void
    {
        $user = $request->user();

        $isMember = $user && WorkspaceMember::query()
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $user->id)
            ->exists();

        if (! $isMember) {
            abort(403, 'You do not have access to this workspace.');
        }
    }
}
