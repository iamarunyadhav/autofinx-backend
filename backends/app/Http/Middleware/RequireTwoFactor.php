<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $enforce = (bool) env('ENFORCE_2FA_CRITICAL', false);
        $productionLike = ! app()->environment(['local', 'testing']);
        $user = $request->user();

        if ((! $enforce && ! $productionLike) || ! $user) {
            return $next($request);
        }

        $hasSecret = ! empty($user->two_factor_secret);
        $isConfirmed = ! empty($user->two_factor_confirmed_at);

        if (! $hasSecret || ! $isConfirmed) {
            return response()->json(['message' => 'Enable and confirm two-factor authentication to perform this action.'], 403);
        }

        return $next($request);
    }
}
