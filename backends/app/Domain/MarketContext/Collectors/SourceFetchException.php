<?php

namespace App\Domain\MarketContext\Collectors;

use RuntimeException;

/** Carries a short machine code only: never an upstream body, URL or credential. */
final class SourceFetchException extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}
