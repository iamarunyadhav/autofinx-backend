<?php

namespace App\Domain\MarketContext\Support;

/** Read-only view over config('market_context.profiles'). */
final class ProfileRegistry
{
    /** @return array<string, array> enabled profiles keyed by instrument */
    public function enabled(): array
    {
        return array_filter((array) config('market_context.profiles', []), fn ($p) => (bool) ($p['enabled'] ?? false));
    }

    public function find(string $assetClass, string $instrument): ?array
    {
        $profile = config('market_context.profiles.'.strtoupper($instrument));

        return is_array($profile) && strtoupper((string) ($profile['asset_class'] ?? '')) === strtoupper($assetClass) ? $profile : null;
    }

    /** @return list<string> enabled instruments of an asset class */
    public function instrumentsFor(string $assetClass): array
    {
        return array_keys(array_filter($this->enabled(), fn ($p) => $p['asset_class'] === $assetClass));
    }

    /** @return array<string, array> enabled profiles that list this collector, keyed by instrument */
    public function usingCollector(string $sourceId): array
    {
        return array_filter($this->enabled(), fn ($p) => in_array($sourceId, (array) ($p['collectors'] ?? []), true));
    }

    /** True when the title/text names this profile (tickers case-sensitive, names case-insensitive). */
    public function matches(array $profile, string $text): bool
    {
        foreach ((array) ($profile['aliases']['tickers'] ?? []) as $ticker) {
            if (preg_match('/(?<![A-Za-z0-9])'.preg_quote($ticker, '/').'(?![A-Za-z0-9])/', $text) === 1) {
                return true;
            }
        }
        foreach ((array) ($profile['aliases']['names'] ?? []) as $name) {
            if (preg_match('/(?<![A-Za-z0-9])'.preg_quote($name, '/').'(?![A-Za-z0-9])/i', $text) === 1) {
                return true;
            }
        }

        return false;
    }
}
