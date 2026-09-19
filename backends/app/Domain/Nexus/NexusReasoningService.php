<?php

namespace App\Domain\Nexus;

use App\Models\NexusEntry;
use App\Models\NexusModelCall;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class NexusReasoningService
{
    public function __construct(private readonly NexusDiscoveryService $discovery) {}

    public function run(string $workspace, array $capabilities, bool $deep = false, array $observations = []): string
    {
        if (! config('nexus.llm.enabled') || (int) config('nexus.autonomy') < 1) {
            return 'DISABLED';
        }
        if (! app()->bound(NexusResearchProvider::class) && config('nexus.llm.provider') !== 'openai-compatible') {
            return 'UNSUPPORTED_PROVIDER';
        }
        $sources = NexusEntry::where('workspace_id', $workspace)->where('kind', 'source')->where('status', 'AVAILABLE')
            ->where('updated_at', '>=', now()->subDays(2))->orderByDesc('updated_at')->orderBy('id')->limit(4)->get();
        $known = NexusEntry::where('workspace_id', $workspace)->where('kind', 'candidate')->latest()->limit(20)->get(['title', 'status']);
        $context = ['capabilities' => $capabilities, 'observations' => $observations,
            'previous_proposals_do_not_repeat_without_new_evidence' => $known->toArray(), 'sources' => $sources->map(fn ($s) => [
                'id' => $s->id, 'category' => $s->payload['category'], 'reliability' => $s->payload['reliability'],
                'items' => array_map(fn ($item) => ['title' => $item['title'] ?? '',
                    'text' => mb_substr((string) ($item['text'] ?? ''), 0, 600),
                    'published_at' => $item['published_at'] ?? null], array_slice($s->payload['items'], 0, 2)),
            ])->all()];
        if ($sources->isEmpty()) {
            return 'NO_EVIDENCE';
        }
        $json = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        while (strlen($json) > config('nexus.llm.max_input_bytes') && $context['previous_proposals_do_not_repeat_without_new_evidence'] !== []) {
            array_pop($context['previous_proposals_do_not_repeat_without_new_evidence']);
            $json = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
        if (strlen($json) > config('nexus.llm.max_input_bytes')) {
            return 'CONTEXT_LIMIT';
        }
        $model = (string) config('nexus.llm.'.($deep ? 'deep_model' : 'routine_model'));
        if ($model === '' || ! config('nexus.llm.key') || config('nexus.llm.input_price') <= 0 || config('nexus.llm.output_price') <= 0) {
            return 'PROVIDER_OR_PRICING_REQUIRED';
        }
        $evidenceContext = $context;
        unset($evidenceContext['previous_proposals_do_not_repeat_without_new_evidence']);
        $fingerprint = hash('sha256', 'prompt-v1|'.$model.'|'.json_encode($evidenceContext, JSON_THROW_ON_ERROR));
        // Global reservation lock ensures different workspaces share one provider budget.
        $lock = Cache::lock('nexus:llm:budget', 10);
        if (! $lock->get()) {
            return 'BUDGET_BUSY';
        }
        try {
            if (NexusModelCall::where('workspace_id', $workspace)->where('fingerprint', $fingerprint)->where('created_at', '>=', now()->subDays(7))->exists()) {
                return 'CONTENT_CACHED';
            }
            if (NexusModelCall::where('workspace_id', $workspace)->where('created_at', '>=', now()->subDay())->exists()) {
                return 'DAILY_REASONING_GATE';
            }
            // A byte per input token + system prompt allowance overestimates normal tokenization.
            $reserve = round(((strlen($json) + 2048) * config('nexus.llm.input_price')
                + config('nexus.llm.max_output_tokens') * config('nexus.llm.output_price')) / 1000000, 6);
            if (NexusModelCall::where('created_at', '>=', now()->subHour())->count() >= config('nexus.llm.calls_per_hour')
                || ($deep && NexusModelCall::where('deep', true)->where('created_at', '>=', now()->startOfDay())->count() >= config('nexus.llm.deep_calls_per_day'))
                || NexusModelCall::where('created_at', '>=', now()->startOfDay())->sum('reserved_usd') + $reserve > config('nexus.llm.daily_budget')
                || NexusModelCall::where('created_at', '>=', now()->startOfMonth())->sum('reserved_usd') + $reserve > config('nexus.llm.monthly_budget')) {
                return 'BUDGET_EXHAUSTED';
            }
            $call = NexusModelCall::create(['workspace_id' => $workspace, 'fingerprint' => $fingerprint, 'model' => $model,
                'deep' => $deep, 'status' => 'reserved', 'reserved_usd' => $reserve]);
        } finally {
            $lock->release();
        }
        try {
            $provider = app()->bound(NexusResearchProvider::class) ? app(NexusResearchProvider::class) : new NexusChatResearchProvider;
            $result = $provider->research($json, $model);
            $accepted = 0;
            foreach (array_slice($result['candidates'] ?? [], 0, 5) as $candidate) {
                $ids = $candidate['source_ids'] ?? [];
                if ($ids === [] || array_diff($ids, $sources->modelKeys()) !== []) {
                    continue;
                }
                $candidate['origin'] = 'LLM_REASONING';
                $candidate['evidence'] = $sources->whereIn('id', $ids)->map(fn ($s) => [
                    'source_id' => $s->id, 'url' => $s->payload['url'], 'content_hash' => $s->payload['content_hash'],
                    'retrieved_at' => $s->payload['retrieved_at'], 'items' => $s->payload['items'],
                ])->all();
                $this->discovery->candidate($workspace, $candidate['title'], $candidate);
                $accepted++;
            }
            $call->update(['status' => 'completed', 'result' => ['accepted_candidates' => $accepted]]);

            return 'COMPLETED';
        } catch (Throwable) {
            // Keep the reservation charged: timeout may still incur provider cost. No automatic retry.
            $call->update(['status' => 'failed', 'result' => ['error_code' => 'RESEARCH_PROVIDER_FAILED']]);

            return 'PROVIDER_FAILED';
        }
    }
}
