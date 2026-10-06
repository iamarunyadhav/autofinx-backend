<?php

namespace App\Domain\MarketContext\Support;

final class AssetClass
{
    public const CRYPTO = 'CRYPTO';
    public const FOREX = 'FOREX';
    public const METALS = 'METALS';

    public const ALL = [self::CRYPTO, self::FOREX, self::METALS];

    /** Instrument key used for market-wide metrics (e.g. Fear & Greed). */
    public const MARKET_WIDE = 'ALL';
}
