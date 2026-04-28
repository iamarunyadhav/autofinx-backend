<?php

namespace App\Domain\Fast2X;

final class Types
{
    public static function priceTick(string $symbol, float $price, array $data = []): array
    {
        $bid = isset($data['bid']) ? (float) $data['bid'] : null;
        $ask = isset($data['ask']) ? (float) $data['ask'] : null;

        return [
            'symbol' => strtoupper($symbol),
            'price' => $price,
            'bid' => $bid,
            'ask' => $ask,
            'spread' => $bid && $ask ? max(0, $ask - $bid) : ($data['spread'] ?? null),
            'volume' => isset($data['volume']) ? (float) $data['volume'] : null,
            'eventTime' => (int) ($data['eventTime'] ?? floor(microtime(true) * 1000)),
            'receivedAt' => (int) ($data['receivedAt'] ?? floor(microtime(true) * 1000)),
        ];
    }
}
