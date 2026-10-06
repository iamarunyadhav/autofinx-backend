<?php

namespace App\Domain\MarketContext\Classification;

use App\Domain\Intelligence\ExternalContextTaxonomy as Tax;
use App\Domain\MarketContext\Data\RawItem;

/**
 * Deterministic Stage-1 classifier (plan section 6, rules only; no LLM in Phase 1).
 * Titles are untrusted data: they are only ever matched against fixed patterns.
 * The highest-severity matching rule wins; otherwise the source default applies.
 */
final class RuleClassifier
{
    public const VERSION = 'rules-1';

    /** @var list<array{0:string,1:string,2:string,3:bool}> pattern, category, severity, macroSourceOnly */
    private const RULES = [
        ['/\b(hack(ed|ers?)?|exploit(ed|s)?|drained|stolen|breach(ed)?|rug ?pull)\b/i', Tax::CATEGORY_SECURITY_INCIDENT, Tax::SEVERITY_HIGH, false],
        ['/\b(insolven\w*|bankrupt\w*|withdrawals? (suspended|halted|paused)|suspends? (trading|withdrawals?)|halts? (trading|withdrawals?))\b/i', Tax::CATEGORY_EXCHANGE_INCIDENT, Tax::SEVERITY_HIGH, false],
        ['/\b(depeg(ged)?|contagion)\b/i', Tax::CATEGORY_SYSTEMIC_MARKET_STRESS, Tax::SEVERITY_HIGH, false],
        ['/\b(crash(es|ed)?|plunge[sd]?|plummets?|liquidation (cascade|wave))\b/i', Tax::CATEGORY_SYSTEMIC_MARKET_STRESS, Tax::SEVERITY_MEDIUM, false],
        ['/\b(network|chain|mainnet) (outage|halt(ed)?|down|stall(ed)?)\b|\boutage\b/i', Tax::CATEGORY_NETWORK_OUTAGE, Tax::SEVERITY_MEDIUM, false],
        ['/\b(SEC|CFTC|DOJ)\b|\b(lawsuit|sues?|sued|crackdown|subpoena\w*|indict\w*|sanction\w*)\b|\bban(s|ned)?\b/', Tax::CATEGORY_REGULATORY_NEWS, Tax::SEVERITY_MEDIUM, false],
        ['/\b(war|invasion|missile|airstrikes?|tariffs?)\b/i', Tax::CATEGORY_GEOPOLITICAL_RISK, Tax::SEVERITY_MEDIUM, false],
        ['/\b(system )?maintenance\b/i', Tax::CATEGORY_EXCHANGE_INCIDENT, Tax::SEVERITY_LOW, false],
        ['/FOMC statement|monetary policy decisions?|Federal Open Market Committee (issues|announces)/i', Tax::CATEGORY_MACRO_EVENT, Tax::SEVERITY_HIGH, true],
        ['/\b(FOMC|federal funds rate|interest rates?|rate (cut|hike|decision)|inflation|CPI|Governing Council)\b/i', Tax::CATEGORY_MACRO_EVENT, Tax::SEVERITY_MEDIUM, true],
    ];

    /** @return array{category:string,severity:string,version:string} */
    public function classify(RawItem $item, array $source): array
    {
        $category = (string) ($source['category'] ?? Tax::CATEGORY_CRYPTO_MARKET_NEWS);
        $severity = (string) ($source['severity'] ?? Tax::SEVERITY_LOW);

        if ($item->hint === 'delisting') {
            [$category, $severity] = [Tax::CATEGORY_EXCHANGE_INCIDENT, Tax::SEVERITY_MEDIUM];
        } elseif ($item->hint === 'listing') {
            [$category, $severity] = [Tax::CATEGORY_ASSET_SPECIFIC_NEWS, Tax::SEVERITY_LOW];
        }

        $isMacroSource = ($source['category'] ?? null) === Tax::CATEGORY_MACRO_EVENT;
        $text = $item->title.' '.($item->excerpt ?? '');
        $matched = false;
        foreach (self::RULES as [$pattern, $ruleCategory, $ruleSeverity, $macroOnly]) {
            if ($macroOnly && ! $isMacroSource) {
                continue;
            }
            $rank = Tax::severityRank($ruleSeverity);
            // Strictly higher severity wins; an equal-severity rule only names the category once.
            if (($rank > Tax::severityRank($severity) || (! $matched && $rank === Tax::severityRank($severity))) && preg_match($pattern, $text) === 1) {
                [$category, $severity] = [$ruleCategory, $ruleSeverity];
                $matched = true;
            }
        }

        return ['category' => $category, 'severity' => $severity, 'version' => self::VERSION];
    }
}
