<?php

namespace App\Domain\Ml\Training;

use RuntimeException;

/** The python trainer child exited unsuccessfully (or was killed by a signal). */
final class TrainerProcessFailed extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $exitCode = null, public readonly bool $signaled = false)
    {
        parent::__construct($message);
    }
}
