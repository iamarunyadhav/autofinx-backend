<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AssertsWorkspaceMembership;
use App\Models\V5ResearchScannerRun;
use App\Models\V5ResearchSelection;
use App\Models\V5ShadowPrediction;
use App\Models\NexusEntry;
use App\Models\NexusReview;
use App\Models\WorkspaceMember;
use App\Domain\Ml\Shadow\V5KnowledgeExportService;
use App\Domain\Ml\Shadow\V5KnowledgeCategorizer;
use App\Domain\Ml\Shadow\V5KnowledgePaperRunner;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class V5ResearchController extends Controller
{
    use AssertsWorkspaceMembership;

    public function overview(): JsonResponse
    {
        return response()->json(['status' => 'RESEARCH_ONLY', 'execution_authorized' => false, 'latest_run' => $this->displayedRun()]);
    }

    public function latest(): JsonResponse
    {
        return response()->json(['execution_authorized' => false, 'run' => $this->displayedRun()]);
    }

    public function candidates(): JsonResponse
    {
        $run = $this->displayedRun();

        return response()->json(['execution_authorized' => false, 'candidates' => $run?->candidates ?? []]);
    }

    public function status(): JsonResponse
    {
        $run = $this->latestRun();

        return response()->json([
            'execution_authorized' => false,
            'data_sources' => $run?->source_status ?? ['status' => 'NO_RUN'],
            'model_status' => 'UNCALIBRATED_RESEARCH_ONLY',
        ]);
    }

    public function history(): JsonResponse
    {
        return response()->json([
            'execution_authorized' => false,
            'predictions' => V5ShadowPrediction::query()->latest('forecast_at')->limit(100)->get(),
        ]);
    }

    public function selections(Request $request, string $workspace): JsonResponse
    {
        $this->assertWorkspaceMembership($request, $workspace);

        return $this->selectionList($request, $workspace);
    }

    public function select(Request $request, string $workspace): JsonResponse
    {
        $this->assertWorkspaceMembership($request, $workspace);

        return $this->createSelection($request, $workspace);
    }

    public function remove(Request $request, string $workspace, string $id): JsonResponse
    {
        $this->assertWorkspaceMembership($request, $workspace);
        $selection = V5ResearchSelection::query()
            ->where('workspace_id', $workspace)
            ->where('user_id', $request->user()->id)
            ->findOrFail($id);
        $selection->update(['status' => 'REMOVED_BY_USER']);

        return response()->json(['execution_authorized' => false, 'selection' => $selection->fresh()]);
    }

    public function selectionMetrics(Request $request, string $workspace): JsonResponse
    {
        $this->assertWorkspaceMembership($request, $workspace);

        return $this->metrics($request, $workspace);
    }

    /** Knowledge proposals are research-only. Approval never promotes a model or enables execution. */
    public function knowledge(Request $request, string $workspace): JsonResponse
    {
        $this->assertWorkspaceMembership($request, $workspace);
        return response()->json([
            'execution_authorized' => false,
            'items' => NexusEntry::query()->where('workspace_id', $workspace)->where('kind', 'candidate')
                ->where('payload->source', 'v5_knowledge_review')->latest()->limit(100)->get(),
        ]);
    }

    /** Read-only boundary for approved knowledge; never feeds baseline ML or live execution. */
    public function knowledgePaperExperiments(Request $request, string $workspace): JsonResponse
    {
        $this->assertWorkspaceMembership($request, $workspace);
        $items = NexusEntry::query()->where('workspace_id', $workspace)->where('kind', 'candidate')
            ->where('payload->source', 'v5_knowledge_review')->latest()->limit(100)->get()
            ->map(function (NexusEntry $item): array {
                $payload = (array) $item->payload;
                $approved = $item->status === 'APPROVED';
                return [
                    'id' => $item->id, 'title' => $item->title,
                    'category' => data_get($payload, 'category', 'OTHER'), 'status' => $item->status,
                    'paper_track' => $approved ? 'READY_FOR_ISOLATED_EXPERIMENT' : 'REVIEW_REQUIRED',
                    'verification' => (array) data_get($payload, 'verification', []),
                    'training_eligible' => false, 'execution_authorized' => false, 'paper_trades' => [],
                    'note' => $approved
                        ? 'Approved for isolated paper experiment review; baseline V5 autopilot is not changed.'
                        : 'Human approval is required before this item can be considered for paper experiments.',
                ];
            })->values();
        return response()->json(['execution_authorized' => false, 'baseline_track' => 'V5 ML Paper Autopilot', 'knowledge_track' => 'V5 Knowledge Experiments', 'items' => $items]);
    }

    public function runKnowledgePaper(Request $request, string $workspace, string $entry, V5KnowledgePaperRunner $runner): JsonResponse
    {
        $this->assertWorkspaceRole($request, $workspace);
        abort_unless(\Illuminate\Schema\Schema::hasTable('nexus_entries'), 503, 'Nexus database migration is pending.');
        $item = NexusEntry::query()->where('workspace_id', $workspace)->where('kind', 'candidate')->findOrFail($entry);
        abort_unless(data_get($item->payload, 'source') === 'v5_knowledge_review', 422, 'Not a V5 knowledge item.');
        $ledger = $runner->run($workspace, $item);
        return response()->json(['execution_authorized' => false, 'baseline_autopilot_untouched' => true, 'ledger' => $ledger], $ledger->wasRecentlyCreated ? 201 : 200);
    }

    public function createKnowledge(Request $request, string $workspace): JsonResponse
    {
        $this->assertWorkspaceMembership($request, $workspace);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'content' => ['required', 'string', 'max:12000'],
            'category' => ['required', 'in:CANDLE,TREND,SUPPORT_RESISTANCE,RISK,OTHER'],
            'source_type' => ['nullable', 'in:MANUAL_NOTE,VIDEO_TRANSCRIPT,AUDIO_TRANSCRIPT,DOCUMENT_TRANSCRIPT'],
            'source_url' => ['nullable', 'url', 'max:1000'],
        ]);
        $entry = NexusEntry::create([
            'workspace_id' => $workspace,
            'kind' => 'candidate',
            'title' => $data['title'],
            'status' => 'PROPOSED',
            'evidence_level' => 'C',
            'fingerprint' => hash('sha256', json_encode(['v5_knowledge_review', $workspace, $data['title'], $data['content']], JSON_THROW_ON_ERROR)),
            'payload' => [
                'source' => 'v5_knowledge_review', 'category' => $data['category'], 'content' => $data['content'],
                'source_type' => $data['source_type'] ?? 'MANUAL_NOTE',
                'source_url' => $data['source_url'] ?? null, 'paper_metrics' => null,
                'verification' => ['backtest' => 'PENDING', 'paper_trade' => 'PENDING', 'holdout' => 'PENDING'],
                'training_eligible' => false, 'execution_authorized' => false,
            ],
        ]);
        return response()->json(['execution_authorized' => false, 'item' => $entry], 201);
    }

    public function uploadKnowledge(Request $request, string $workspace, V5KnowledgeCategorizer $categorizer): JsonResponse
    {
        $this->assertWorkspaceMembership($request, $workspace);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'category' => ['required', 'in:CANDLE,TREND,SUPPORT_RESISTANCE,RISK,OTHER'],
            'source_type' => ['nullable', 'in:VIDEO_TRANSCRIPT,AUDIO_TRANSCRIPT,DOCUMENT_TRANSCRIPT'],
            'content' => ['nullable', 'string', 'max:12000'],
            'document' => ['required', 'file', 'mimes:pdf,txt,md', 'max:20480'],
        ]);
        $path = $request->file('document')->store('v5-knowledge/'.$workspace, 'local');
        $content = (string) ($data['content'] ?? '');
        $parts = $categorizer->split($data['title'], $content, $data['category']);
        // Without extracted text there is still one document-level review item;
        // the system never pretends that a PDF/video was transcribed.
        if ($parts === []) {
            $parts = [['category' => $data['category'], 'title' => $data['title'], 'content' => $content]];
        }
        $entries = collect($parts)->map(function (array $part, int $index) use ($workspace, $path, $data, $request): NexusEntry {
            return NexusEntry::create([
            'workspace_id' => $workspace, 'kind' => 'candidate', 'title' => $part['title'], 'status' => 'PROPOSED',
            'evidence_level' => 'C', 'fingerprint' => hash('sha256', $workspace.'|'.$path.'|'.$index.'|'.$part['category'].'|'.$part['content']),
            'payload' => ['source' => 'v5_knowledge_review', 'category' => $part['category'], 'content' => $part['content'],
                'item_status' => 'PENDING_REVIEW', 'parent_document_title' => $data['title'],
                'source_type' => $data['source_type'] ?? 'DOCUMENT_TRANSCRIPT',
                'document_path' => $path, 'document_mime' => $request->file('document')->getMimeType(),
                'verification' => ['document_review' => 'PENDING', 'backtest' => 'PENDING', 'paper_trade' => 'PENDING', 'holdout' => 'PENDING'],
                'training_eligible' => false, 'execution_authorized' => false],
            ]);
        });
        return response()->json(['execution_authorized' => false, 'items' => $entries->values(), 'item_count' => $entries->count()], 201);
    }

    public function reviewKnowledge(Request $request, string $workspace, string $entry): JsonResponse
    {
        $this->assertWorkspaceRole($request, $workspace);
        $data = $request->validate(['action' => ['required', 'in:APPROVED,REJECTED'], 'reason' => ['required', 'string', 'min:3', 'max:2000']]);
        $item = DB::transaction(function () use ($request, $workspace, $entry, $data) {
            $item = NexusEntry::query()->where('workspace_id', $workspace)->where('kind', 'candidate')->lockForUpdate()->findOrFail($entry);
            abort_unless(data_get($item->payload, 'source') === 'v5_knowledge_review', 422, 'Not a V5 knowledge item.');
            NexusReview::create(['workspace_id' => $workspace, 'entry_id' => $item->id, 'user_id' => $request->user()->id, 'action' => $data['action'], 'reason' => $data['reason']]);
            $payload = (array) $item->payload;
            $payload['verification']['human_review'] = $data['action'];
            $payload['training_eligible'] = false; // paper/holdout gates must be added by a future experiment result.
            $item->update(['status' => $data['action'], 'payload' => $payload]);
            return $item->fresh();
        });
        return response()->json(['execution_authorized' => false, 'item' => $item]);
    }

    /** Download only human-approved items. Exporting never changes their training or execution state. */
    public function downloadKnowledge(Request $request, string $workspace, V5KnowledgeExportService $exports)
    {
        $this->assertWorkspaceMembership($request, $workspace);
        $items = $exports->approved($workspace);
        abort_if($items === [], 422, 'No human-approved V5 knowledge items are available for export.');

        $format = $request->string('format', 'txt')->lower()->value();
        abort_unless(in_array($format, ['txt', 'pdf'], true), 422, 'Format must be txt or pdf.');
        $file = 'v5-knowledge-review-'.now('UTC')->format('Ymd-His');
        $text = $exports->text($items);

        if ($format === 'pdf') {
            $html = '<!doctype html><html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:10px;line-height:1.45}h1{font-size:17px}h2{font-size:13px;margin-top:18px}.meta{color:#555}.content{white-space:pre-wrap;border-top:1px solid #ddd;padding-top:7px}</style></head><body>';
            $html .= '<h1>AUTOFINX V5 Research Knowledge Review</h1><p class="meta">Human-approved research notes only. This file does not train, promote, or execute a trading model.</p>';
            foreach ($items as $number => $item) {
                $payload = (array) $item->payload;
                $verification = (array) data_get($payload, 'verification', []);
                $html .= '<h2>'.($number + 1).'. '.e($item->title).'</h2>';
                $html .= '<p class="meta">Category: '.e((string) data_get($payload, 'category', 'OTHER')).' · Item: '.e($item->status).' · Source: '.e((string) data_get($payload, 'source_type', 'MANUAL_NOTE')).'<br>Document: '.e((string) data_get($verification, 'document_review', 'PENDING')).' · Human: '.e((string) data_get($verification, 'human_review', 'PENDING')).' · Backtest/Paper/Holdout: '.e((string) data_get($verification, 'backtest', 'PENDING')).' / '.e((string) data_get($verification, 'paper_trade', 'PENDING')).' / '.e((string) data_get($verification, 'holdout', 'PENDING')).'<br>Training eligible: NO</p>';
                $html .= '<div class="content">'.nl2br(e((string) data_get($payload, 'content', 'No extracted transcript was supplied.'))).'</div>';
            }
            return Pdf::loadHTML($html)->download($file.'.pdf');
        }

        return response($text, 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$file.'.txt"']);
    }

    /** Compatibility routes; new callers should use workspace-scoped endpoints. */
    public function legacySelections(Request $request): JsonResponse
    {
        return $this->selectionList($request, $this->legacyWorkspace($request));
    }

    public function legacySelect(Request $request): JsonResponse
    {
        return $this->createSelection($request, $this->legacyWorkspace($request));
    }

    public function legacyRemove(Request $request, string $id): JsonResponse
    {
        return $this->remove($request, $this->legacyWorkspace($request), $id);
    }

    public function legacySelectionMetrics(Request $request): JsonResponse
    {
        return $this->metrics($request, $this->legacyWorkspace($request));
    }

    public function rankingHistory(): JsonResponse
    {
        $runs = V5ResearchScannerRun::with('candidates')->latest('as_of')->limit(20)->get()->reverse()->values();
        $previous = [];
        $snapshots = [];
        foreach ($runs as $run) {
            $current = [];
            foreach ($run->candidates as $candidate) {
                $current[$candidate->symbol] = (int) $candidate->rank;
            }
            $rows = [];
            foreach ($current as $symbol => $rank) {
                $rows[] = [
                    'symbol' => $symbol,
                    'current_rank' => $rank,
                    'previous_rank' => $previous[$symbol] ?? null,
                    'rank_movement' => isset($previous[$symbol]) ? $previous[$symbol] - $rank : null,
                    'new_entrant' => ! isset($previous[$symbol]),
                    'execution_authorized' => false,
                ];
            }
            foreach (array_diff_key($previous, $current) as $symbol => $rank) {
                $rows[] = ['symbol' => $symbol, 'current_rank' => null, 'previous_rank' => $rank, 'rank_movement' => null, 'removed' => true, 'execution_authorized' => false];
            }
            $snapshots[] = ['as_of' => $run->as_of, 'rows' => $rows];
            $previous = $current;
        }

        return response()->json(['execution_authorized' => false, 'snapshots' => $snapshots]);
    }

    private function createSelection(Request $request, string $workspace): JsonResponse
    {
        $data = $request->validate([
            'symbol' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9]+USDT$/'],
            'side' => ['required', 'in:LONG,SHORT'],
            'horizon' => ['nullable', 'in:1h,4h,24h'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $run = $this->latestRun();
        $candidate = $run?->candidates->firstWhere('symbol', $data['symbol']);
        $referencePrice = (float) data_get($candidate?->evidence, 'assessment.horizons.15m.reference_close', 0);

        if (! $candidate || $referencePrice <= 0) {
            return response()->json(['message' => 'A current server-observed reference price is required for selection.'], 422);
        }

        $selection = DB::transaction(function () use ($request, $workspace, $data, $run, $candidate, $referencePrice) {
            WorkspaceMember::query()
                ->where('workspace_id', $workspace)
                ->where('user_id', $request->user()->id)
                ->lockForUpdate()
                ->firstOrFail();

            $activeQuery = V5ResearchSelection::query()
                ->where('workspace_id', $workspace)
                ->where('user_id', $request->user()->id)
                ->where('status', 'TRACKING');

            if ((clone $activeQuery)->where('symbol', $data['symbol'])->exists()) {
                abort(422, 'This symbol is already being tracked in the workspace.');
            }
            if ($activeQuery->count() >= 3) {
                abort(422, 'Maximum three active research selections.');
            }

            $predictionId = V5ShadowPrediction::query()->where('symbol', $data['symbol'])->latest('forecast_at')->value('id');

            return V5ResearchSelection::create([
                'id' => (string) Str::uuid(),
                'user_id' => $request->user()->id,
                'workspace_id' => $workspace,
                'symbol' => $data['symbol'],
                'side' => $data['side'],
                'status' => 'TRACKING',
                'selected_at' => now(),
                'expires_at' => $data['expires_at'] ?? null,
                'selection_price' => $referencePrice,
                'prediction_snapshot_id' => $predictionId,
                'original_rank' => $candidate->rank,
                'current_rank' => $candidate->rank,
                'horizon' => $data['horizon'] ?? '24h',
                'notes' => $data['notes'] ?? null,
                'research_metrics' => ['scanner_run_id' => $run->id, 'reference_price_source' => 'server_closed_candle', 'cost_adjusted_return_status' => 'unavailable'],
            ]);
        });

        return response()->json(['execution_authorized' => false, 'selection' => $selection], 201);
    }

    private function selectionList(Request $request, string $workspace): JsonResponse
    {
        $selections = V5ResearchSelection::query()
            ->where('workspace_id', $workspace)
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get();

        return response()->json(['execution_authorized' => false, 'selections' => $selections]);
    }

    private function metrics(Request $request, string $workspace): JsonResponse
    {
        $selections = V5ResearchSelection::query()
            ->where('workspace_id', $workspace)
            ->where('user_id', $request->user()->id)
            ->get();
        if ($selections->isEmpty()) {
            return response()->json(['execution_authorized' => false, 'metrics' => []]);
        }

        $symbols = $selections->pluck('symbol')->unique()->values();
        $runs = V5ResearchScannerRun::query()
            ->where('as_of', '>=', $selections->min('selected_at'))
            ->with(['candidates' => fn ($query) => $query->whereIn('symbol', $symbols)])
            ->orderBy('as_of')
            ->limit(500)
            ->get();

        $items = $selections->map(function (V5ResearchSelection $selection) use ($runs) {
            $history = [['at' => $selection->selected_at, 'return' => 0.0]];
            $currentPrice = null;
            $currentRank = null;
            foreach ($runs as $run) {
                if ($run->as_of->lt($selection->selected_at)) {
                    continue;
                }
                $candidate = $run->candidates->firstWhere('symbol', $selection->symbol);
                $price = (float) data_get($candidate?->evidence, 'assessment.horizons.15m.reference_close', 0);
                if ($price <= 0 || (float) $selection->selection_price <= 0) {
                    continue;
                }
                $gross = ($selection->side === 'LONG' ? 1 : -1) * (($price / (float) $selection->selection_price) - 1);
                $history[] = ['at' => $run->as_of, 'return' => $gross];
                $currentPrice = $price;
                $currentRank = (int) $candidate->rank;
            }
            if ($currentRank !== null && $selection->current_rank !== $currentRank) {
                $selection->update(['current_rank' => $currentRank]);
            }

            return [
                'selection_id' => $selection->id,
                'symbol' => $selection->symbol,
                'side' => $selection->side,
                'selection_price' => (float) $selection->selection_price,
                'current_reference_price' => $currentPrice,
                'return_since_selection' => count($history) > 1 ? end($history)['return'] : null,
                'estimated_after_cost_return' => null,
                'cost_status' => 'unavailable',
                'history' => $history,
                'status' => $selection->status,
                'execution_authorized' => false,
            ];
        });

        return response()->json(['execution_authorized' => false, 'metrics' => $items]);
    }

    private function legacyWorkspace(Request $request): string
    {
        $workspace = $request->validate(['workspace_id' => ['required', 'uuid']])['workspace_id'];
        $this->assertWorkspaceMembership($request, $workspace);

        return $workspace;
    }

    private function latestRun(): ?V5ResearchScannerRun
    {
        return V5ResearchScannerRun::query()->latest('as_of')->with('candidates')->first();
    }

    private function displayedRun(): ?V5ResearchScannerRun
    {
        $run = $this->latestRun();
        if ($run) {
            $run->setRelation('candidates', $run->candidates->sortBy('rank')->take(3)->values());
        }

        return $run;
    }
}
