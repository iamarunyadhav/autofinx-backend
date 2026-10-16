<?php

namespace App\Domain\MarketOpportunityBrain\Adapters;

/**
 * Thrown whenever a real-network call is attempted while this module's own config says it must
 * not run (MASTER_SPEC.md Layer D/K: "Disable all schedulers, network/model downloads... by
 * default"). Never caught-and-ignored into a silent no-op at the call site that raised it --
 * callers must surface this as a BLOCKED/DISABLED status, never pretend data was fetched.
 */
class MarketDataDisabledException extends \RuntimeException
{
}
