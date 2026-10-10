<?php

namespace App\Domain\Exchange;

use RuntimeException;

/**
 * A conditional (stop / take-profit) order failed. Carries the exact Binance code/message so
 * callers and logs never have to parse a string. `outcome`:
 *  - rejected : Binance definitely did NOT place the order (or it was never sent)
 *  - unknown  : the request may or may not have been placed and the lookup could not settle it
 */
class BinanceConditionalOrderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $endpoint,
        public readonly string $outcome,
        public readonly ?int $httpStatus = null,
        public readonly ?int $binanceCode = null,
        public readonly ?string $binanceMsg = null,
    ) {
        parent::__construct($message);
    }

    /** Algo endpoint definitely cannot serve this request (route/type/params unsupported). */
    public function isAlgoUnsupported(): bool
    {
        return $this->endpoint === 'algo' && $this->outcome === 'rejected'
            && BinanceAlgoOrderApi::isUnsupportedRejection($this->httpStatus, $this->binanceCode);
    }

    /** Legacy endpoint answered "use the Algo Order API" (-4120). */
    public function isAlgoRequired(): bool
    {
        return $this->endpoint === 'legacy' && $this->binanceCode === BinanceAlgoOrderApi::LEGACY_ALGO_REQUIRED_CODE;
    }

    public function context(): array
    {
        return [
            'endpoint' => $this->endpoint,
            'outcome' => $this->outcome,
            'http_status' => $this->httpStatus,
            'binance_code' => $this->binanceCode,
            'binance_msg' => $this->binanceMsg,
        ];
    }
}
