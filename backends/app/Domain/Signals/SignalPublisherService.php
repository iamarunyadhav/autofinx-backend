<?php

namespace App\Domain\Signals;

use App\Models\Signal;
use App\Domain\Signals\WebhookDispatcherService;
use Illuminate\Support\Str;

class SignalPublisherService
{
    public function publish(string $workspaceId, string $symbol, string $action, float $confidence, array $meta = []): Signal
    {
        $normalizedMeta = array_merge([
            'source' => $meta['source'] ?? 'manual',
            'score' => $meta['score'] ?? null,
            'rr' => $meta['rr'] ?? ($meta['risk_reward'] ?? null),
            'htf_aligned' => $meta['htf_aligned'] ?? ($meta['htfAligned'] ?? null),
            'news_risk' => $meta['news_risk'] ?? ($meta['newsRisk'] ?? null),
        ], $meta);

        $signal = Signal::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'symbol' => strtoupper(trim($symbol)),
            'action' => strtoupper(trim($action)),
            'confidence' => $confidence > 1 ? round($confidence / 10, 4) : $confidence,
            'regime' => $normalizedMeta['regime'] ?? null,
            'strategy_name' => $normalizedMeta['strategy_name'] ?? null,
            'meta' => $normalizedMeta,
        ]);

        // Fan-out to any configured webhooks for the workspace
        /** @var WebhookDispatcherService $dispatcher */
        $dispatcher = app(WebhookDispatcherService::class);
        $dispatcher->dispatchForWorkspace($workspaceId, [
            'type' => 'signal',
            'symbol' => $symbol,
            'action' => strtoupper(trim($action)),
            'confidence' => $confidence > 1 ? round($confidence / 10, 4) : $confidence,
            'meta' => $normalizedMeta,
            'id' => $signal->id,
        ]);

        return $signal;
    }
}
