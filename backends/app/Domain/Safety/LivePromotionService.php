<?php

namespace App\Domain\Safety;

use App\Models\PromotionApproval;
use App\Models\PromotionRequest;
use Illuminate\Support\Str;
use RuntimeException;

class LivePromotionService
{
    public function request(string $workspaceId, string $userId, array $payload): PromotionRequest
    {
        if (!config('services.live_trading_enabled', false)) {
            throw new RuntimeException('Live trading disabled');
        }

        return PromotionRequest::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'requested_by' => $userId,
            'payload_json' => $payload,
            'status' => 'pending',
        ]);
    }

    public function approve(PromotionRequest $request, string $approverId): PromotionApproval
    {
        if ($request->status !== 'pending') {
            throw new RuntimeException('Already processed');
        }

        $request->status = 'approved';
        $request->save();

        return PromotionApproval::create([
            'id' => (string) Str::uuid(),
            'request_id' => $request->id,
            'approved_by' => $approverId,
            'approved_at' => now(),
            'totp_verified_at' => now(),
        ]);
    }
}
