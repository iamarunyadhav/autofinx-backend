<?php

namespace App\Domain\RatioBot;

use App\Models\RatioBotConfig;
use App\Models\RatioBotDecision;
use App\Models\RatioBotLot;
use Illuminate\Support\Collection;

class RatioBotLotService
{
    /**
     * A "cycle" is one full BUY(s) -> SELL(s) -> FLAT round trip.
     * RatioBotStateService::onSellFilled() increments this on every full
     * exit, and every lot is stamped with the cycle it was opened in — so
     * buyStagesFilled()/sellStagesCompleted() only ever count the CURRENT
     * cycle's lots. Without this, a bot that completed one full cycle would
     * see buyFilled >= buyStages forever (old closed lots never stop
     * counting) and simply never buy again — contradicts "operate
     * autonomously until stopped."
     */
    public function currentCycleNumber(RatioBotConfig $config): int
    {
        return max(1, (int) ($config->state?->current_cycle_number ?? 1));
    }

    /**
     * @param string|null $lotType filter to RatioBotLot::TYPE_NORMAL/TYPE_RECOVERY/TYPE_SLOT_FUNDED; null returns all.
     * @return Collection<int, RatioBotLot>
     */
    public function openLots(RatioBotConfig $config, ?string $lotType = null): Collection
    {
        return RatioBotLot::query()
            ->where('ratio_bot_config_id', $config->id)
            ->where('cycle_number', $this->currentCycleNumber($config))
            ->where('status', RatioBotLot::STATUS_OPEN)
            ->where('remaining_qty', '>', 0)
            ->when($lotType !== null, fn ($query) => $query->where('lot_type', $lotType))
            ->orderBy('buy_stage_index')
            ->get();
    }

    public function buyStagesFilled(RatioBotConfig $config): int
    {
        return (int) RatioBotLot::query()
            ->where('ratio_bot_config_id', $config->id)
            ->where('cycle_number', $this->currentCycleNumber($config))
            ->count();
    }

    public function sellStagesCompleted(RatioBotConfig $config): int
    {
        return (int) RatioBotLot::query()
            ->where('ratio_bot_config_id', $config->id)
            ->where('cycle_number', $this->currentCycleNumber($config))
            ->where('status', RatioBotLot::STATUS_CLOSED)
            ->count();
    }

    public function createBuyLot(
        RatioBotConfig $config,
        RatioBotDecision $decision,
        float $price,
        float $qty,
        string $orderId,
    ): RatioBotLot {
        $stageIndex = max(1, (int) data_get($decision->metadata, 'stage.index', $this->buyStagesFilled($config) + 1));
        $allocationPct = (float) data_get($decision->metadata, 'stage.allocation_pct', 0);
        $lotType = (string) data_get($decision->metadata, 'stage.type', RatioBotLot::TYPE_NORMAL);

        return RatioBotLot::create([
            'ratio_bot_config_id' => $config->id,
            'cycle_number' => $this->currentCycleNumber($config),
            'buy_decision_id' => $decision->id,
            'buy_order_id' => $orderId,
            'buy_stage_index' => $stageIndex,
            'lot_type' => in_array($lotType, [RatioBotLot::TYPE_NORMAL, RatioBotLot::TYPE_RECOVERY, RatioBotLot::TYPE_SLOT_FUNDED], true) ? $lotType : RatioBotLot::TYPE_NORMAL,
            'allocation_pct' => $allocationPct,
            'buy_price' => $price,
            'original_qty' => $qty,
            'remaining_qty' => $qty,
            'spent_quote' => $price * $qty,
            'status' => RatioBotLot::STATUS_OPEN,
            'bought_at' => now(),
        ]);
    }

    /**
     * Multi-asset spec Part B: once a position enters recovery, every
     * recovery-driven buy for that stuck episode merges into ONE averaged
     * lot rather than creating a separate row each time -- this is the only
     * place that decides "merge vs. create", callers never branch on it.
     * The merged lot gets its own, more lenient profit floor (see
     * RatioBotConfig::recoveryLotMinProfitRatio() and how
     * profitableFifoSellPlan()/reverseSellPlan() read it below) — a normal
     * lot is entirely unaffected by this method existing.
     */
    public function recordRecoveryBuyFill(
        RatioBotConfig $config,
        RatioBotDecision $decision,
        float $price,
        float $qty,
        string $orderId,
    ): RatioBotLot {
        $existing = RatioBotLot::query()
            ->where('ratio_bot_config_id', $config->id)
            ->where('cycle_number', $this->currentCycleNumber($config))
            ->where('status', RatioBotLot::STATUS_OPEN)
            ->where('is_recovery_average', true)
            ->first();

        if (! $existing) {
            $lot = $this->createBuyLot($config, $decision, $price, $qty, $orderId);
            $lot->update(['is_recovery_average' => true]);

            return $lot->fresh();
        }

        $oldQty = (float) $existing->original_qty;
        $oldPrice = (float) $existing->buy_price;
        $newQty = $oldQty + $qty;
        $newPrice = $newQty > 0 ? (($oldQty * $oldPrice) + ($qty * $price)) / $newQty : $price;

        $existing->update([
            'buy_price' => $newPrice,
            'original_qty' => $newQty,
            'remaining_qty' => (float) $existing->remaining_qty + $qty,
            'spent_quote' => (float) $existing->spent_quote + ($price * $qty),
        ]);

        return $existing->fresh();
    }

