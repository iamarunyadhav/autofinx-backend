<?php

namespace App\Domain\Trading;

use Illuminate\Support\Str;
use Throwable;

/**
 * VNext Dynamic Decision Engine, Phase A item 1 -- multi-window (short/
 * medium/long) forecast comparison for Quick Trade.
 *
 * Computes each window through the EXISTING AsyncTradeForecast
 * caching/locking pattern (same Redis cache key + per-scope pending lock +
 * `predictions`-queue dispatch as the single-window /quick-trade/forecast
 * endpoint's own `async=true` path) -- never bypassed, never a second
 * caching mechanism. Each window keeps its own Direction/Trade Decision/
 * WAIT reason/cost breakdown; nothing here averages, fuses or otherwise
 * merges the 3 windows into one number.
 *
 * Read-only and observation-only: this class never places, blocks or
 * alters a trade, and does not touch QuickTradeService,
 * RobotSessionService or any Ratio Bot decision file.
 */
class MultiWindowForecastComparisonService
{
    public function __construct(
        private readonly AsyncTradeForecast $asyncForecast,
        private readonly MultiWindowForecastAuditService $audit,
    ) {
    }

    /**
     * @return array<int, array{label:string, timeframe:string, expiry_seconds:int}>
     */
    public function windows(): array
    {
        return array_map(
            static fn (array $w) => [
                'label' => (string) $w['label'],
                'timeframe' => (string) $w['timeframe'],
                'expiry_seconds' => (int) $w['expiry_seconds'],
            ],
            (array) config('multi_window_forecast.windows', []),
        );
    }

    /**
     * @param  string  $actor  the authenticated user id (AsyncTradeForecast's own cache/lock scope key)
     * @param  array<string,mixed>  $base  symbol/exchange/stake_amount/mode/workspace_id/integration_id/bot_type
     * @param  array<string, array<string,mixed>>  $candlesByLabel  window label => validated candle array
     * @return array<string,mixed>
     */
    public function compare(string $actor, array $base, array $candlesByLabel, ?string $correlationId = null): array
    {
        $correlationId = $correlationId && Str::isUuid($correlationId) ? $correlationId : (string) Str::uuid();
        $windows = [];

        foreach ($this->windows() as $window) {
            $label = $window['label'];
            $data = array_merge($base, [
                'timeframe' => $window['timeframe'],
                'expiry_seconds' => $window['expiry_seconds'],
                'candles' => $candlesByLabel[$label] ?? [],
            ]);

            $entry = [
                'window_label' => $label,
                'timeframe' => $window['timeframe'],
                'expiry_seconds' => $window['expiry_seconds'],
                'status' => 'unavailable',
            ];

            try {
                $response = $this->asyncForecast->request($actor, 'forecast', $data);
                $statusCode = $response->getStatusCode();
                $payload = json_decode((string) $response->getContent(), true) ?: [];

                if ($statusCode === 200 && is_array($payload['forecast'] ?? null)) {
                    $entry['status'] = 'ready';
                    $entry['forecast'] = $payload['forecast'];
                    $entry['net_edge'] = $payload['net_edge'] ?? null;
                    $entry['position_sizing'] = $payload['position_sizing'] ?? null;

                    $this->audit->capture($correlationId, $label, $data, $payload['forecast']);
                } else {
                    // 202 calculating (fresh scope, first poll) -- the
                    // frontend re-POSTs the same correlation_id shortly
                    // after, exactly like the existing single-window
                    // forecast's own polling contract.
                    $entry['status'] = 'calculating';
                    $entry['retry_after_ms'] = $payload['retry_after_ms'] ?? 1000;
                }
            } catch (Throwable $e) {
                $entry['status'] = 'unavailable';
                $entry['error'] = 'Forecast computation failed for this window.';
                report($e);
            }

            $windows[] = $entry;
        }

        return [
            'schema_version' => 1,
            'correlation_id' => $correlationId,
            'symbol' => strtoupper((string) ($base['symbol'] ?? '')),
            'exchange' => (string) ($base['exchange'] ?? 'binance'),
            'generated_at' => now()->toIso8601String(),
            'windows' => $windows,
        ];
    }
}
