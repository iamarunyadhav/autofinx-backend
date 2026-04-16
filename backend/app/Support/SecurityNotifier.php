<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Notification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Http;

class SecurityNotifier
{
    public function confirmAction(?Authenticatable $user, ?string $workspaceId, string $action, array $meta = []): void
    {
        $email = $user?->email;
        $body = $this->buildMessage($action, $meta);

        if ($email) {
            Mail::raw($body, function ($message) use ($email, $action) {
                $message->to($email)->subject("[Sentinel] Confirmation: {$action}");
            });
        }

        $this->sendSms($body);
        $this->sendPush($body);

        Notification::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user?->id,
            'workspace_id' => $workspaceId,
            'type' => 'security',
            'content' => [
                'title' => 'Security confirmation',
                'body' => $body,
                'action' => $action,
                'meta' => $meta,
            ],
        ]);

        AuditLog::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'action' => $action,
            'actor_id' => $user?->id,
            'actor_type' => 'user',
            'metadata' => $meta,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    private function buildMessage(string $action, array $meta): string
    {
        $summary = json_encode($meta);
        return "Action: {$action}\nDetails: {$summary}\nIf this was not you, rotate keys and contact support.";
    }

    private function sendSms(string $message): void
    {
        $url = env('SMS_WEBHOOK_URL');
        $key = env('SMS_WEBHOOK_KEY');
        if (! $url || ! $key) return;
        Http::withHeaders(['X-API-Key' => $key])->post($url, [
          'message' => $message,
        ]);
    }

    private function sendPush(string $message): void
    {
        $url = env('PUSH_WEBHOOK_URL');
        $key = env('PUSH_WEBHOOK_KEY');
        if (! $url || ! $key) return;
        Http::withHeaders(['X-API-Key' => $key])->post($url, [
          'message' => $message,
          'title' => 'Security Alert',
        ]);
    }
}
