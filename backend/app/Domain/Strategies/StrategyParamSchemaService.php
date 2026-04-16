<?php

namespace App\Domain\Strategies;

class StrategyParamSchemaService
{
    public function catalog(): array
    {
        return (array) config('bot_strategies.types', []);
    }

    public function allowedTypes(): array
    {
        return array_keys($this->catalog());
    }

    public function isSupported(string $strategyType): bool
    {
        return isset($this->catalog()[strtolower($strategyType)]);
    }

    public function schema(string $strategyType): array
    {
        $strategyType = strtolower(trim($strategyType));
        return (array) ($this->catalog()[$strategyType] ?? []);
    }

    public function normalize(string $strategyType, array $params, ?string $assetClass = null): array
    {
        $schema = $this->schema($strategyType);
        $definitions = (array) ($schema['params'] ?? []);
        $normalized = [];

        foreach ($definitions as $key => $definition) {
            $definition = (array) $definition;
            $value = array_key_exists($key, $params)
                ? $params[$key]
                : ($definition['default'] ?? null);
            $normalized[$key] = $this->normalizeValue($value, $definition);
        }

        // Keep non-schema keys for forward compatibility / strategy internal state.
        foreach ($params as $key => $value) {
            if (! array_key_exists($key, $normalized)) {
                $normalized[$key] = $value;
            }
        }

        if ($assetClass) {
            $preset = (array) (($schema['presets'][strtoupper($assetClass)] ?? []));
            foreach ($preset as $key => $value) {
                if (! array_key_exists($key, $params)) {
                    $normalized[$key] = $value;
                }
            }
        }

        return $normalized;
    }

    private function normalizeValue(mixed $value, array $definition): mixed
    {
        $type = strtolower((string) ($definition['type'] ?? 'string'));
        $min = $definition['min'] ?? null;
        $max = $definition['max'] ?? null;
        $enum = $definition['enum'] ?? null;

        $normalized = match ($type) {
            'int', 'integer' => (int) $value,
            'float', 'double', 'number' => (float) $value,
            'bool', 'boolean' => $this->toBool($value),
            default => is_scalar($value) ? (string) $value : $value,
        };

        if (is_array($enum) && count($enum) > 0) {
            if (! in_array($normalized, $enum, true)) {
                $normalized = $definition['default'] ?? $enum[0];
            }
        }

        if (is_numeric($normalized)) {
            $numeric = (float) $normalized;
            if (is_numeric($min)) {
                $numeric = max($numeric, (float) $min);
            }
            if (is_numeric($max)) {
                $numeric = min($numeric, (float) $max);
            }
            if ($type === 'int' || $type === 'integer') {
                return (int) round($numeric);
            }

            return $numeric;
        }

        return $normalized;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return ((int) $value) === 1;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
