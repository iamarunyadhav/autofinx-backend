<?php

namespace App\Domain\Exchange;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Binance answered an order request with a definite rejection, so no order
 * exists on the exchange (e.g. -2010 insufficient balance, -1013 filter
 * failure). Per the Binance API docs a 4XX means the request was refused;
 * only a 5XX, or -1007 "timeout waiting for response from backend", leaves
 * the execution status UNKNOWN. Callers may treat this as a plain failure
 * instead of an uncertain order that needs manual reconciliation.
 */
class BinanceOrderRejectedException extends RuntimeException
{
    private const UNKNOWN_STATUS_CODES = [-1007];

    public function __construct(
        string $message,
        public readonly int $httpStatus,
        public readonly ?int $binanceCode,
    ) {
        parent::__construct($message);
    }

    public static function fromResponse(Response $response, string $message): ?self
    {
        $status = $response->status();
        $code = is_numeric($response->json('code')) ? (int) $response->json('code') : null;
        if ($status < 400 || $status >= 500 || $status === 408 || in_array($code, self::UNKNOWN_STATUS_CODES, true)) {
            return null;
        }

        return new self($message.' '.$response->body(), $status, $code);
    }

    public static function in(\Throwable $e): ?self
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof self) {
                return $current;
            }
        }

        return null;
    }
}
