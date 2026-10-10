<?php

namespace App\Domain\QuickTradeManual\Exchange;

use RuntimeException;

/**
 * Failure talking to the exchange for a manual SL/TP position.
 *
 * `ambiguous` is the load-bearing flag: TRUE means the request may or may not have
 * reached the exchange (timeout, 5xx, connection reset) so the caller MUST resolve the
 * truth by querying; FALSE means the exchange definitely rejected it (or it was never
 * sent), so nothing happened on the exchange side.
 */
class ManualExchangeException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $exchangeCode = null,
        public readonly bool $ambiguous = true,
    ) {
        parent::__construct($message);
    }

    public static function rejected(string $message, ?int $code = null): self
    {
        return new self($message, $code, false);
    }

    public static function unknownOutcome(string $message, ?int $code = null): self
    {
        return new self($message, $code, true);
    }
}
