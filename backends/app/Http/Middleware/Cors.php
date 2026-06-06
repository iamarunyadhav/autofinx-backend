<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class Cors
{
    public function handle(Request $request, Closure $next): Response
    {
        $headers = $this->corsHeaders($request);

        if ($request->getMethod() === 'OPTIONS') {
            return response()->noContent(204)->withHeaders($headers);
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $response = app(ExceptionHandler::class)->render($request, $e);
        }

        foreach ($headers as $key => $value) {
            $response->headers->set($key, $value);
        }

        return $response;
    }

    /**
     * Build CORS headers from the same origin allow-list used by config/cors.php.
     * Reflect only allowed origins when credentials are enabled; this keeps
     * production preflight deterministic while still supporting local dev.
     */
    private function corsHeaders(Request $request): array
    {
        $origin = trim((string) $request->headers->get('Origin', ''));
        $allowedOrigins = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => rtrim(trim((string) $value), '/'),
            (array) config('cors.allowed_origins', [])
        ))));

        $allowOrigin = '*';
        if ($origin !== '') {
            $normalizedOrigin = rtrim($origin, '/');
            $allowOrigin = in_array($normalizedOrigin, $allowedOrigins, true)
                ? $origin
                : ($this->isLocalDevelopmentOrigin($origin) ? $origin : (string) ($allowedOrigins[0] ?? $origin));
        }

        $requestedHeaders = trim((string) $request->headers->get('Access-Control-Request-Headers', ''));

        return [
            'Access-Control-Allow-Origin' => $allowOrigin,
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => $requestedHeaders !== ''
                ? $requestedHeaders
                : 'Content-Type, Authorization, X-Requested-With, X-CSRF-TOKEN, Accept',
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Max-Age' => '600',
            'Vary' => 'Origin, Access-Control-Request-Method, Access-Control-Request-Headers',
        ];
    }

    private function isLocalDevelopmentOrigin(string $origin): bool
    {
        $host = parse_url($origin, PHP_URL_HOST);

        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }
}
