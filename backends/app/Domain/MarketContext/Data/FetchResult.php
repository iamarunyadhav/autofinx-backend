<?php

namespace App\Domain\MarketContext\Data;

use Illuminate\Http\Client\Response;

final class FetchResult
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly ?string $etag,
        public readonly ?string $lastModified,
        public readonly Response $response,
    ) {}

    public function notModified(): bool
    {
        return $this->status === 304;
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