    /**
     * Seeds a lot with no backing RatioBotDecision/exchange order — used only
     * by RatioBotStateService::anchorStartingInventory() to onboard a
     * SELL-starting-entry bot's pre-existing coin holdings into the same
     * lot-based accounting normal BUY fills use, so the existing stage-sell/
     * recovery-sell logic manages it identically.
     */
    public function createStartingLot(RatioBotConfig $config, string $lotType, int $stageIndex, float $allocationPct, float $price, float $qty): RatioBotLot
    {
        return RatioBotLot::create([
            'ratio_bot_config_id' => $config->id,
            'cycle_number' => $this->currentCycleNumber($config),
            'buy_decision_id' => null,
            'buy_order_id' => null,
            'buy_stage_index' => max(1, $stageIndex),
            'lot_type' => in_array($lotType, [RatioBotLot::TYPE_NORMAL, RatioBotLot::TYPE_RECOVERY, RatioBotLot::TYPE_SLOT_FUNDED], true) ? $lotType : RatioBotLot::TYPE_NORMAL,
            'allocation_pct' => $allocationPct,
            'buy_price' => $price,
            'original_qty' => $qty,
            'remaining_qty' => $qty,
            'spent_quote' => $price * $qty,
            'status' => RatioBotLot::STATUS_OPEN,
            'bought_at' => now(),
        ]);
    }

    public function ensureStartingLotFromState(RatioBotConfig $config): ?RatioBotLot
    {
        if ($this->openLots($config)->count() > 0) {
            return null;
        }

        $state = $config->state;
        $price = (float) ($state?->last_buy_price ?? 0);
        $qty = (float) ($state?->last_buy_quantity ?? 0);
        if ($price <= 0 || $qty <= 0) {
            return null;
        }

        return $this->createStartingLot($config, RatioBotLot::TYPE_NORMAL, 1, 100, $price, $qty);
    }

    /**
     * FIFO sell plan for normal accounting: oldest open lot sells first, but
     * no non-protective sell may touch a lot below its own net-profit floor.
     *
     * @return array{qty: float, lot_ids: array<int, string>, min_buy_price: float|null, max_buy_price: float|null, skipped_unprofitable_qty: float}
     */
    public function profitableFifoSellPlan(RatioBotConfig $config, float $currentPrice, float $minNetProfitRatio, float $desiredQty, ?string $lotType = null): array
    {
        $this->ensureStartingLotFromState($config);

        $selectedQty = 0.0;
        $skippedQty = 0.0;
        $selectedLotIds = [];
        $minBuyPrice = null;
        $maxBuyPrice = null;
        $recoveryFloor = null;

        foreach ($this->openLots($config, $lotType) as $lot) {
            if ($desiredQty > 0 && $selectedQty >= $desiredQty) {
                break;
            }

            $buyPrice = (float) $lot->buy_price;
            $remaining = (float) $lot->remaining_qty;
            if ($remaining <= 0) {
                continue;
            }

            // Part B: the merged recovery-average lot gets its own, more
            // lenient (never below execution cost) floor — every other lot
            // keeps the standard ladder-wide minNetProfitRatio unchanged.
            if ((bool) $lot->is_recovery_average) {
                $recoveryFloor ??= $config->recoveryLotMinProfitRatio();
                $floorRatio = $recoveryFloor;
            } else {
                $floorRatio = $minNetProfitRatio;
            }

            if ($buyPrice <= 0 || $currentPrice < ($buyPrice * (1 + $floorRatio))) {
                $skippedQty += $remaining;
                continue;
            }

            $take = $desiredQty > 0 ? min($remaining, max(0.0, $desiredQty - $selectedQty)) : $remaining;
            if ($take <= 0) {
                continue;
            }

            $selectedQty += $take;
            $selectedLotIds[] = (string) $lot->id;
            $minBuyPrice = $minBuyPrice === null ? $buyPrice : min($minBuyPrice, $buyPrice);
            $maxBuyPrice = $maxBuyPrice === null ? $buyPrice : max($maxBuyPrice, $buyPrice);
        }

        return [
            'qty' => round($selectedQty, 8),
            'lot_ids' => $selectedLotIds,
            'min_buy_price' => $minBuyPrice,
            'max_buy_price' => $maxBuyPrice,
            'skipped_unprofitable_qty' => round($skippedQty, 8),
        ];
    }

