<?php

namespace App\Domain\StrategyLab;

use RuntimeException;

/** A refused Strategy Lab action; the controller returns it as 422 {message, code}. */
final class StrategyLabException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
