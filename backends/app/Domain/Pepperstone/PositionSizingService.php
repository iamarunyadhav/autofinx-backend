<?php

namespace App\Domain\Pepperstone;

use App\Models\InstrumentMapping;
use App\Models\PepperstoneBotConfig;

/**
 * Broker-agnostic position-sizing engine for the Pepperstone Metals &
 * Energy module.
 *
 * SHARED-VS-DUPLICATED DECISION (documented per this task's requirement):
 * lotSizeForLevel() below reproduces, field-for-field, the exact three-mode
 * escalation formula already built and tested in
 * App\Domain\ForexGrid\GridLotSizer::lotSizeForLevel() (fixed / martingale /
 * hybrid). That class's own docblock says it is broker-agnostic pure math
 * with zero I/O, so in principle one shared class could serve both
 * modules. It is deliberately DUPLICATED, not extracted, here: extracting a
 * shared class would require editing app/Domain/ForexGrid/GridLotSizer.php
 * (either moving its body out or making it delegate to a new shared
 * class), and this task's explicit zero-touch boundary forbids touching
 * anything under app/Domain/ForexGrid/*. Duplicating a ~15-line pure
 * function is a far smaller, more isolated risk than reaching into a
 * just-built, already-tested, independent module to change how it works
 * internally. If a shared extraction is ever wanted, it should be its own
 * deliberate, reviewed change touching both modules on purpose -- and must
 * keep the math byte-for-byte identical to both this method and
 * GridLotSizer::lotSizeForLevel() if so (see GridLotSizerTest for the
 * boundary cases both copies must keep satisfying).
 */
class PositionSizingService
{
    public function lotSizeForLevel(int $levelIndex, PepperstoneBotConfig $config): float
    {
        if ($config->position_sizing_mode === 'fixed') {
            return (float) $config->base_lot_size;
        }

        if ($config->position_sizing_mode === 'martingale') {
            $size = (float) $config->base_lot_size * ((float) $config->martingale_multiplier ** $levelIndex);
            return min($size, (float) $config->martingale_max_lot_size);
        }

        // hybrid
        $escalationLevels = (int) $config->hybrid_martingale_level_count;
        if ($levelIndex < $escalationLevels) {
            $size = (float) $config->base_lot_size * ((float) $config->martingale_multiplier ** $levelIndex);
            return min($size, (float) $config->martingale_max_lot_size);
        }

        return min(
            (float) $config->base_lot_size * ((float) $config->martingale_multiplier ** ($escalationLevels - 1)),
            (float) $config->martingale_max_lot_size,
        );
    }

    /**
     * Resolves the raw escalation-formula lot size down to a broker-valid
     * volume: rounds DOWN to the nearest instrument_mappings.volume_step,
     * then REJECTS (never rounds back up) if the result is below
     * min_volume. Rounding up past the intended risk is never acceptable
     * here -- a rejection is always the safe failure mode.
     *
     * @return array{lots: float|null, rejected: bool, reason: string|null, raw_lots: float}
     */
    public function sizeForLevel(int $levelIndex, PepperstoneBotConfig $config, InstrumentMapping $mapping): array
    {
        $raw = $this->lotSizeForLevel($levelIndex, $config);

        return $this->roundToVolumeStep($raw, $mapping);
    }

    /**
     * Shared by sizeForLevel() (escalation-formula sizing) and
     * sizeEqualLot() (flat basket sizing) -- rounds DOWN to the nearest
     * instrument_mappings.volume_step, then REJECTS (never rounds back up)
     * if the result is below min_volume. Extracted verbatim from
     * sizeForLevel()'s original body; behavior is byte-for-byte identical
     * to before this extraction (see PositionSizingServiceTest's existing
     * rounding/rejection cases, unchanged).
     *
     * @return array{lots: float|null, rejected: bool, reason: string|null, raw_lots: float}
     */
    private function roundToVolumeStep(float $raw, InstrumentMapping $mapping): array
    {
        $step = (float) $mapping->volume_step;
        $minVolume = (float) $mapping->min_volume;

        $rounded = $raw;
        if ($step > 0) {
            // round() guards against float noise like 0.30000000000000004
            // before the below-min-volume comparison.
            $rounded = round(floor(round($raw / $step, 8) + 1e-9) * $step, 8);
        }

        if ($rounded <= 0 || $rounded < $minVolume) {
            return [
                'lots' => null,
                'rejected' => true,
                'reason' => 'below_min_volume',
                'raw_lots' => $raw,
            ];
        }

        return [
            'lots' => $rounded,
            'rejected' => false,
            'reason' => null,
            'raw_lots' => $raw,
        ];
    }

    /**
     * Required margin for `lots` of this instrument at `price`, using
     * instrument_mappings.margin_requirement (a fraction of notional --
     * e.g. 0.05 for 20:1 leverage). Defaults to 1.0 (no leverage, the
     * conservative worst case) when the mapping has no margin_requirement
     * configured, since no real Pepperstone leverage schedule is confirmed
     * yet (see PepperstoneBrokerAdapter's docblock).
     */
    /**
     * Equal-lot sizing for basket configs (MTF/Basket plan §9) -- the ONLY
     * sizing path basket configs use. Deliberately ignores
     * position_sizing_mode/martingale_* entirely (no martingale/hybrid
     * choice exists for this bot type) and always sizes to the config's
     * flat `lot_per_level`, every level, every time. Reuses the exact same
     * volume-step-rounding/min-volume-rejection logic as sizeForLevel() (via
     * the shared roundToVolumeStep() helper) -- that part of the existing
     * sizing service is correct and mode-agnostic, so it is reused, not
     * duplicated.
     *
     * @return array{lots: float|null, rejected: bool, reason: string|null, raw_lots: float}
     */
    public function sizeEqualLot(PepperstoneBotConfig $config, InstrumentMapping $mapping): array
    {
        $raw = (float) $config->lot_per_level;

        return $this->roundToVolumeStep($raw, $mapping);
    }

    public function requiredMargin(float $lots, float $price, InstrumentMapping $mapping): float
    {
        $contractSize = (float) $mapping->contract_size;
        $marginRequirement = $mapping->margin_requirement !== null ? (float) $mapping->margin_requirement : 1.0;

        return $lots * $contractSize * $price * $marginRequirement;
    }

    public function hasSufficientMargin(float $lots, float $price, InstrumentMapping $mapping, float $freeMargin): bool
    {
        return $this->requiredMargin($lots, $price, $mapping) <= $freeMargin;
    }
}
