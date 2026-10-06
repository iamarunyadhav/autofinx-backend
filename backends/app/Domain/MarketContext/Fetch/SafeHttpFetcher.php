<?php

namespace App\Domain\MarketContext\Fetch;

use App\Domain\MarketContext\Data\FetchResult;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The only HTTP path of the Market Context module. Same envelope as
 * Nexus\NexusSourceService: https only, URL must extend a registry URL, no
 * credentials/ports, a verified public address is pinned (anti DNS-rebinding),
 * 3s connect / 8s total, 256 KiB body cap, redirects off.
 */
class SafeHttpFetcher
{
    /** @var array<string, string> host => first public address */
    private static array $resolved = [];

    public function get(string $url, ?string $etag = null, ?string $lastModified = null): FetchResult
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            throw new RuntimeException('Unsafe source URL.');
        }
        $host = (string) ($parts['host'] ?? '');
        if (! $this->isRegistered($url)) {
            throw new RuntimeException('Source is not registered.');
        }

        $max = (int) config('market_context.http.max_bytes', 262144);
        $curl = [];
        if ((bool) config('market_context.http.pin_dns', true)) {
            $curl = [CURLOPT_RESOLVE => [$host.':443:'.$this->publicAddress($host)]];
        }
        $headers = ['User-Agent' => (string) config('market_context.http.user_agent', 'AutoFinX-MarketContext/1.0')];
        if ($etag) {
            $headers['If-None-Match'] = $etag;
        }
        if ($lastModified) {
            $headers['If-Modified-Since'] = $lastModified;
        }

        $response = Http::connectTimeout((int) config('market_context.http.connect_timeout', 3))
            ->timeout((int) config('market_context.http.timeout', 8))
            ->withHeaders($headers)
            ->withOptions(['allow_redirects' => false, 'curl' => $curl,
                'progress' => static function ($total, $downloaded) use ($max) {
                    if ($total > $max || $downloaded > $max) {
                        throw new RuntimeException('Source body too large.');
                    }
                },
            ])->get($url);

        if (strlen($response->body()) > $max) {
            throw new RuntimeException('Source body too large.');
        }

        return new FetchResult($response->status(), $response->body(), $response->header('ETag') ?: null,
            $response->header('Last-Modified') ?: null, $response);
    }

    private function isRegistered(string $url): bool
    {
        foreach ((array) config('market_context.sources', []) as $source) {
            if (isset($source['url']) && str_starts_with($url, (string) $source['url'])) {
                return true;
            }
        }

        return false;
    }

    private function publicAddress(string $host): string
    {
        if (isset(self::$resolved[$host])) {
            return self::$resolved[$host];
        }
        $addresses = gethostbynamel($host) ?: [];
        if ($addresses === [] || count(array_filter($addresses, fn ($ip) => ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))) > 0) {
            throw new RuntimeException('Source did not resolve to public addresses.');
        }

        return self::$resolved[$host] = $addresses[0];
    }
}
