<?php

namespace App\Domain\Exchange\DTO;

class ExchangeOrder
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $status,
        public readonly ?string $symbol,
        public readonly ?string $side,
        public readonly ?string $type,
        public readonly float $origQty,
        public readonly float $executedQty,
        public readonly ?float $price,
        public readonly array $raw,
    ) {
    }

    public static function fromBinance(array $payload): self
    {
        $orderId = (string) ($payload['orderId'] ?? $payload['orderListId'] ?? '');

        return new self(
            orderId: $orderId,
            status: strtoupper((string) ($payload['status'] ?? $payload['listStatusType'] ?? 'NEW')),
            symbol: isset($payload['symbol']) ? (string) $payload['symbol'] : null,
            side: isset($payload['side']) ? strtoupper((string) $payload['side']) : null,
            type: isset($payload['type']) ? strtoupper((string) $payload['type']) : null,
            origQty: (float) ($payload['origQty'] ?? $payload['quantity'] ?? 0),
            executedQty: (float) ($payload['executedQty'] ?? 0),
            price: isset($payload['price']) ? (float) $payload['price'] : null,
            raw: $payload
        );
    }

    public function toArray(): array
    {
        return [
            'orderId' => $this->orderId,
            'status' => $this->status,
            'symbol' => $this->symbol,
            'side' => $this->side,
            'type' => $this->type,
            'origQty' => $this->origQty,
            'executedQty' => $this->executedQty,
            'price' => $this->price,
            'raw' => $this->raw,
        ];
    }
}
