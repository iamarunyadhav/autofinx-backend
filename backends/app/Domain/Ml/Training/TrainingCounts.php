<?php

namespace App\Domain\Ml\Training;

use App\Domain\Ml\Shadow\FeatureLabelService;
use App\Domain\Ml\Shadow\MlCandleCollector;
use App\Domain\Ml\Shadow\MlHistoryWindow;
use App\Domain\Ml\Shadow\RetrainEligibility;
use App\Domain\Ml\Shadow\ShadowFeatureBuilder;
use App\Models\MlCandle;
use App\Models\MlCandleRejection;
use App\Models\MlFeatureRow;
use App\Models\MlLabel;
use Illuminate\Support\Facades\Cache;

/**
 * Every COUNT over the big ml tables (candles, feature rows, labels) and the
 * retrain eligibility joins, computed at most once per 5 minutes and shared by
 * all dashboard requests. One request recomputes under a lock while the rest
 * keep serving the previous copy (up to a day old), so a 10 s poll can never
 * pile up N concurrent heavy counts. `as_of` is shown to the user.
 */
class TrainingCounts
{
    public const FRESH_KEY = 'ml:training:counts:v1';

    public const STALE_KEY = 'ml:training:counts:stale:v1';

    public const LOCK_KEY = 'ml:training:counts:lock';

    public const TTL_SECONDS = 300;

    public function __construct(private readonly FeatureLabelService $labels, private readonly RetrainEligibility $eligibility) {}

    /**
     * @return array{as_of: string, symbols: array<string, array{collected:int, target:int, rejected:int, feature_rows:int, pending:int, mature:int, retrain: array<int, array{eligible:bool, reason:string, new_mature:int, required:int}>}>}
     */
    public function get(): array
    {
        $fresh = Cache::get(self::FRESH_KEY);
        if (is_array($fresh)) {
            return $fresh;
        }
        $lock = Cache::lock(self::LOCK_KEY, 120);
        if ($lock->get()) {
            try {
                $data = $this->compute();
                Cache::put(self::FRESH_KEY, $data, self::TTL_SECONDS);
                Cache::put(self::STALE_KEY, $data, 86400);

                return $data;
            } finally {
                $lock->release();
            }
        }
        $stale = Cache::get(self::STALE_KEY);

        return is_array($stale) ? $stale : $this->compute();
    }

    /** @return array<string, mixed> */
    public function compute(): array
    {
        $timeframe = (string) config('ml.base_timeframe', '5m');
        $provider = (string) config('ml.provider', 'binance_futures');
        $interval = MlCandleCollector::intervalMs($timeframe);
        $symbols = [];
        foreach ((array) config('ml.symbols', []) as $symbol) {
            $labelCounts = MlLabel::query()->where('symbol', $symbol)->where('timeframe', $timeframe)
                ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
            $retrain = [];
            foreach ($this->labels->horizons() as $horizon) {
                $check = $this->eligibility->check($symbol, $horizon);
                $retrain[$horizon] = ['eligible' => (bool) $check['eligible'], 'reason' => (string) $check['reason'], 'new_mature' => (int) $check['new_mature'], 'required' => (int) $check['required']];
            }
            $months = MlHistoryWindow::months($symbol);
            $symbols[$symbol] = [
                'collected' => MlCandle::query()->where('provider', $provider)->where('symbol', $symbol)->where('timeframe', $timeframe)->count(),
                'target' => (int) round($months * 30.4375 * 86_400_000 / $interval),
                'rejected' => (int) MlCandleRejection::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->count(),
                'feature_rows' => MlFeatureRow::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->where('feature_schema_version', ShadowFeatureBuilder::SCHEMA_VERSION)->count(),
                'pending' => (int) ($labelCounts['PENDING'] ?? 0),
                'mature' => (int) ($labelCounts['MATURE'] ?? 0),
                'retrain' => $retrain,
            ];
        }

        return ['as_of' => now('UTC')->toIso8601String(), 'symbols' => $symbols];
    }
}
