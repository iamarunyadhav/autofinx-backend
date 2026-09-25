<?php

namespace App\Http\Controllers\Api;

use App\Domain\Ops\KillSwitchService;
use App\Domain\Ops\DeploymentReadinessService;
use App\Domain\Ops\ChaosSimulationService;
use App\Domain\Ops\IncidentService;
use App\Domain\Ops\MaintenanceConfidenceService;
use App\Domain\Ops\OperationalActionService;
use App\Domain\Ops\OperationalDriftDetectionService;
use App\Domain\Ops\OperationalHealthService;
use App\Domain\Ops\OperationalTrendService;
use App\Domain\Ops\OperationalReadinessChecklistService;
use App\Domain\Ops\OperationalBreakerService;
use App\Domain\MarketData\Enums\StreamType;
use App\Domain\MarketData\MarketFreshnessService;
use App\Domain\MarketData\MarketStateStore;
use App\Domain\Ops\RecoveryDrillService;
use App\Domain\Ops\ReleaseGovernanceService;
use App\Domain\Ops\RunbookService;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Bot;
use App\Models\ExchangeConnection;
use App\Models\Incident;
use App\Models\OperationalReadinessSnapshot;
use App\Models\WorkspaceMember;
use App\Models\Order;
use App\Models\Payment;
use App\Models\SchedulerRun;
use App\Support\OrderStatusPoller;
use App\Models\WebhookInbox;
use App\Models\MarketTick;
use App\Models\MarketFeedStatus;
use App\Models\WithdrawalRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class OpsController extends Controller
{
    public function health()
    {
        return response()->json([
            'status' => 'ok',
            'db' => DB::connection()->getPdo() ? 'up' : 'down',
            'time' => now(),
            'metrics' => [
                'live_running_bots' => Bot::where('is_live', true)->whereIn('status', ['RUNNING', 'running'])->count(),
                'open_live_orders' => Order::where('meta->mode', 'live')
                    ->whereNotIn('status', ['FILLED', 'filled', 'FAILED', 'failed', 'CANCELLED', 'REJECTED'])
                    ->count(),
                'pending_payments' => Payment::whereIn('status', ['pending', 'pending_provider', 'pending_webhook'])->count(),
                'pending_withdrawals' => WithdrawalRequest::whereIn('status', [
                    WithdrawalRequest::STATUS_UNDER_REVIEW,
                    WithdrawalRequest::STATUS_APPROVED,
                    WithdrawalRequest::STATUS_SUBMITTED_TO_PROVIDER,
                    WithdrawalRequest::STATUS_PROVIDER_PENDING,
                ])->count(),
            ],
        ]);
    }

    public function auditLogs()
    {
        return AuditLog::orderByDesc('created_at')->limit(200)->get();
    }

    public function schedulerRuns()
    {
        return response()->json([
            'runs' => SchedulerRun::orderByDesc('started_at')->limit(50)->get(),
        ]);
    }

    public function pollOrders(Request $request, OrderStatusPoller $poller)
    {
        $limit = (int) $request->input('limit', 50);
        $summary = $poller->poll($limit);
        return response()->json($summary);
    }

    public function providerHealth(OperationalBreakerService $breakers, MarketFreshnessService $freshness, MarketStateStore $marketState)
    {
        $webhooks = WebhookInbox::query()
            ->select('source')
            ->selectRaw('MAX(received_at) as last_received_at')
            ->selectRaw('SUM(CASE WHEN received_at >= ? THEN 1 ELSE 0 END) as count_24h', [now()->subDay()])
            ->groupBy('source')
            ->get();

        $lastTick = MarketTick::orderByDesc('occurred_at')->first();
        $stale = false;
        if ($lastTick && $lastTick->occurred_at) {
            $stale = now()->diffInSeconds($lastTick->occurred_at) > 60;
        }

        $connections = ExchangeConnection::where('provider', 'binance')
            ->where('is_active', true)
            ->get()
            ->map(fn (ExchangeConnection $connection) => [
                'connection_id' => $connection->id,
                'workspace_id' => $connection->workspace_id,
                'stream' => $breakers->streamState($connection),
                'provider_breaker' => $breakers->providerState((string) $connection->workspace_id, 'binance'),
            ]);

        $configuredInstruments = collect(config('market_data.instruments', []))
            ->keys()
            ->merge(MarketFeedStatus::query()->whereNotNull('symbol')->pluck('symbol'))
            ->map(fn ($symbol) => strtoupper((string) $symbol))
            ->filter()
            ->unique()
            ->values();

        $providers = collect(config('market_data.providers', []))
            ->map(function (array $definition, string $provider) use ($configuredInstruments, $freshness, $marketState) {
                $instruments = $configuredInstruments
                    ->filter(function (string $instrument) use ($definition): bool {
                        $marketType = (string) data_get(config('market_data.instruments', []), "{$instrument}.market_type", 'CRYPTO');

                        return in_array($marketType, (array) ($definition['market_types'] ?? []), true);
                    })
                    ->map(function (string $instrument) use ($provider, $freshness, $marketState) {
                        $health = $freshness->evaluate($provider, $instrument, StreamType::QUOTE);
                        $quote = $marketState->quote($provider, $instrument);

                        return [
                            'instrument' => $instrument,
                            'market_type' => (string) data_get(config('market_data.instruments', []), "{$instrument}.market_type", 'CRYPTO'),
                            'quote' => [
                                'state' => $health['state'] ?? 'CONNECTING',
                                'age_ms' => $health['data_age_ms'] ?? $health['received_age_ms'] ?? null,
                                'bid' => $quote['bid'] ?? null,
                                'ask' => $quote['ask'] ?? null,
                                'mid' => $quote['mid'] ?? null,
                                'last' => $quote['last'] ?? null,
                            ],
                            'health' => $health,
                        ];
                    })
                    ->values()
                    ->all();

                return [
                    'provider' => $provider,
                    'capabilities' => (array) ($definition['capabilities'] ?? []),
                    'instruments' => $instruments,
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'webhooks' => $webhooks,
            'ticks' => [
                'last_tick_at' => $lastTick?->occurred_at,
                'symbol_id' => $lastTick?->symbol_id,
                'stale' => $stale,
            ],
            'breakers' => [
                'stale_feed' => $breakers->staleFeedState(null),
            ],
            'binance_connections' => $connections,
            'providers' => $providers,
        ]);
    }

    public function doctorCleanup(Request $request)
    {
        @set_time_limit(180);

        $data = $request->validate([
            'sleep' => 'nullable|integer|min:3|max:45',
            'skip_migrate' => 'nullable|boolean',
        ]);

        try {
            Artisan::call('doctor_cleanup', [
                '--json' => true,
                '--sleep' => (int) ($data['sleep'] ?? 15),
                '--skip-migrate' => (bool) ($data['skip_migrate'] ?? false),
            ]);

            $raw = trim(Artisan::output());
            $payload = json_decode($raw, true);

            if (! is_array($payload)) {
                return response()->json([
                    'ok' => false,
                    'status' => 'invalid_output',
                    'message' => 'doctor_cleanup finished but returned invalid JSON.',
                    'raw' => $raw,
                ], 500);
            }

            return response()->json($payload, ($payload['ok'] ?? false) ? 200 : 207);
        } catch (Throwable $e) {
            return response()->json([
                'ok' => false,
                'status' => 'failed',
                'message' => $e->getMessage(),
                'steps' => [],
            ], 500);
        }
    }

    public function killSwitch(Request $request)
    {
        $data = $request->validate([
            'workspace_id' => 'required|uuid',
            'enabled' => 'required|boolean',
            'reason' => 'nullable|string|max:240',
        ]);

        // Body workspace_id: the caller must be a member, otherwise anyone
        // could halt (or re-enable) another workspace's live trading.
        $this->assertWorkspaceAccess((string) $data['workspace_id'], $request->user()?->id);

        $service = app(KillSwitchService::class);
        $reason = trim((string) ($data['reason'] ?? 'manual'));
        $state = $data['enabled']
            ? $service->deactivate($data['workspace_id'], $request->user()?->id, $reason)
            : $service->activate($data['workspace_id'], $request->user()?->id, $reason);

        return response()->json([
            'workspace_id' => $data['workspace_id'],
            'kill_switch_active' => (bool) ($state['active'] ?? false),
            'global_trading_enabled' => ! (bool) ($state['active'] ?? false),
            'state' => $state,
        ]);
    }

    public function providerBreaker(Request $request, OperationalBreakerService $breakers)
    {
        $data = $request->validate([
            'workspace_id' => 'nullable|uuid',
            'provider' => 'required|string|max:40',
            'enabled' => 'required|boolean',
            'reason' => 'nullable|string|max:240',
        ]);

        $reason = trim((string) ($data['reason'] ?? 'manual'));
        $workspaceId = isset($data['workspace_id']) ? (string) $data['workspace_id'] : null;
        $provider = strtolower((string) $data['provider']);

        // A workspace-scoped breaker is that workspace's own control. (The
        // workspace-less, platform-wide variant has no owning workspace to
        // check against -- see the platform-admin note in the audit report.)
        if ($workspaceId !== null) {
            $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);
        }

        $state = $data['enabled']
            ? $breakers->activateProviderOutage($workspaceId, $provider, $reason, 'manual')
            : $breakers->clearProviderOutage($workspaceId, $provider, $reason, 'manual');

        return response()->json([
            'workspace_id' => $workspaceId,
            'provider' => $provider,
            'state' => $state,
        ]);
    }

    public function workspaceReadiness(string $workspaceId, OperationalReadinessChecklistService $checklist)
    {
        $this->assertWorkspaceAccess($workspaceId, request()->user()?->id);

        return response()->json($checklist->evaluate($workspaceId, false));
    }

    public function runWorkspaceReadiness(Request $request, string $workspaceId, OperationalReadinessChecklistService $checklist)
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);

        $result = $checklist->evaluate($workspaceId, true, $request->user()?->id, 'manual_operator_check');

        return response()->json($result);
    }

    public function deploymentReadiness(string $workspaceId, DeploymentReadinessService $deployment)
    {
        $this->assertWorkspaceAccess($workspaceId, request()->user()?->id);

        return response()->json($deployment->state($workspaceId));
    }

    public function workspaceHealth(string $workspaceId, OperationalHealthService $health)
    {
        $this->assertWorkspaceAccess($workspaceId, request()->user()?->id);

        return response()->json($health->summary($workspaceId));
    }

    public function readinessHistory(Request $request, string $workspaceId, OperationalReadinessChecklistService $checklist)
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);

        $limit = (int) $request->query('limit', 20);

        return response()->json($checklist->history($workspaceId, max(1, min(100, $limit))));
    }

    public function incidents(Request $request, string $workspaceId, IncidentService $incidents)
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);

        $data = $request->validate([
            'status' => 'nullable|string|max:24',
            'severity' => 'nullable|string|max:16',
            'kind' => 'nullable|string|max:64',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        return response()->json([
            'incidents' => $incidents->list($workspaceId, $data)->values()->all(),
        ]);
    }

    public function incident(Request $request, string $workspaceId, Incident $incident)
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);
        abort_unless((string) $incident->workspace_id === (string) $workspaceId, 404);

        return response()->json($incident->load(['runbook', 'events', 'actions']));
    }

    public function acknowledgeIncident(Request $request, string $workspaceId, Incident $incident, IncidentService $incidents)
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);
        abort_unless((string) $incident->workspace_id === (string) $workspaceId, 404);
        $data = $request->validate([
            'note' => 'nullable|string|max:500',
        ]);

        return response()->json([
            'incident' => $incidents->acknowledge($incident, $request->user()?->id, $data['note'] ?? null),
        ]);
    }

    public function resolveIncident(Request $request, string $workspaceId, Incident $incident, IncidentService $incidents)
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);
        abort_unless((string) $incident->workspace_id === (string) $workspaceId, 404);
        $data = $request->validate([
            'summary' => 'nullable|string|max:500',
        ]);

        return response()->json([
            'incident' => $incidents->resolve($incident, $request->user()?->id, $data['summary'] ?? null),
        ]);
    }

    public function runbooks(Request $request, string $workspaceId, RunbookService $runbooks)
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);

        $data = $request->validate([
            'category' => 'nullable|string|max:64',
        ]);

        return response()->json([
            'runbooks' => $runbooks->list($workspaceId, $data['category'] ?? null)->values()->all(),
        ]);
    }

    public function runbook(Request $request, string $workspaceId, string $key, RunbookService $runbooks)
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);

        $runbook = $runbooks->find($key, $workspaceId);
        abort_unless($runbook, 404);

        return response()->json($runbook);
    }

    public function readinessSnapshots(Request $request, string $workspaceId)
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);

        $limit = (int) $request->query('limit', 20);

        return response()->json([
            'snapshots' => OperationalReadinessSnapshot::query()
                ->where('workspace_id', $workspaceId)
                ->orderByDesc('checked_at')
                ->limit(max(1, min(100, $limit)))
                ->get(),
        ]);
    }

    public function trends(Request $request, string $workspaceId, OperationalTrendService $trends)
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);

        $windowDays = (int) $request->query('window_days', config('ops.maintenance.trend_window_days', 7));

        return response()->json($trends->summary($workspaceId, max(1, min(30, $windowDays))));
    }

    public function driftDetections(Request $request, string $workspaceId, OperationalDriftDetectionService $drift)
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);

        $limit = (int) $request->query('limit', 20);

        return response()->json([
            'current' => $drift->openDetections($workspaceId),
            'history' => $drift->history($workspaceId, max(1, min(100, $limit))),
        ]);
    }

    public function maintenanceConfidence(Request $request, string $workspaceId, MaintenanceConfidenceService $confidence)
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);

        $limit = (int) $request->query('limit', 20);

        return response()->json([
            'current' => $confidence->summary($workspaceId),
            'history' => $confidence->history($workspaceId, max(1, min(100, $limit))),
        ]);
    }

    public function drillScenarios(Request $request, string $workspaceId, RecoveryDrillService $drills)
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);

        return response()->json([
            'scenarios' => $drills->scenarios(),
        ]);
    }

    public function drills(Request $request, string $workspaceId, RecoveryDrillService $drills)
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);

        $limit = (int) $request->query('limit', 20);

        return response()->json([
            'drills' => $drills->history($workspaceId, max(1, min(100, $limit)))['drills'] ?? [],
            'reviews' => $drills->reviews($workspaceId, max(1, min(100, $limit)))['reviews'] ?? [],
        ]);
    }

    public function runDrill(Request $request, string $workspaceId, RecoveryDrillService $drills)
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);

        $data = $request->validate([
            'scenario_key' => 'required|string|max:120',
            'allow_live' => 'nullable|boolean',
        ]);

        return response()->json($drills->run($workspaceId, $data['scenario_key'], $request->user()?->id, [
            'allow_live' => (bool) ($data['allow_live'] ?? false),
        ]));
    }

    public function chaosScenarios(Request $request, string $workspaceId, ChaosSimulationService $chaos)
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);

        return response()->json([
            'scenarios' => $chaos->scenarios(),
        ]);
    }

    public function chaosSimulations(Request $request, string $workspaceId, ChaosSimulationService $chaos)
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);

        $limit = (int) $request->query('limit', 20);

        return response()->json($chaos->history($workspaceId, max(1, min(100, $limit))));
    }

    public function runChaosSimulation(Request $request, string $workspaceId, ChaosSimulationService $chaos)
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);

        $data = $request->validate([
            'scenario_key' => 'required|string|max:120',
            'acknowledged_risk' => 'nullable|boolean',
        ]);

        return response()->json($chaos->run($workspaceId, $data['scenario_key'], $request->user()?->id, [
            'acknowledged_risk' => (bool) ($data['acknowledged_risk'] ?? false),
        ]));
    }

    public function releases(Request $request, string $workspaceId, ReleaseGovernanceService $releases)
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);

        $limit = (int) $request->query('limit', 20);

        return response()->json($releases->listReleases($workspaceId, max(1, min(100, $limit))));
    }

    public function createRelease(Request $request, string $workspaceId, ReleaseGovernanceService $releases)
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);

        $data = $request->validate([
            'release_key' => 'nullable|string|max:120',
            'version' => 'required|string|max:80',
            'environment' => 'nullable|string|max:32',
            'status' => 'nullable|string|max:24',
            'summary' => 'nullable|string|max:500',
            'change_summary_json' => 'nullable|array',
            'metadata_json' => 'nullable|array',
            'blocked_reason' => 'nullable|string|max:500',
        ]);

        return response()->json($releases->recordRelease($workspaceId, $data, $request->user()?->id));
    }

    public function verifyRelease(Request $request, string $workspaceId, string $releaseId, ReleaseGovernanceService $releases)
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);

        $data = $request->validate([
            'status' => 'nullable|string|max:24',
            'summary' => 'nullable|string|max:500',
            'checklist_json' => 'nullable|array',
            'evidence_json' => 'nullable|array',
        ]);

        return response()->json($releases->verifyRelease($workspaceId, $releaseId, $data, $request->user()?->id));
    }

    public function rollbacks(Request $request, string $workspaceId, ReleaseGovernanceService $releases)
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);

        $limit = (int) $request->query('limit', 20);

        return response()->json($releases->listRollbacks($workspaceId, max(1, min(100, $limit))));
    }

    public function createRollback(Request $request, string $workspaceId, ReleaseGovernanceService $releases)
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);

        $data = $request->validate([
            'release_record_id' => 'nullable|uuid',
            'rollback_key' => 'nullable|string|max:120',
            'status' => 'nullable|string|max:24',
            'reason' => 'nullable|string|max:500',
            'summary' => 'nullable|string|max:500',
            'blocked_reason' => 'nullable|string|max:500',
            'details_json' => 'nullable|array',
            'evidence_json' => 'nullable|array',
        ]);

        return response()->json($releases->recordRollback($workspaceId, $data, $request->user()?->id));
    }

    public function postIncidentReviews(Request $request, string $workspaceId, RecoveryDrillService $drills)
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);

        $limit = (int) $request->query('limit', 20);

        return response()->json($drills->reviews($workspaceId, max(1, min(100, $limit))));
    }

    public function recordPostIncidentReview(Request $request, string $workspaceId, RecoveryDrillService $drills)
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);

        $data = $request->validate([
            'incident_id' => 'required|uuid',
            'root_cause_hypothesis' => 'nullable|string|max:1000',
            'contributing_factors_json' => 'nullable|array',
            'detection_summary' => 'nullable|string|max:1000',
            'mitigation_summary' => 'nullable|string|max:1000',
            'lessons_learned' => 'nullable|string|max:1000',
            'follow_up_actions_json' => 'nullable|array',
            'evidence_json' => 'nullable|array',
        ]);

        return response()->json($drills->recordReview($workspaceId, $data['incident_id'], $data, $request->user()?->id));
    }

    public function action(Request $request, string $workspaceId, OperationalActionService $actions)
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);

        $data = $request->validate([
            'action_key' => 'required|string|max:64',
            'reason' => 'nullable|string|max:240',
            'limit' => 'nullable|integer|min:1|max:500',
            'integration_id' => 'nullable|uuid',
            'symbol' => 'nullable|string|max:32',
        ]);

        return response()->json([
            'result' => $actions->execute($workspaceId, $data['action_key'], $data, $request->user()?->id),
        ]);
    }

    private function assertWorkspaceAccess(string $workspaceId, ?string $userId): void
    {
        abort_if(! $userId, 401, 'Authentication required.');

        $role = WorkspaceMember::query()
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $userId)
            ->value('role');

        abort_if(! $role, 403, 'You are not a member of this workspace.');
    }
}
