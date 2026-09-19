<?php

namespace App\Domain\Nexus;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class NexusSourceService
{
    public const MAX_BYTES = 262144;

    public function __construct(private readonly NexusStore $store) {}

    public function collect(string $workspace): array
    {
        $states = [];
        $registry = config('nexus.sources', []);
        $keys = array_keys($registry);
        // Bound each job while allowing registries larger than four sources to make progress.
        $offset = count($keys) > 4 ? (intdiv(now()->timestamp, 900) * 4) % count($keys) : 0;
        $dueKeys = array_slice(array_merge(array_slice($keys, $offset), array_slice($keys, 0, $offset)), 0, 4);
        foreach ($dueKeys as $key) {
            $source = $registry[$key];
            if (! ($source['enabled'] ?? false)) {
                continue;
            }
            $cacheKey = 'nexus:source:'.hash('sha256', json_encode($source));
            // Public documents can share a cache; private workspace observations cannot.
            $result = Cache::get($cacheKey);
            if (! is_array($result)) {
                $lock = Cache::lock($cacheKey.':lock', 20);
                if (! $lock->get()) {
                    $states[$key] = 'BUSY';

                    continue;
                }
                try {
                    $result = Cache::get($cacheKey);
                    if (! is_array($result)) {
                        try {
                            $result = $this->fetch($source);
                        } catch (Throwable) {
                            // Do not expose provider response bodies, credentials or internal URLs.
                            $result = ['status' => 'UNAVAILABLE', 'items' => [], 'error_code' => 'SOURCE_FETCH_FAILED', 'retrieved_at' => now()->toIso8601String()];
                        }
                        Cache::put($cacheKey, $result, now()->addMinutes($result['status'] === 'AVAILABLE' ? max(15, (int) $source['refresh_minutes']) : 15));
                    }
                } finally {
                    $lock->release();
                }
            }
            $this->store->put($workspace, 'source', $key, $key, $result + [
                'url' => $source['url'], 'category' => $source['category'], 'reliability' => $source['reliability'],
                'refresh_minutes' => $source['refresh_minutes'], 'origin' => 'EXTERNAL_DATA',
            ], $result['status']);
            $states[$key] = $result['status'];
        }

        return $states;
    }

    public function fetch(array $source): array
    {
        $url = (string) $source['url'];
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            throw new RuntimeException('Unsafe source URL.');
        }
        $host = $parts['host'] ?? '';
        $allowed = array_map(fn ($s) => parse_url($s['url'], PHP_URL_HOST), config('nexus.sources', []));
        if (! in_array($host, $allowed, true)) {
            throw new RuntimeException('Source is not registered.');
        }
        // Pin a verified public address to avoid DNS rebinding. Redirects are prohibited.
        $addresses = gethostbynamel($host) ?: [];
        if ($addresses === [] || count(array_filter($addresses, fn ($ip) => ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))) > 0) {
            throw new RuntimeException('Source did not resolve to public addresses.');
        }
        $response = Http::connectTimeout(3)->timeout(8)->withHeaders(['User-Agent' => 'AutoFinX-Nexus/1.0'])
            ->withOptions(['allow_redirects' => false, 'curl' => [CURLOPT_RESOLVE => [$host.':443:'.$addresses[0]]],
                'progress' => static function ($total, $downloaded) {
                    if ($total > self::MAX_BYTES || $downloaded > self::MAX_BYTES) {
                        throw new RuntimeException('Source body too large.');
                    }
                },
            ])->get($url);
        if (! $response->successful() || strlen($response->body()) > self::MAX_BYTES) {
            throw new RuntimeException('Source response rejected.');
        }

        return ['status' => 'AVAILABLE', 'retrieved_at' => now()->toIso8601String(),
            'content_hash' => hash('sha256', $response->body()), 'items' => $this->parse($response->body(), $source['format'])];
    }

    public function parse(string $body, string $format): array
    {
        if (strlen($body) > self::MAX_BYTES || preg_match('/<!DOCTYPE|<!ENTITY/i', $body)) {
            throw new RuntimeException('Unsafe source document.');
        }
        if ($format === 'json') {
            $items = json_decode($body, true, 32, JSON_THROW_ON_ERROR)['items'] ?? [];
        } else {
            $previous = libxml_use_internal_errors(true);
            try {
                $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET);
                if ($xml === false) {
                    throw new RuntimeException('Invalid source document.');
                }
                $items = [];
                foreach (array_slice($xml->xpath('//*[local-name()="item" or local-name()="entry"]') ?: [], 0, 10) as $node) {
                    $read = fn ($name) => (string) (($node->xpath('./*[local-name()="'.$name.'"]') ?: [])[0] ?? '');
                    $items[] = ['title' => $read('title'), 'text' => $read('description') ?: $read('summary'),
                        'published_at' => $read('pubDate') ?: $read('published')];
                }
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
        }

        return array_map(fn ($item) => ['title' => mb_substr(strip_tags((string) ($item['title'] ?? '')), 0, 200),
            'text' => mb_substr(strip_tags((string) ($item['text'] ?? '')), 0, 1200),
            'published_at' => mb_substr((string) ($item['published_at'] ?? ''), 0, 80)], array_slice($items, 0, 10));
    }
}
