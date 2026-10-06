<?php

namespace App\Domain\MarketContext\Collectors;

use App\Domain\MarketContext\Contracts\ContextCollector;
use App\Domain\MarketContext\Data\FetchResult;
use App\Domain\MarketContext\Fetch\SafeHttpFetcher;

abstract class BaseCollector implements ContextCollector
{
    public function __construct(
        protected readonly string $source,
        protected readonly array $config,
        protected readonly SafeHttpFetcher $fetcher,
    ) {}

    public function sourceId(): string
    {
        return $this->source;
    }

    /** Strict: anything other than 2xx/304 becomes a short error code. */
    protected function expectOk(FetchResult $result): FetchResult
    {
        if ($result->ok() || $result->notModified()) {
            return $result;
        }

        throw new SourceFetchException('HTTP_'.$result->status);
    }

    protected function clean(?string $text, int $max): string
    {
        $text = html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_substr($text, 0, $max);
    }
}
