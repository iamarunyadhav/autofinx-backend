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
     * @param string|null $lotType filter to RatioBotLot::TYPE_NORMAL/TYPE_RECOVERY; null returns both.
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
            'lot_type' => in_array($lotType, [RatioBotLot::TYPE_NORMAL, RatioBotLot::TYPE_RECOVERY], true) ? $lotType : RatioBotLot::TYPE_NORMAL,
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
            'lot_type' => in_array($lotType, [RatioBotLot::TYPE_NORMAL, RatioBotLot::TYPE_RECOVERY], true) ? $lotType : RatioBotLot::TYPE_NORMAL,
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

        foreach ($openLots as $lot) {
            $targetQty += (float) $lot->remaining_qty * max(0.0, min(100.0, $targetAllocationPct)) / 100;
        }

        foreach ($openLots as $lot) {
            if ($targetQty > 0 && $selectedQty >= $targetQty) {
                break;
            }

            $buyPrice = (float) $lot->buy_price;
            if ($buyPrice <= 0 || $currentPrice < ($buyPrice * (1 + $minNetProfitRatio))) {
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
