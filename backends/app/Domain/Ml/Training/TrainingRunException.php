<?php

namespace App\Domain\Ml\Training;

use RuntimeException;

/** A refused control-plane request; rendered as HTTP 422 {message, code}. */
final class TrainingRunException extends RuntimeException
{
    public const ML_DISABLED = 'ml_disabled';

    public const ALREADY_ACTIVE = 'already_active';

    public const NOT_STOPPABLE = 'not_stoppable';

    public const NOT_RETRYABLE = 'not_retryable';

    public const UNKNOWN_SYMBOL = 'unknown_symbol';

    public const UNKNOWN_HORIZON = 'unknown_horizon';

    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
