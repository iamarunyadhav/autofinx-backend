<?php

namespace App\Domain\Intelligence;

use App\Models\AiDecision;
use App\Models\AiDecisionCycle;
use App\Models\Notification;
use Illuminate\Support\Str;

class AiNotificationSummaryService
{
    public function notify(string $workspaceId, string $type, string $title, string $body, string $severity, array $payload = []): Notification
    {
        return Notification::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'type' => $type,
            'content' => array_merge([
                'title' => $title,
                'body' => $body,
                'severity' => $severity,
            ], $payload),
        ]);
    }

    public function forCycle(AiDecisionCycle $cycle, string $title, string $body, string $severity, array $payload = []): Notification
    {
        return $this->notify((string) $cycle->workspace_id, 'ai_cycle', $title, $body, $severity, array_merge([
            'cycle_id' => $cycle->id,
            'symbol' => $cycle->symbol,
            'mode' => $cycle->mode,
            'status' => $cycle->status,
            'provider' => $cycle->provider_name,
            'model' => $cycle->provider_model,
        ], $payload));
    }

    public function forDecision(AiDecision $decision, string $title, string $body, string $severity, array $payload = []): Notification
    {
        return $this->notify((string) $decision->workspace_id, 'ai_trading_state', $title, $body, $severity, array_merge([
            'decision_id' => $decision->id,
            'symbol' => $decision->symbol,
            'action' => $decision->action,
            'confidence' => $decision->confidence,
            'status' => $decision->final_status,
        ], $payload));
    }
}
