<?php

namespace App\Domain\Trading;

use App\Models\BotLog;
use Illuminate\Support\Str;

class BotLogService
{
    public function log(string $botId, ?string $workspaceId, string $level, string $message, array $meta = [], ?string $traceId = null): void
    {
        BotLog::create([
            'id' => (string) Str::uuid(),
            'bot_id' => $botId,
            'workspace_id' => $workspaceId,
            'level' => strtoupper($level),
            'message' => $message,
            'meta_json' => $meta,
            'ts' => now(),
            'trace_id' => $traceId,
        ]);
    }
}
