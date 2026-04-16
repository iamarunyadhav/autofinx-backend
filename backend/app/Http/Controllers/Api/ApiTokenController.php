<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ApiTokenController extends Controller
{
    private const ALLOWED_SCOPES = [
        'read:signals',
        'write:signals',
    ];

    public function index(Request $request)
    {
        $workspaceId = $request->query('workspace_id');
        $q = ApiToken::query();
        if ($workspaceId) {
            $q->where('workspace_id', $workspaceId);
        }
        return $q->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'workspace_id' => 'nullable|uuid',
            'name' => 'required|string',
            'scopes' => 'array',
            'scopes.*' => 'string|in:read:signals,write:signals,*',
        ]);

        $raw = 'atk_' . Str::random(40);
        $hash = hash('sha256', $raw);

        $token = ApiToken::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $data['workspace_id'] ?? null,
            'name' => $data['name'],
            'token_hash' => $hash,
            'scopes' => $data['scopes'] ?? [],
        ]);

        return response()->json(['token' => $token, 'plain' => $raw], 201);
    }

    public function destroy(ApiToken $apiToken)
    {
        $apiToken->delete();
        return response()->json(['deleted' => true]);
    }

    public function rotate(ApiToken $apiToken)
    {
        $raw = 'atk_' . Str::random(40);
        $apiToken->token_hash = hash('sha256', $raw);
        $apiToken->save();
        return response()->json(['token' => $apiToken, 'plain' => $raw]);
    }
}
