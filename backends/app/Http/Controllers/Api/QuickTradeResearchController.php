<?php

namespace App\Http\Controllers\Api;

use App\Domain\Trading\QuickTradeResearchService;
use App\Http\Controllers\Controller;
use App\Models\QuickTrade;
use App\Models\WorkspaceMember;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class QuickTradeResearchController extends Controller
{
    public function index(Request $request, string $workspace, QuickTradeResearchService $research)
    {
        abort_unless($request->user() && WorkspaceMember::where('workspace_id', $workspace)
            ->where('user_id', $request->user()->id)->exists(), 403, 'You do not have access to this workspace.');
        $data = $request->validate([
            'trade_ids' => 'nullable|array|max:100', 'trade_ids.*' => 'uuid',
            'symbol' => 'nullable|string|max:32', 'mode' => 'nullable|in:live,paper',
            'source' => 'nullable|string|max:32', 'from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from',
            'cursor' => 'nullable|uuid', 'limit' => 'nullable|integer|min:1|max:100',
            'as_of' => 'nullable|date|before_or_equal:now',
        ]);
        $asOf = CarbonImmutable::parse($data['as_of'] ?? now())->utc();
        $limit = (int) ($data['limit'] ?? 50);
        $query = QuickTrade::where('workspace_id', $workspace)->where('status', '!=', 'open')
            ->whereNotNull('settled_at')->where('settled_at', '<=', $asOf)
            ->when($data['trade_ids'] ?? null, fn ($q, $ids) => $q->whereIn('id', $ids))
            ->when($data['symbol'] ?? null, fn ($q, $symbol) => $q->where('symbol', strtoupper($symbol)))
            ->when($data['mode'] ?? null, fn ($q, $mode) => $q->where('mode', $mode))
            ->when($data['source'] ?? null, fn ($q, $source) => $q->where('source', $source))
            ->when($data['from'] ?? null, fn ($q, $date) => $q->where('entry_at', '>=', CarbonImmutable::parse($date)->utc()))
            ->when($data['to'] ?? null, fn ($q, $date) => $q->where('entry_at', '<=', CarbonImmutable::parse($date)->utc()))
            ->when($data['cursor'] ?? null, fn ($q, $cursor) => $q->where('id', '>', $cursor));
        $trades = $query->orderBy('id')->limit($limit + 1)->get();
        $hasMore = $trades->count() > $limit;
        $page = $trades->take($limit);
        $rows = $page->map(fn ($trade) => $research->report($trade))->values();
        $summary = [];
        foreach (['primary', 'shadow'] as $name) {
            $evaluated = $rows->filter(fn ($row) => $row['mode'] === 'live' && $row['execution_environment'] === 'live'
                && $row[$name.'_comparison']['direction_correct'] !== null);
            $hits = $evaluated->filter(fn ($row) => $row[$name.'_comparison']['direction_correct'] === true)->count();
            $summary[$name] = ['evaluable' => $evaluated->count(), 'correct' => $hits,
                'accuracy_pct' => $evaluated->count() >= 30 ? round($hits / $evaluated->count() * 100, 2) : null,
                'status' => $evaluated->count() >= 30 ? 'observed_sample' : 'insufficient_data'];
        }
        return response()->json(['schema_version' => 1, 'as_of' => $asOf->toIso8601String(), 'rows' => $rows,
            'next_cursor' => $hasMore ? $page->last()->id : null, 'has_more' => $hasMore,
            'summary' => $summary, 'summary_scope' => 'this_page_live_trades_only',
            'shadow_only' => true, 'sample_storage_ready' => Schema::hasTable('quick_trade_research_samples'),
            'collection_enabled' => (bool) config('quick_trade_research.enabled', true),
            'shadow_enabled' => (bool) config('unified_forecast.quick_trade_shadow_enabled', true),
        ])->header('Cache-Control', 'private, no-store');
    }
}
