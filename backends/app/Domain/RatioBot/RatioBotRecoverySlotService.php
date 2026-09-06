<?php

namespace App\Domain\RatioBot;

use App\Domain\MarketData\MarketSnapshotService;
use App\Models\RatioBotConfig;
use App\Models\RatioBotEvent;
use App\Models\RatioBotRecoverySlot;

class RatioBotRecoverySlotService
{
    public function __construct(
        private readonly MarketSnapshotService $snapshots,
    ) {
    }

    public function skim(RatioBotConfig $config, float $realized, string $fundedCurrency = 'quote', array $context = []): ?RatioBotRecoverySlot
    {
        if (! $config->recoverySlotEnabled() || $realized <= 0) {
            return null;
        }

        $slot = $this->slotFor($config);
        $cap = $this->targetCapQuoteEquiv($config);
        $slotValue = $this->quoteEquivalentTotal($config, $slot);

        if ($cap > 0 && $slotValue >= $cap) {
            RatioBotEvent::create([
                'ratio_bot_config_id' => $config->id,
                'event_type' => RatioBotEvent::EVENT_RECOVERY_SLOT_CAP_REACHED,
                'context' => [
                    'slot_total_quote_equiv' => round($slotValue, 8),
                    'target_cap_quote_equiv' => round($cap, 8),
                    'symbol' => $config->normalizedSymbol(),
                ] + $context,
                'occurred_at' => now(),
            ]);

            return $slot;
        }

        $amount = $realized * ($config->recoverySlotAllocationPct() / 100);
        if ($amount <= 0) {
            return $slot;
        }

        $isBaseFunding = strtolower($fundedCurrency) === 'base';
        $slot->update([
            'symbol' => $config->normalizedSymbol(),
            'workspace_id' => $config->workspace_id,
            'target_cap_quote_equiv' => $cap > 0 ? round($cap, 8) : null,
            'quote_balance' => round((float) $slot->quote_balance + ($isBaseFunding ? 0.0 : $amount), 8),
            'base_balance' => round((float) $slot->base_balance + ($isBaseFunding ? $amount : 0.0), 8),
            'last_topped_up_at' => now(),
        ]);

        RatioBotEvent::create([
            'ratio_bot_config_id' => $config->id,
            'event_type' => RatioBotEvent::EVENT_RECOVERY_SLOT_TOPPED_UP,
            'context' => [
                'skim_amount' => round($amount, 8),
                'skim_currency' => $isBaseFunding ? 'base' : 'quote',
                'quote_balance' => (float) $slot->quote_balance,
                'base_balance' => (float) $slot->base_balance,
                'slot_total_quote_equiv' => round($this->quoteEquivalentTotal($config, $slot), 8),
                'target_cap_quote_equiv' => $slot->target_cap_quote_equiv !== null ? (float) $slot->target_cap_quote_equiv : null,
                'symbol' => $config->normalizedSymbol(),
            ] + $context,
            'occurred_at' => now(),
        ]);

        return $slot->refresh();
    }

    public function payload(RatioBotConfig $config): array
    {
        $slot = $this->slotFor($config);
        $cap = $this->targetCapQuoteEquiv($config);
        $slot->update([
            'target_cap_quote_equiv' => $cap > 0 ? round($cap, 8) : null,
            'symbol' => $config->normalizedSymbol(),
            'workspace_id' => $config->workspace_id,
        ]);

        $recentEvents = RatioBotEvent::query()
            ->where('ratio_bot_config_id', $config->id)
            ->whereIn('event_type', [
                RatioBotEvent::EVENT_RECOVERY_SLOT_TOPPED_UP,
                RatioBotEvent::EVENT_RECOVERY_SLOT_CAP_REACHED,
                RatioBotEvent::EVENT_RECOVERY_SLOT_USED,
            ])
            ->orderByDesc('occurred_at')
            ->limit(20)
            ->get(['id', 'event_type', 'context', 'occurred_at']);

        return [
            'bot_id' => (string) $config->id,
            'bot_name' => (string) $config->name,
            'symbol' => $config->normalizedSymbol(),
            'quote_asset' => $config->quoteAsset(),
            'base_asset' => $config->baseAsset(),
            'enabled' => $config->recoverySlotEnabled(),
            'allocation_pct' => $config->recoverySlotAllocationPct(),
            'target_pct' => $config->recoverySlotTargetPct(),
            'quote_balance' => (float) $slot->quote_balance,
            'base_balance' => (float) $slot->base_balance,
            'quote_equivalent_total' => round($this->quoteEquivalentTotal($config, $slot), 8),
            'target_cap_quote_equiv' => $cap > 0 ? round($cap, 8) : null,
            'last_topped_up_at' => optional($slot->last_topped_up_at)->toIso8601String(),
            'last_used_at' => optional($slot->last_used_at)->toIso8601String(),
            'recent_events' => $recentEvents->map(fn (RatioBotEvent $event) => [
                'id' => (string) $event->id,
                'event_type' => $event->event_type,
                'context' => $event->context,
                'occurred_at' => optional($event->occurred_at)->toIso8601String(),
            ])->values()->all(),
        ];
    }

    public function slotFor(RatioBotConfig $config): RatioBotRecoverySlot
    {
        return RatioBotRecoverySlot::query()->firstOrCreate(
            ['ratio_bot_config_id' => $config->id],
            [
                'workspace_id' => $config->workspace_id,
                'symbol' => $config->normalizedSymbol(),
                'quote_balance' => 0,
                'base_balance' => 0,
                'target_cap_quote_equiv' => round($this->targetCapQuoteEquiv($config), 8),
            ],
        );
    }

    private function targetCapQuoteEquiv(RatioBotConfig $config): float
    {
        $baseQuoteAmount = max(0.0, (float) ($config->base_quote_amount ?? 0));

        return $baseQuoteAmount * ($config->recoverySlotTargetPct() / 100);
    }

    private function quoteEquivalentTotal(RatioBotConfig $config, RatioBotRecoverySlot $slot): float
    {
        $price = $this->latestPrice($config);
        $quote = (float) ($slot->quote_balance ?? 0);
        $base = (float) ($slot->base_balance ?? 0);

        return $quote + ($base * max(0.0, $price));
    }

    private function latestPrice(RatioBotConfig $config): float
    {
        $snapshot = $this->snapshots->forRatioBot($config);
        $price = (float) ($snapshot['price'] ?? 0);
        if ($price > 0) {
            return $price;
        }

        return max(0.0, (float) ($config->state?->last_seen_price ?? 0));
    }
}
