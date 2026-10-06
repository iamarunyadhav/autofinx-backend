<?php

namespace App\Domain\MarketContext\Collectors;

use App\Domain\MarketContext\Data\CollectResult;
use App\Domain\MarketContext\Data\RawItem;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Generic RSS 2.0 / Atom collector driven by the registry (Fed, ECB, CoinDesk,
 * Cointelegraph). Shapes verified 2026-10-06: RSS 2.0 <item> with title, link,
 * guid, pubDate (RFC 822) and optional description. Registry 'excerpt' => false
 * stores the headline only.
 */
final class RssNewsCollector extends BaseCollector
{
    public function collect(?string $etag, ?string $lastModified, Carbon $now): CollectResult
    {
        $result = $this->expectOk($this->fetcher->get($this->config['url'], $etag, $lastModified));
        if ($result->notModified()) {
            return new CollectResult(notModified: true, etag: $etag, lastModified: $lastModified);
        }

        return new CollectResult(items: $this->parse($result->body, $now), etag: $result->etag,
            lastModified: $result->lastModified, contentHash: hash('sha256', $result->body));
    }

    /** @return list<RawItem> */
    public function parse(string $body, Carbon $now): array
    {
        $body = ltrim($body, "\xEF\xBB\xBF \t\r\n");
        if (strlen($body) > (int) config('market_context.http.max_bytes', 262144) || preg_match('/<!DOCTYPE|<!ENTITY/i', $body)) {
            throw new SourceFetchException('UNSAFE_DOCUMENT');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET);
            if ($xml === false) {
                throw new SourceFetchException('BAD_FORMAT');
            }
            $nodes = $xml->xpath('//*[local-name()="item" or local-name()="entry"]') ?: [];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $maxTitle = (int) config('market_context.limits.max_title_chars', 240);
        $maxExcerpt = (int) config('market_context.limits.max_excerpt_chars', 300);
        $withExcerpt = (bool) ($this->config['excerpt'] ?? false);
        $items = [];
        foreach (array_slice($nodes, 0, (int) config('market_context.limits.max_items_per_source', 30)) as $node) {
            $read = fn (string $name): string => (string) (($node->xpath('./*[local-name()="'.$name.'"]') ?: [])[0] ?? '');
            $title = $this->clean($read('title'), $maxTitle);
            if ($title === '') {
                continue;
            }
            $link = trim($read('link'));
            if ($link === '') { // Atom: <link href="..."/>
                $atom = $node->xpath('./*[local-name()="link"]/@href');
                $link = trim((string) ($atom[0] ?? ''));
            }
            $url = $this->safeUrl($link);
            $guid = trim($read('guid') ?: $read('id'));
            $published = $this->parseDate($read('pubDate') ?: ($read('published') ?: $read('updated')), $now);
            $excerpt = $withExcerpt ? $this->clean($read('description') ?: $read('summary'), $maxExcerpt) : '';
            $items[] = new RawItem($this->source, $guid !== '' ? mb_substr($guid, 0, 191) : ($url !== null ? mb_substr($url, 0, 191) : null),
                $title, $excerpt !== '' && $excerpt !== $title ? $excerpt : null, $url, $published);
        }

        return $items;
    }

    private function safeUrl(string $url): ?string
    {
        // Only a bare https link is kept for display; never fetched by this module.
        return str_starts_with($url, 'https://') && strlen($url) <= 500 ? $url : null;
    }

    private function parseDate(string $value, Carbon $now): Carbon
    {
        try {
            $at = $value !== '' ? Carbon::parse($value)->utc() : $now->copy()->utc();
        } catch (Throwable) {
            $at = $now->copy()->utc();
        }

        // A feed cannot publish from the future; clamp so as-of math stays sane.
        return $at->greaterThan($now->copy()->utc()->addMinutes(5)) ? $now->copy()->utc() : $at;
    }
}
