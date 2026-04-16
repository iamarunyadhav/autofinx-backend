<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RequireApiAccessScope
{
    public function handle(Request $request, Closure $next, string ...$requiredScopes): Response
    {
        $user = Auth::guard('sanctum')->user();
        if ($user) {
            $request->setUserResolver(fn () => $user);

            return $next($request);
        }

        $plainToken = $this->extractToken($request);
        if ($plainToken === '') {
            return response()->json(['message' => 'Authentication required.'], 401);
        }

        $token = ApiToken::where('token_hash', hash('sha256', $plainToken))->first();
        if (! $token) {
            return response()->json(['message' => 'Invalid API token.'], 401);
        }

        if (! $this->matchesWorkspace($token, $request)) {
            return response()->json(['message' => 'API token is not authorized for this workspace.'], 403);
        }

        if (! $this->hasRequiredScopes($token, $requiredScopes)) {
            return response()->json(['message' => 'API token is missing a required scope.'], 403);
        }

        $token->forceFill(['last_used_at' => now()])->save();
        $request->attributes->set('api_token', $token);

        return $next($request);
    }

    private function extractToken(Request $request): string
    {
        $bearer = trim((string) $request->bearerToken());
        if (str_starts_with($bearer, 'atk_')) {
            return $bearer;
        }

        return trim((string) $request->header('X-Api-Token', ''));
    }

    private function matchesWorkspace(ApiToken $token, Request $request): bool
    {
        if (! $token->workspace_id) {
            return true;
        }

        $workspaceId = (string) ($request->route('workspace')
            ?? $request->route('workspaceId')
            ?? $request->query('workspace_id')
            ?? $request->input('workspace_id')
            ?? '');

        return $workspaceId === '' || $workspaceId === (string) $token->workspace_id;
    }

    private function hasRequiredScopes(ApiToken $token, array $requiredScopes): bool
    {
        $granted = array_values(array_filter((array) ($token->scopes ?? [])));

        if (empty($requiredScopes) || in_array('*', $granted, true)) {
            return true;
        }

        foreach ($requiredScopes as $scope) {
            if (! in_array($scope, $granted, true)) {
                return false;
            }
        }

        return true;
    }
}
