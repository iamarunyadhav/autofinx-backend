<?php

namespace App\Http\Controllers\Api;

use App\Domain\Nexus\NexusCycleService;
use App\Domain\Nexus\NexusReportService;
use App\Domain\Nexus\NexusStore;
use App\Http\Controllers\Controller;
use App\Jobs\RunNexusExperimentJob;
use App\Models\NexusEntry;
use App\Models\NexusReview;
use App\Models\NexusRun;
use App\Models\WorkspaceMember;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

class NexusController extends Controller
{
    private function authorizeWorkspace(Request $request, string $workspace, bool $write = false): void
    {
        $member = WorkspaceMember::where('workspace_id', $workspace)->where('user_id', $request->user()?->id)->first();
        abort_unless($member, 403);
        abort_if($write && ! in_array(strtolower($member->role), ['owner', 'admin'], true), 403, 'Workspace owner or admin required.');
        abort_unless(Schema::hasTable('nexus_runs'), 503, 'Nexus database migration is pending.');
    }

    public function index(Request $request, string $workspace, NexusStore $store, NexusReportService $reports)
    {
        $this->authorizeWorkspace($request, $workspace);

        return response()->json(['enabled' => config('nexus.enabled'), 'cadence_minutes' => 15, 'safety' => $store->safety(),
            'runs' => NexusRun::where('workspace_id', $workspace)->latest()->limit(20)->get(),
            'cost' => $reports->cost($workspace), 'sources' => collect(config('nexus.sources'))->map(fn ($s, $key) => ['key' => $key] + $s)->values(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function entries(Request $request, string $workspace)
    {
        $this->authorizeWorkspace($request, $workspace);
        $data = $request->validate(['kind' => 'required|in:capability,observation,source,candidate,experiment,daily-report,weekly-report', 'page' => 'nullable|integer|min:1']);

        return response()->json(NexusEntry::where('workspace_id', $workspace)->where('kind', $data['kind'])->latest()->orderBy('id')->paginate(20))
            ->header('Cache-Control', 'private, no-store');
    }

    public function run(Request $request, string $workspace, NexusCycleService $cycles)
    {
        $this->authorizeWorkspace($request, $workspace, true);
        abort_unless(config('nexus.enabled'), 409, 'Nexus is disabled in server configuration.');

        return response()->json(['run' => $cycles->dispatch($workspace)], 202);
    }

    public function review(Request $request, string $workspace, string $entry)
    {
        $this->authorizeWorkspace($request, $workspace, true);
        $data = $request->validate(['action' => 'required|in:APPROVED,REJECTED', 'reason' => 'required|string|min:3|max:2000']);
        $result = DB::transaction(function () use ($workspace, $entry, $data, $request) {
            $candidate = NexusEntry::where('workspace_id', $workspace)->where('kind', 'candidate')->lockForUpdate()->findOrFail($entry);
            if ($candidate->status !== $data['action']) {
                NexusReview::create(['workspace_id' => $workspace, 'entry_id' => $entry, 'user_id' => $request->user()->id,
                    'action' => $data['action'], 'reason' => $data['reason']]);
                $candidate->update(['status' => $data['action']]);
            }

            return $candidate;
        });

        return response()->json($result);
    }

    public function experiment(Request $request, string $workspace, NexusStore $store)
    {
        $this->authorizeWorkspace($request, $workspace, true);
        abort_unless(config('nexus.enabled') && config('nexus.autonomy') >= 2, 409, 'Experiments require Nexus autonomy level 2.');
        $data = $request->validate(['candidate_id' => 'required|uuid', 'exchange' => 'required|in:binance,binance_futures',
            'symbol' => 'required|regex:/^[A-Z0-9]{3,24}$/', 'timeframe' => 'required|in:1m,5m,15m,1h',
            'horizon_seconds' => 'required|integer|min:60|max:3600', 'from' => 'required|date', 'to' => 'required|date|after:from|before:now',
            'round_trip_cost_bps' => 'required|numeric|min:0.1|max:1000']);
        NexusEntry::where('workspace_id', $workspace)->where('kind', 'candidate')->findOrFail($data['candidate_id']);
        abort_unless(in_array(config('queue.connections.'.config('nexus.connection').'.driver'), ['redis', 'database'], true), 409, 'Durable queue required.');
        abort_unless((int) config('queue.connections.'.config('nexus.connection').'.retry_after') > 110, 409, 'Queue retry_after must exceed worker timeout.');
        abort_if(Queue::connection(config('nexus.connection'))->size(config('nexus.training_queue')) >= config('nexus.max_pending'), 429, 'Experiment queue is full.');
        $entry = NexusEntry::firstOrCreate(['workspace_id' => $workspace, 'kind' => 'experiment', 'fingerprint' => hash('sha256', json_encode($data))],
            ['title' => 'Forecast proxy: '.$data['symbol'].' '.$data['timeframe'], 'status' => 'QUEUED', 'evidence_level' => 'C', 'payload' => $data]);
        if ($entry->wasRecentlyCreated) {
            try {
                RunNexusExperimentJob::dispatch($entry->id)->onConnection(config('nexus.connection'))->onQueue(config('nexus.training_queue'));
            } catch (\Throwable $e) {
                $entry->update(['status' => 'FAILED']);
                throw $e;
            }
        }

        return response()->json($entry, 202);
    }

    public function export(Request $request, string $workspace, NexusReportService $reports)
    {
        $this->authorizeWorkspace($request, $workspace);
        $data = $request->validate(['format' => 'nullable|in:json,markdown,pdf', 'candidate_id' => 'nullable|uuid']);
        $bundle = $reports->bundle($workspace);
        if (! empty($data['candidate_id'])) {
            $candidate = NexusEntry::where('workspace_id', $workspace)->where('kind', 'candidate')->findOrFail($data['candidate_id']);
            abort_unless($candidate->status === 'APPROVED', 409, 'Approve the recommendation before exporting a build package.');
            $bundle = ['problem' => $candidate->title, 'research' => $candidate->payload, 'architecture_context' => $bundle['current_capabilities'],
                'acceptance_criteria' => ['Verify the evidence against current source code.', 'Preserve workspace isolation and risk checks.', 'Run backend tests and frontend build/lint.'],
                'risk_constraints' => $bundle['safety_state'], 'approval_scope' => 'Implementation proposal only; no production deployment or live trading authorization.'];
        }
        $format = $data['format'] ?? 'json';
        $content = $format === 'json' ? json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : $reports->markdown($bundle);
        if ($format === 'pdf') {
            $content = app('dompdf.wrapper')->setOptions(['isRemoteEnabled' => false])->loadHTML('<meta charset="utf-8"><pre style="font-family:DejaVu Sans;font-size:9px;white-space:pre-wrap">'.e($content).'</pre>')->output();
        }

        return response($content)->header('Content-Type', ['json' => 'application/json', 'markdown' => 'text/markdown', 'pdf' => 'application/pdf'][$format])
            ->header('Content-Disposition', 'attachment; filename="nexus-export.'.($format === 'markdown' ? 'md' : $format).'"')->header('Cache-Control', 'private, no-store');
    }
}
