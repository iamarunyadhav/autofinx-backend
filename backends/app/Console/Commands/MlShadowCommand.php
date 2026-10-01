<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Shared plumbing for the Quick Trade ML shadow commands. Scheduled commands
 * do nothing unless ml.enabled is true; manual ones may pass --force.
 */
abstract class MlShadowCommand extends Command
{
    /** @return list<string> */
    protected function symbols(): array
    {
        $configured = (array) config('ml.symbols', []);
        $requested = $this->hasOption('symbol') ? array_filter((array) $this->option('symbol')) : [];
        if ($requested === []) {
            return array_values($configured);
        }
        $requested = array_values(array_unique(array_map(static fn ($s): string => strtoupper(trim((string) $s)), $requested)));
        $unknown = array_diff($requested, $configured);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Not configured in ml.symbols: '.implode(',', $unknown));
        }

        return $requested;
    }

    protected function disabled(): bool
    {
        if ((bool) config('ml.enabled', false) || ($this->hasOption('force') && $this->option('force'))) {
            return false;
        }
        $this->line('ML shadow learning is disabled (AUTOFINX_ML_SHADOW_ENABLED=false); skipped.');

        return true;
    }
}
