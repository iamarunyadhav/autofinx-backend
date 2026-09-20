<?php

namespace App\Domain\Ops;

use App\Models\FeatureFlag;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Generic, reusable on/off switch store for AI/automation capabilities that
 * today are gated only by static .env/config() values (Unified Forecast
 * Engine shadow/live modes, per-bot-type scheduled backtest/calibration
 * jobs). Backed by the `feature_flags` table; falls back to `default_value`
 * on that row, then to the caller-supplied hardcoded default, if no row
 * exists at all -- so this class is always safe to introduce ahead of the
 * table being seeded.
 *
 * Cached for a short TTL since `enabled()` can be called on hot paths
 * (e.g. QuickTradeController's per-request forecast shadow check) -- a
 * human's toggle in the admin UI takes effect within that TTL, never
 * instantly, by design (avoids a DB hit per request).
 */
class FeatureFlagService
{
    /**
     * Static, code-owned registry of every real flag this app currently
     * knows about, keyed by flag key. This is the single source of truth
     * the admin UI list and `feature-flags:sync` seed from -- adding a new
     * togglable capability means adding one entry here, nothing else.
     *
     * `default_value` mirrors today's real config()/.env default at each
     * flag's underlying call site so introducing a DB row never changes
     * behavior on its own.
     */
    public const REGISTRY = [
        'unified_forecast.quick_trade_shadow' => [
            'category' => 'unified_forecast',
            'label' => 'Quick Trade — Unified Forecast Shadow Mode',
            'description' => 'Logs a side-by-side comparison between the Unified Forecast Engine and Quick Trade\'s existing forecast via AuditEventRecorder. Has zero effect on the trade output returned to the caller -- read-only logging only. Low risk.',
            'default_value' => true,
        ],
        'unified_forecast.quick_trade_live' => [
            'category' => 'unified_forecast',
            'label' => 'Quick Trade — Unified Forecast Live',
            'description' => 'When enabled, Quick Trade\'s live decision (BUY/SELL/WAIT + confidence) is taken from the Unified Forecast Engine instead of the internal forecast logic — but ONLY for a symbol/timeframe/horizon scope whose ForecastModelPromotionGate status is VALIDATED by real holdout evidence; otherwise it silently falls back to the internal logic, so flipping this on alone does not put unvalidated automation in charge of real trades. Data-quality gates (stale feed, low volume) still apply regardless.',
            'default_value' => false,
        ],
        'unified_forecast.robot_trade_shadow' => [
            'category' => 'unified_forecast',
            'label' => 'Robot Trade — Unified Forecast Shadow Mode',
            'description' => 'Logs a side-by-side comparison between the Unified Forecast Engine and Robot Trade\'s existing signal preview via AuditEventRecorder. Has zero effect on which Quick Trade a Robot Trade session actually opens -- read-only logging only. Low risk.',
            'default_value' => true,
        ],
        'unified_forecast.robot_trade_live' => [
            'category' => 'unified_forecast',
            'label' => 'Robot Trade — Unified Forecast Live',
            'description' => 'When enabled, Robot Trade\'s session decision is taken from the Unified Forecast Engine instead of the internal signal — but ONLY for a session whose ForecastModelPromotionGate status is VALIDATED by real holdout evidence; otherwise it silently falls back to the internal signal, so flipping this on alone does not put unvalidated automation in charge of real trades. Existing volatility/stability gates still apply regardless.',
            'default_value' => false,
        ],
        'scheduler.ratio_bot_forecast_backtest' => [
            'category' => 'scheduler',
            'label' => 'Ratio Bot — Scheduled Forecast Backtest',
            'description' => 'Once-daily (03:15) walk-forward forecast-accuracy backtest for Ratio Bot. Read-only analysis job -- not part of the live per-minute ratio-bots:evaluate/reconcile trading loop. Disabling only stops calibration data from refreshing.',
            'default_value' => true,
        ],
        'scheduler.quick_trade_forecast_backtest' => [
            'category' => 'scheduler',
            'label' => 'Quick Trade — Scheduled Forecast Backtest',
            'description' => 'Once-daily (03:45) periodic calibration refresh for Quick Trade\'s Unified Forecast shadow comparison. Read-only analysis job -- never called from any live Quick Trade settlement path.',
            'default_value' => true,
        ],
        'scheduler.robot_trade_forecast_backtest' => [
            'category' => 'scheduler',
            'label' => 'Robot Trade — Scheduled Forecast Backtest',
            'description' => 'Once-daily (04:15) periodic calibration refresh for Robot Trade\'s Unified Forecast shadow comparison. Read-only analysis job -- never called from RobotSessionService::evaluateSession() or any other live session decision path.',
            'default_value' => true,
        ],
        'scheduler.future_trade_forecast_backtest' => [
            'category' => 'scheduler',
            'label' => 'Future Trade — Scheduled Forecast Backtest',
            'description' => 'Once-daily (04:45) periodic calibration refresh for Future Trade. Read-only analysis job -- never called from FutureTradeBotService::scan() or any other live scan/entry/exit decision path.',
            'default_value' => true,
        ],
        'scheduler.fast2x_calibration_backtest' => [
            'category' => 'scheduler',
            'label' => 'Fast2X — Scheduled Calibration Backtest',
            'description' => 'Once-daily (05:15) native calibration refresh for Fast2X, driven by real fast2x_decision_logs/fast2x_trades outcomes. Read-only analysis job -- never called from Fast2XService\'s live tick-loop path.',
            'default_value' => true,
        ],
        'scheduler.fast2x_supervisor' => [
            'category' => 'scheduler',
            'label' => 'Fast2X — Daemon Supervision (restart-on-crash)',
            'description' => 'Every-minute check (fast2x:supervise) that restarts a workspace\'s fast2x:run process if its heartbeat has gone stale (crashed, hung, or never started after the daemon was last enabled). Fast2X has no static Supervisor entry because it is one process per workspace, not a single global daemon like Ratio Bot/Future Trade -- this is the substitute self-healing mechanism. Disabling it means a crashed Fast2X daemon stays down until someone restarts it manually.',
            'default_value' => true,
        ],
        'scheduler.forecast_readiness_evaluation' => [
            'category' => 'scheduler',
            'label' => 'Forecast Readiness Evaluation Sweep',
            'description' => 'Once-daily (05:45) sweep across all four bot types that re-evaluates production-readiness status from freshly-refreshed calibration data. Read-only reporting job -- only persists forecast_readiness_snapshots rows and logs status changes; never called from a live trading/execution path.',
            'default_value' => true,
        ],
    ];

    protected const CACHE_TTL_SECONDS = 60;

    /**
     * Whether a given flag is enabled. Resolution order: DB row `enabled`
     * value -> DB row `default_value` (if a row exists but was somehow left
     * without an explicit `enabled`, which shouldn't normally happen since
     * we always persist both) -> the caller-supplied hardcoded default.
     */
    public function enabled(string $key, bool $default = false): bool
    {
        $cacheKey = 'feature_flags:v1:' . $key;

        // routes/console.php reads several flags at boot time on EVERY
        // artisan invocation, including `migrate` itself before the
        // `feature_flags` table exists yet -- swallow any DB/schema error
        // here (missing table, no DB connection during early setup) and
        // fall back to the registry/caller default rather than breaking
        // every artisan command.
        try {
            $resolved = Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($key) {
                if (! Schema::hasTable('feature_flags')) {
                    return null;
                }

                $row = FeatureFlag::query()->where('key', $key)->first();

                return $row?->enabled;
            });
        } catch (Throwable $e) {
            $resolved = null;
        }

        if ($resolved !== null) {
            return (bool) $resolved;
        }

        return self::REGISTRY[$key]['default_value'] ?? $default;
    }

    /**
     * Update (or create) a flag's enabled state and bust its cache entry
     * immediately so the caller doesn't have to wait out the TTL to see
     * their own write reflected.
     */
    public function set(string $key, bool $enabled, ?string $updatedBy = null): FeatureFlag
    {
        $meta = self::REGISTRY[$key] ?? null;

        $row = FeatureFlag::query()->updateOrCreate(
            ['key' => $key],
            [
                'id' => FeatureFlag::query()->where('key', $key)->value('id') ?? (string) Str::uuid(),
                'enabled' => $enabled,
                'category' => $meta['category'] ?? 'uncategorized',
                'label' => $meta['label'] ?? $key,
                'description' => $meta['description'] ?? null,
                'default_value' => $meta['default_value'] ?? false,
                'updated_by' => $updatedBy,
            ]
        );

        Cache::forget('feature_flags:v1:' . $key);

        return $row;
    }

    /**
     * Ensure every REGISTRY key has a row in `feature_flags`, seeding
     * `default_value`/`enabled` to the current real config default so
     * introducing this table never changes live behavior. Used by
     * `feature-flags:sync` and safe to run repeatedly (idempotent --
     * existing rows are left untouched aside from label/description/
     * category/default_value metadata refresh).
     */
    public function sync(): int
    {
        $count = 0;

        foreach (self::REGISTRY as $key => $meta) {
            $existing = FeatureFlag::query()->where('key', $key)->first();

            if ($existing) {
                $existing->category = $meta['category'];
                $existing->label = $meta['label'];
                $existing->description = $meta['description'];
                $existing->default_value = $meta['default_value'];
                $existing->save();
                continue;
            }

            FeatureFlag::query()->create([
                'id' => (string) Str::uuid(),
                'key' => $key,
                'enabled' => $meta['default_value'],
                'category' => $meta['category'],
                'label' => $meta['label'],
                'description' => $meta['description'],
                'default_value' => $meta['default_value'],
            ]);

            $count++;
        }

        return $count;
    }

    public function all(): array
    {
        $rows = FeatureFlag::query()->get()->keyBy('key');

        return collect(self::REGISTRY)->map(function (array $meta, string $key) use ($rows) {
            $row = $rows->get($key);

            return [
                'key' => $key,
                'enabled' => $row ? (bool) $row->enabled : (bool) $meta['default_value'],
                'label' => $meta['label'],
                'description' => $meta['description'],
                'category' => $meta['category'],
                'default_value' => (bool) $meta['default_value'],
                'updated_at' => $row?->updated_at,
                'updated_by' => $row?->updated_by,
            ];
        })->values()->all();
    }
}
