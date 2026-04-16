<?php

namespace App\Domain\Trading;

use InvalidArgumentException;

class TimeframeRegistry
{
    private const MAP = [
        '5s' => 5,
        '15s' => 15,
        '30s' => 30,
        '1m' => 60,
        '5m' => 300,
        '15m' => 900,
    ];

    public function normalize(?string $timeframe, string $default = '1m'): string
    {
        $candidate = strtolower(trim((string) ($timeframe ?: $default)));

        if (! array_key_exists($candidate, self::MAP)) {
            throw new InvalidArgumentException("Unsupported timeframe [{$candidate}]");
        }

        return $candidate;
    }

    public function seconds(string $timeframe): int
    {
        return self::MAP[$this->normalize($timeframe)];
    }

    public function options(): array
    {
        $labels = [
            '5s' => '5 sec',
            '15s' => '15 sec',
            '30s' => '30 sec',
            '1m' => '1 min',
            '5m' => '5 min',
            '15m' => '15 min',
        ];

        return collect(self::MAP)
            ->map(fn (int $seconds, string $key) => [
                'key' => $key,
                'label' => $labels[$key],
                'seconds' => $seconds,
            ])
            ->values()
            ->all();
    }

    public function optionsFor(array $keys): array
    {
        $allowed = collect($keys)
            ->map(fn ($key) => strtolower(trim((string) $key)))
            ->filter(fn (string $key) => array_key_exists($key, self::MAP))
            ->values()
            ->all();

        return collect($this->options())
            ->filter(fn (array $option) => in_array($option['key'], $allowed, true))
            ->values()
            ->all();
    }
}
