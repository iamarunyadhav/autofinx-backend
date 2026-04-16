<?php

namespace App\Http\Controllers\Api;

use App\Domain\Safety\LivePromotionService;
use App\Http\Controllers\Controller;
use App\Models\PromotionRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

class LivePromotionController extends Controller
{
    public function status(Request $request)
    {
        $workspaceId = $request->input('workspace_id');
        return PromotionRequest::where('workspace_id', $workspaceId)->orderByDesc('created_at')->get();
    }

    public function request(Request $request, LivePromotionService $service)
    {
        $data = $request->validate([
            'workspace_id' => 'required|uuid',
            'risk_acknowledged' => 'required|boolean',
            'venues' => 'array',
            'symbols' => 'array',
            'max_capital' => 'nullable|numeric',
        ]);
        abort_unless($data['risk_acknowledged'] === true, 422, 'Risk acknowledgement required');

        $promotion = $service->request($data['workspace_id'], $request->user()->id, $data);
        return response()->json($promotion, 201);
    }

    public function approve(Request $request, LivePromotionService $service, TwoFactorAuthenticationProvider $provider)
    {
        $data = $request->validate([
            'request_id' => 'required|uuid|exists:promotion_requests,id',
            'otp' => 'required|string',
        ]);

        $user = $request->user();
        $secret = $user?->two_factor_secret ? Crypt::decryptString($user->two_factor_secret) : null;
        if (! $secret || empty($user?->two_factor_confirmed_at)) {
            return response()->json(['message' => 'Enable and confirm two-factor authentication before approving live promotion.'], 403);
        }
        if (! $provider->verify($secret, $data['otp'])) {
            return response()->json(['message' => 'Invalid two-factor code.'], 422);
        }

        $promotion = PromotionRequest::findOrFail($data['request_id']);
        $approval = $service->approve($promotion, $request->user()->id);
        return response()->json(['approval' => $approval, 'request' => $promotion]);
    }
}
