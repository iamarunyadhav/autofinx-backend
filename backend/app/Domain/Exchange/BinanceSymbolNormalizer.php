<?php

namespace App\Domain\Exchange;

class BinanceSymbolNormalizer
{
    public static function normalize(?string $symbol): string
    {
        $raw = strtoupper(trim((string) $symbol));

        return preg_replace('/[^A-Z0-9]/', '', $raw) ?: '';
    }
}
