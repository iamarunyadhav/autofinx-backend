<?php

namespace App\Domain\RatioBot;

use App\Models\Notification;
use App\Models\RatioBotConfig;
use App\Models\RatioBotEvent;
use App\Models\RatioBotState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Edge-triggered Ratio Bot alerts (spec §49/§78) into the platform's
 * existing in-app notification bell — mirrors
 * OpsRuntimeHealthAlertCommand's exact pattern: cache last-seen state per
 * bot, fire Notification::create() only on a genuine transition (never
 * every tick), reuse the same services.ops_alert.webhook_url channel.
 * Never blocks/throws into the evaluation loop — every public method is
 * fail-open.
 */
class RatioBotAlertService
{
    private const ALERT_EVENT_TYPES = [
        RatioBotEvent::EVENT_RECOVERY_MONITORING_ENTERED => ['title' => 'Recovery reserve eligibility window started', 'severity' => 'warning'],
        RatioBotEvent::EVENT_RECOVERY_TRADE_EXECUTED => ['title' => 'Recovery reserve executed', 'severity' => 'info'],
        'review_recommended' => ['title' => 'Strategy review recommended', 'severity' => 'warning'],
    ];

    public function checkAndNotify(RatioBotConfig $config): void
    {
        try {
            $this->notifyNewEvents($config);
            $this->notifyNoTradeThreshold($config);
        } catch (\Throwable $e) {
            Log::warning('[RatioBotAlert] check failed, continuing without alerting', [
                'config_id' => $config->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The bot stopped trading on its own (blocked, disabled, or waiting for
     * a human after an unresolved order). Called at the moment it happens,
     * not from checkAndNotify(): a stopped bot is no longer evaluated, so an
     * evaluation-driven check would never see it. One alert per bot and
     * reason per cooldown window, so a block that flaps is not a flood.
     */
    public function notifyStopped(RatioBotConfig $config, string $reasonCode, string $detail, bool $needsReview = false): void
    {
        try {
            $cooldown = max(60, (int) config('services.ops_alert.ratio_bot_stop_cooldown_seconds', 1800));
            if (! Cache::add("ratio-bot:alert:stopped:{$config->id}:{$reasonCode}", true, now()->addSeconds($cooldown))) {
                return;
            }

            $title = $needsReview ? 'Bot paused — needs your review' : 'Bot stopped trading';
            $body = sprintf("Reason: %s
%s
Mode: %s / %s", $reasonCode, $detail, (string) $config->execution_mode, (string) $config->mode);
            $this->fire($config, $title, $body, $needsReview ? 'critical' : 'warning');
        } catch (\Throwable $e) {
            Log::warning('[RatioBotAlert] stop alert failed, continuing', ['config_id' => $config->id, 'error' => $e->getMessage()]);
        }
    }

    private function notifyNewEvents(RatioBotConfig $config): void
    {
        $cacheKey = "ratio-bot:alert:last-event-at:{$config->id}";
        $cutoff = Cache::get($cacheKey);

        if ($cutoff === null) {
            // First observation ever — establish a baseline, don't alert on history.
            Cache::put($cacheKey, now()->toIso8601String(), now()->addDays(30));
            return;
        }

        $events = RatioBotEvent::query()
            ->where('ratio_bot_config_id', $config->id)
            ->whereIn('event_type', array_keys(self::ALERT_EVENT_TYPES))
            ->where('occurred_at', '>', $cutoff)
            ->orderBy('occurred_at')
            ->get();

        if ($events->isEmpty()) {
            return;
        }

        foreach ($events as $event) {
            $meta = self::ALERT_EVENT_TYPES[$event->event_type];
            $this->fire($config, $meta['title'], $this->describeEvent($event), $meta['severity']);
        }

        Cache::put($cacheKey, optional($events->last()->occurred_at)->toIso8601String() ?? now()->toIso8601String(), now()->addDays(30));
    }

    private function notifyNoTradeThreshold(RatioBotConfig $config): void
    {
        $minutes = (int) ($config->no_trade_alert_minutes ?? 0);
        if ($minutes <= 0) {
            return;
        }

        $state = $config->state;
        $lastActivity = $state?->last_trade_time ?? $state?->created_at;
        if (! $lastActivity) {
            return;
        }

        $overThreshold = $lastActivity->copy()->addMinutes($minutes)->isPast();
        $cacheKey = "ratio-bot:alert:no-trade-active:{$config->id}";
        $wasActive = (bool) Cache::get($cacheKey, false);

        if ($overThreshold && ! $wasActive) {
            Cache::put($cacheKey, true, now()->addDays(7));
            $this->fire($config, "No trade for {$minutes} minutes", $this->noTradeBody($state, $minutes), 'info');
            return;
        }

        if (! $overThreshold && $wasActive) {
            Cache::put($cacheKey, false, now()->addDays(7));
        }
    }

    private function noTradeBody(?RatioBotState $state, int $minutes): string
    {
        $recovery = 'not yet eligible';
        if ($state?->recovery_used_at) {
            $recovery = 'already used';
        } elseif ($state?->recovery_review_at) {
            $recovery = 'monitoring';
        }

        return sprintf(
            "Current state: %s\nReason: %s\nRecovery: %s",
            (string) ($state?->position_state ?? 'unknown'),
            (string) ($state?->block_reason_detail ?? 'market remains outside the profitable range'),
            $recovery,
        );
    }

    private function describeEvent(RatioBotEvent $event): string
    {
        return sprintf(
            '%s at %s',
            str_replace('_', ' ', $event->event_type),
            optional($event->occurred_at)->toIso8601String() ?? 'unknown time',
        );
    }

    private function fire(RatioBotConfig $config, string $title, string $body, string $severity): void
    {
        Notification::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $config->workspace_id,
            'type' => 'ratio_bot_alert',
            'content' => [
                'title' => "{$title} — {$config->normalizedSymbol()} ({$config->name})",
                'body' => $body,
                'severity' => $severity,
                'ratio_bot_config_id' => (string) $config->id,
            ],
        ]);

        $webhookUrl = (string) config('services.ops_alert.webhook_url', '');
        if ($webhookUrl === '') {
            return;
        }

        try {
            Http::timeout(5)->post($webhookUrl, ['text' => "{$title}\n{$body}"]);
        } catch (\Throwable $e) {
            Log::warning('[RatioBotAlert] webhook post failed', ['error' => $e->getMessage()]);
        }
    }
}
