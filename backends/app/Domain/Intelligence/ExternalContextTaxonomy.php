<?php

namespace App\Domain\Intelligence;

final class ExternalContextTaxonomy
{
    public const CATEGORY_MACRO_EVENT = 'MACRO_EVENT';
    public const CATEGORY_CRYPTO_MARKET_NEWS = 'CRYPTO_MARKET_NEWS';
    public const CATEGORY_EXCHANGE_INCIDENT = 'EXCHANGE_INCIDENT';
    public const CATEGORY_REGULATORY_NEWS = 'REGULATORY_NEWS';
    public const CATEGORY_GEOPOLITICAL_RISK = 'GEOPOLITICAL_RISK';
    public const CATEGORY_SYSTEMIC_MARKET_STRESS = 'SYSTEMIC_MARKET_STRESS';
    public const CATEGORY_ASSET_SPECIFIC_NEWS = 'ASSET_SPECIFIC_NEWS';
    public const CATEGORY_NETWORK_OUTAGE = 'NETWORK_OUTAGE';
    public const CATEGORY_SECURITY_INCIDENT = 'SECURITY_INCIDENT';
    public const CATEGORY_LIQUIDITY_RISK = 'LIQUIDITY_RISK';

    public const SEVERITY_LOW = 'LOW';
    public const SEVERITY_MEDIUM = 'MEDIUM';
    public const SEVERITY_HIGH = 'HIGH';
    public const SEVERITY_CRITICAL = 'CRITICAL';

    public const IMPACT_IGNORE = 'IGNORE';
    public const IMPACT_CAUTION = 'CAUTION';
    public const IMPACT_REDUCE_CONFIDENCE = 'REDUCE_CONFIDENCE';
    public const IMPACT_NO_TRADE = 'NO_TRADE';
    public const IMPACT_PAUSE_REVIEW = 'PAUSE_REVIEW';

    public static function severityRank(string $severity): int
    {
        return match (strtoupper($severity)) {
            self::SEVERITY_CRITICAL => 4,
            self::SEVERITY_HIGH => 3,
            self::SEVERITY_MEDIUM => 2,
            default => 1,
        };
    }
}

