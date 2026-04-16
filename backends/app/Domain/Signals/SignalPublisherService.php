<?php

namespace App\Domain\Signals;

use App\Models\Signal;
use App\Domain\Signals\WebhookDispatcherService;
use Illuminate\Support\Str;

class SignalPublisherService
{
    public function publish(string $workspaceId, string $symbol, string $action, float $confidence, array $meta = []): Signal
    {
        $signal = Signal::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'symbol' => $symbol,
            'action' => $action,
            'confidence' => $confidence,
            'regime' => $meta['regime'] ?? null,
            'strategy_name' => $meta['strategy_name'] ?? null,
            'meta' => $meta,
        ]);

        // Fan-out to any configured webhooks for the workspace
        /** @var WebhookDispatcherService $dispatcher */
        $dispatcher = app(WebhookDispatcherService::class);
        $dispatcher->dispatchForWorkspace($workspaceId, [
            'type' => 'signal',
            'symbol' => $symbol,
            'action' => $action,
            'confidence' => $confidence,
            'meta' => $meta,
            'id' => $signal->id,
        ]);

        return $signal;
    }
}