    /**
     * Strict reverse-purchase-order (LIFO) selling: the most recently bought
     * open lot (highest buy_stage_index) sells first — BUY3 -> SELL1,
     * BUY2 -> SELL2, BUY1 -> SELL3. This never skips ahead to an older lot
     * just because it happens to be more profitable: if the most recent open
     * lot isn't profitable yet, selling STOPS there (the loop breaks) and
     * waits, rather than reaching past it to cash out an older one out of
     * order. Still hard-gated by minNetProfitRatio per lot — LIFO order
     * never overrides "never sell at a loss."
     *
     * @return array{qty: float, lot_ids: array<int, string>, allocation_pct: float, min_buy_price: float|null}
     */
    public function reverseSellPlan(RatioBotConfig $config, float $currentPrice, float $minNetProfitRatio, float $targetAllocationPct, ?string $lotType = null): array
    {
        $targetQty = 0.0;
        $selectedQty = 0.0;
        $selectedLotIds = [];
        $allocation = 0.0;
        $minBuyPrice = null;

        $openLots = $this->openLots($config, $lotType)
            ->sortByDesc('buy_stage_index')
            ->values();
        $recoveryFloor = null;

        foreach ($openLots as $lot) {
            $targetQty += (float) $lot->remaining_qty * max(0.0, min(100.0, $targetAllocationPct)) / 100;
        }

        foreach ($openLots as $lot) {
            if ($targetQty > 0 && $selectedQty >= $targetQty) {
                break;
            }

            $buyPrice = (float) $lot->buy_price;
            if ((bool) $lot->is_recovery_average) {
                $recoveryFloor ??= $config->recoveryLotMinProfitRatio();
                $floorRatio = $recoveryFloor;
            } else {
                $floorRatio = $minNetProfitRatio;
            }

            if ($buyPrice <= 0 || $currentPrice < ($buyPrice * (1 + $floorRatio))) {
                break; // LIFO: the most recent open lot isn't profitable yet — stop, don't reach past it to an older lot
            }

            $remaining = (float) $lot->remaining_qty;
            $take = $targetQty > 0 ? min($remaining, max(0.0, $targetQty - $selectedQty)) : $remaining;
            if ($take <= 0) {
                break;
            }

            $selectedQty += $take;
            $selectedLotIds[] = (string) $lot->id;
            $allocation += (float) $lot->allocation_pct * ($take / max((float) $lot->original_qty, 0.00000001));
            $minBuyPrice = $minBuyPrice === null ? $buyPrice : min($minBuyPrice, $buyPrice);
        }

        return [
            'qty' => round($selectedQty, 8),
            'lot_ids' => $selectedLotIds,
            'allocation_pct' => round($allocation, 4),
            'min_buy_price' => $minBuyPrice,
        ];
    }

    /**
     * @param array<int, string> $lotIds
     */
    public function applySellFill(RatioBotConfig $config, array $lotIds, float $price, float $qty, RatioBotDecision $decision, string $orderId): float
    {
        $remainingSellQty = $qty;
        $realized = 0.0;

        foreach ($lotIds as $lotId) {
            if ($remainingSellQty <= 0) {
                break;
            }

            $lot = RatioBotLot::query()
                ->where('ratio_bot_config_id', $config->id)
                ->whereKey($lotId)
                ->first();

            if (! $lot || $lot->status !== RatioBotLot::STATUS_OPEN) {
                continue;
            }

            $sellQty = min((float) $lot->remaining_qty, $remainingSellQty);
            if ($sellQty <= 0) {
                continue;
            }

            $lotRealized = ($price - (float) $lot->buy_price) * $sellQty;
            $remaining = round((float) $lot->remaining_qty - $sellQty, 8);
            $fills = (array) ($lot->sell_fills ?? []);
            $fills[] = [
                'decision_id' => (string) $decision->id,
                'order_id' => $orderId,
                'price' => $price,
                'qty' => $sellQty,
                'realized_pnl' => $lotRealized,
                'sold_at' => now()->toIso8601String(),
            ];

            $lot->update([
                'remaining_qty' => $remaining,
                'realized_pnl' => (float) $lot->realized_pnl + $lotRealized,
                'sell_fills' => $fills,
                'status' => $remaining <= 0.00000001 ? RatioBotLot::STATUS_CLOSED : RatioBotLot::STATUS_OPEN,
                'closed_at' => $remaining <= 0.00000001 ? now() : $lot->closed_at,
            ]);

            $remainingSellQty -= $sellQty;
            $realized += $lotRealized;
        }

        return $realized;
    }
}
