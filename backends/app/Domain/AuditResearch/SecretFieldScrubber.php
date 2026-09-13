<?php

namespace App\Domain\AuditResearch;

/**
 * Shared secret-shaped-field filter used by every audit-event write and
 * every export formatter (CSV/JSON/Markdown). Applied recursively so a
 * secret buried inside a nested metadata array is caught too, not just a
 * top-level key.
 */
final class SecretFieldScrubber
{
    private const BLOCKED_NEEDLES = [
        'api_key',
        'apikey',
        'api_secret',
        'apisecret',
        'secret',
        'password',
        'passwd',
        'token',
        'private_key',
        'privatekey',
    ];

    public static function scrub(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && self::isBlockedKey($key)) {
                $result[$key] = '[REDACTED]';
                continue;
            }

            $result[$key] = is_array($item) ? self::scrub($item) : $item;
        }

        return $result;
    }

    private static function isBlockedKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', ' '], '_', $key));
        foreach (self::BLOCKED_NEEDLES as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }
}
