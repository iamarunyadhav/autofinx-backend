<?php

namespace App\Domain\Nexus;

final class NexusCapabilityGapService
{
    /** Missing at a registered location is a verification task, not proof no implementation exists. */
    public function detect(array $capabilities, array $observations): array
    {
        $gaps = [];
        foreach ($capabilities as $capability) {
            if (! $capability['code_present']) {
                $gaps[] = ['module' => $capability['module'], 'kind' => 'CAPABILITY_VERIFICATION', 'reason' => 'Registered implementation not found; verify renamed or moved code before building.'];
            }
        }
        foreach ($observations['modules'] ?? [] as $module => $observation) {
            if ($observation['status'] === 'ADAPTER_REQUIRED') {
                $gaps[] = ['module' => $module, 'kind' => 'DATA_ADAPTER', 'reason' => 'No verified workspace-scoped observation adapter.'];
            } elseif ($observation['sample_size'] === 0) {
                $gaps[] = ['module' => $module, 'kind' => 'INSUFFICIENT_OBSERVATIONS', 'reason' => 'No rows observed in the last 24 hours; runtime capability is unknown.'];
            }
        }

        return $gaps;
    }
}
