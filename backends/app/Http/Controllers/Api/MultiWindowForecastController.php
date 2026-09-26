<?php

namespace App\Http\Controllers\Api;

use App\Domain\Trading\MultiWindowForecastAccuracyReport;
use App\Domain\Trading\MultiWindowForecastAuditService;
use App\Domain\Trading\MultiWindowForecastComparisonService;
use App\Domain\Trading\TradingModuleSettingsService;
use App\Http\Controllers\Concerns\AssertsWorkspaceMembership;
use App\Http\Controllers\Controller;
use App\Models\MultiWindowForecastAudit;
use Illuminate\Http\Request;

/**
 * VNext Dynamic Decision Engine, Phase A -- read-only, informational-only
 * multi-window (short/medium/long) forecast comparison for Quick Trade.
 * Never places, blocks or alters a trade; see
 * App\Domain\Trading\MultiWindowForecastComparisonService for the compute
 * path and App\Domain\Trading\MultiWindowForecastAuditService for the
 * (optional, currently un-migrated) audit persistence.
 */
class MultiWindowForecastController extends Controller
{
    use AssertsWorkspaceMembership;

    /**
     * Computes all 3 predefined windows for one symbol via the existing
     * AsyncTradeForecast caching/locking pattern. The caller supplies the
     * candles for each window (already fetched from the existing
     * /quick-trade/chart endpoint at that window's own timeframe, exactly
     * like the single-window /quick-trade/forecast endpoint's own
     * "caller already has this from its own chart poll" contract) --
     * this endpoint never fetches market data itself.
     */
    public function compare(Request $request, TradingModuleSettingsService $settings, MultiWindowForecastComparisonService $comparison)
    {
        $maxExpiry = $settings->maxExpirySeconds();
        $labels = array_map(static fn (array $w) => (string) $w['label'], (array) config('multi_window_forecast.windows', []));

        $rules = [
            'symbol' => 'required|string|max:32',
            'exchange' => 'nullable|string|max:32',
            'stake_amount' => 'required|numeric|min:0.01',
            'mode' => 'nullable|string|in:paper,live',
            'workspace_id' => 'nullable|uuid',
            'integration_id' => 'nullable|uuid',
            'correlation_id' => 'nullable|uuid',
            'windows' => 'required|array',
        ];
        foreach ($labels as $label) {
            $rules["windows.{$label}"] = 'required|array';
            $rules["windows.{$label}.candles"] = 'required|array|min:10|max:500';
            $rules["windows.{$label}.candles.*.open"] = 'required|numeric';
            $rules["windows.{$label}.candles.*.high"] = 'required|numeric';
            $rules["windows.{$label}.candles.*.low"] = 'required|numeric';
            $rules["windows.{$label}.candles.*.close"] = 'required|numeric';
            $rules["windows.{$label}.candles.*.volume"] = 'nullable|numeric';
            $rules["windows.{$label}.candles.*.timestamp"] = 'nullable|numeric';
            $rules["windows.{$label}.candles.*.opens_at"] = 'nullable|string|max:64';
        }
        // Every configured window's expiry_seconds must clear the platform's
        // own derived cap -- same cap /quick-trade/forecast itself enforces,
        // so a comparison request is never accepted for a window the
        // platform would otherwise reject on its own.
        foreach ((array) config('multi_window_forecast.windows', []) as $window) {
            if ((int) ($window['expiry_seconds'] ?? 0) > $maxExpiry) {
                abort(500, 'multi_window_forecast.windows misconfigured: a window exceeds the platform max expiry.');
            }
        }

        $data = $request->validate($rules);

        // Naming a workspace can make the server price the decision off
        // that workspace's real account fee rate, so it must be the
        // caller's own.
        $this->assertOptionalWorkspaceMembership($request, $data['workspace_id'] ?? null);

        $candlesByLabel = [];
        foreach ($labels as $label) {
            $candlesByLabel[$label] = $data['windows'][$label]['candles'];
        }

        $base = [
            'symbol' => $data['symbol'],
            'exchange' => $data['exchange'] ?? null,
            'stake_amount' => $data['stake_amount'],
            'mode' => $data['mode'] ?? 'paper',
            'workspace_id' => $data['workspace_id'] ?? null,
            'integration_id' => $data['integration_id'] ?? null,
        ];

        $result = $comparison->compare(
            (string) $request->user()->getAuthIdentifier(),
            $base,
            $candlesByLabel,
            $data['correlation_id'] ?? null,
        );

        return response()->json($result);
    }

    /**
     * Phase A item 3 -- per-window (short/medium/long) accuracy/win-rate/
     * net-PnL breakdown, reported separately per window, never blended.
     * Returns INSUFFICIENT_DATA-style per-window status honestly (see
     * MultiWindowForecastAccuracyReport) rather than a fabricated number
     * when the audit table is empty/un-migrated.
     */
    public function accuracyReport(Request $request, MultiWindowForecastAuditService $audit, MultiWindowForecastAccuracyReport $report)
    {
        $data = $request->validate([
            'symbol' => 'nullable|string|max:32',
            'limit' => 'nullable|integer|min:1|max:5000',
        ]);

        if (! $audit->enabled()) {
            return response()->json([
                'schema_version' => 1,
                'status' => 'table_missing',
                'note' => 'multi_window_forecast_audits migration has not been applied yet.',
                'windows' => ['short' => null, 'medium' => null, 'long' => null],
            ]);
        }

        $rows = MultiWindowForecastAudit::query()
            ->when($data['symbol'] ?? null, fn ($q, $v) => $q->where('symbol', strtoupper((string) $v)))
            ->orderByDesc('created_at')
            ->limit((int) ($data['limit'] ?? 2000))
            ->get()
            ->map(fn (MultiWindowForecastAudit $row) => $row->toArray())
            ->all();

        return response()->json($report->generate($rows));
    }

    /**
     * Verbatim copy of QuickTradeController::assertOptionalWorkspaceMembership()
     * (private there too) -- membership is only required when the caller
     * actually names a workspace.
     */
    private function assertOptionalWorkspaceMembership(Request $request, mixed $workspaceId): void
    {
        if (is_string($workspaceId) && trim($workspaceId) !== '') {
            $this->assertWorkspaceMembership($request, $workspaceId);
        } elseif ($workspaceId !== null && $workspaceId !== '') {
            abort(403, 'You do not have access to this workspace.');
        }
    }
}
