<?php

namespace App\Domain\Fast2X;

use App\Domain\Forecast\ForecastService;
use App\Models\Fast2XConfig;
use App\Models\Signal;

class ExternalSignalFusionService
{
    public function __construct(
        private readonly ForecastService $forecast,
    ) {}

    public function evaluate(Fast2XConfig $config, array $confirmation, float $currentPrice): array
    {
        $settings = $config->mergedSettings();
        $enabled = (bool) ($settings['useExternalSignals'] ?? false);
        $required = (bool) ($settings['requireExternalSignalAgreement'] ?? false);
        $ttlSeconds = max(15, (int) ($settings['externalSignalTtlSeconds'] ?? 180));
        $minimumConfidence = (float) ($settings['externalSignalMinConfidence'] ?? 0.68);
        $minimumScore = (int) ($settings['externalSignalMinScore'] ?? 7);
        $minimumRr = (float) ($settings['externalSignalMinRR'] ?? 2.0);
        $requiredSource = trim((string) ($settings['externalSignalSource'] ?? ''));
        $forecastWeight = max(0.0, min(0.25, (float) ($settings['externalSignalForecastWeight'] ?? 0.10)));
        $minimumFusedConfidence = (float) ($settings['fusedEntryMinConfidence'] ?? 0.74);

        $latest = Signal::query()
            ->where('workspace_id', $config->workspace_id)
            ->where('symbol', $config->normalizedSymbol())
            ->where('created_at', '>=', now()->subSeconds($ttlSeconds))
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->first(function (Signal $signal) use ($requiredSource): bool {
                if ($requiredSource === '') {
                    return true;
                }

                return strtolower((string) data_get($signal->meta, 'source', '')) === strtolower($requiredSource);
            });

        if (! $enabled) {
            return [
                'enabled' => false,
                'required' => false,
                'allowed' => true,
                'confidence' => round((float) ($confirmation['confidence'] ?? 0), 4),
                'reasons' => ['External signal confirmation disabled'],
                'latestSignal' => null,
                'forecast' => null,
            ];
        }

        if (! $latest) {
            return [
                'enabled' => true,
                'required' => $required,
                'allowed' => ! $required,
                'confidence' => round((float) ($confirmation['confidence'] ?? 0), 4),
                'reasons' => ['No recent external signal available'],
                'latestSignal' => null,
                'forecast' => null,
            ];
        }

        $meta = (array) ($latest->meta ?? []);
        $action = strtoupper(trim((string) $latest->action));
        $score = (int) ($meta['score'] ?? $this->scoreFromConfidence($latest->confidence));
        $externalConfidence = $this->normalizeConfidence((float) $latest->confidence, $score);
        $rr = $this->firstFloat($meta, ['rr', 'risk_reward', 'riskReward']);
        $htfAligned = $this->firstBool($meta, ['htf_aligned', 'htfAligned', 'require_htf', 'requireHtf'], false);
        $newsRisk = strtoupper(trim((string) ($meta['news_risk'] ?? $meta['newsRisk'] ?? 'LOW')));
        $forecast = $this->forecast->predictNext($config->normalizedSymbol(), 5);
        $forecastBias = $this->forecastBias($forecast, $currentPrice);
        $forecastConfidence = $forecastBias === 'BULLISH' ? (float) ($forecast['confidence'] ?? 0) : 0.0;
        $localConfidence = (float) ($confirmation['confidence'] ?? 0);
        $fusedConfidence = round(
            min(
                0.99,
                ($localConfidence * 0.55)
                + ($externalConfidence * 0.35)
                + ($forecastConfidence * $forecastWeight)
            ),
            4
        );

        $checks = [
            'action' => in_array($action, ['BUY', 'LONG'], true),
            'confidence' => $externalConfidence >= $minimumConfidence,
            'score' => $score >= $minimumScore,
            'rr' => $rr === null || $rr >= $minimumRr,
            'htf' => ! (bool) ($settings['externalSignalRequireHtf'] ?? true) || $htfAligned,
            'news' => ! (bool) ($settings['externalSignalBlockHighNews'] ?? true) || $newsRisk !== 'HIGH',
            'fused' => $fusedConfidence >= $minimumFusedConfidence,
        ];

        $reasons = [
            $checks['action'] ? 'External signal is bullish' : "External signal action {$action} blocks BUY",
            $checks['confidence'] ? sprintf('External confidence %.2f is acceptable', $externalConfidence) : sprintf('External confidence %.2f below minimum', $externalConfidence),
            $checks['score'] ? "Signal score {$score}/10 is acceptable" : "Signal score {$score}/10 below minimum",
            $checks['rr'] ? sprintf('Risk reward %s is acceptable', $rr === null ? 'n/a' : number_format($rr, 2)) : sprintf('Risk reward %.2f below minimum', (float) $rr),
            $checks['htf'] ? 'Higher timeframe alignment confirmed' : 'Higher timeframe alignment missing',
            $checks['news'] ? "News risk {$newsRisk} is acceptable" : 'High-impact news risk blocks entry',
            $checks['fused'] ? sprintf('Fused confidence %.2f is acceptable', $fusedConfidence) : sprintf('Fused confidence %.2f below minimum', $fusedConfidence),
        ];

        return [
            'enabled' => true,
            'required' => $required,
            'allowed' => ! in_array(false, $checks, true),
            'confidence' => $fusedConfidence,
            'reasons' => $reasons,
            'checks' => $checks,
            'latestSignal' => [
                'id' => $latest->id,
                'symbol' => $latest->symbol,
                'action' => $action,
                'confidence' => round($externalConfidence, 4),
                'score' => $score,
                'rr' => $rr,
                'newsRisk' => $newsRisk,
                'htfAligned' => $htfAligned,
                'source' => (string) ($meta['source'] ?? 'external'),
                'strategyName' => $latest->strategy_name,
                'ageSeconds' => max(0, now()->diffInSeconds($latest->created_at)),
                'createdAt' => optional($latest->created_at)->toIso8601String(),
            ],
            'forecast' => [
                'price' => $forecast['price'] ?? null,
                'confidence' => round((float) ($forecast['confidence'] ?? 0), 4),
                'bias' => $forecastBias,
            ],
        ];
    }

    private function scoreFromConfidence(float $confidence): int
    {
        if ($confidence > 1) {
            return (int) max(0, min(10, round($confidence)));
        }

        return (int) max(0, min(10, round($confidence * 10)));
    }

    private function normalizeConfidence(float $confidence, int $score): float
    {
        if ($confidence > 1) {
            return round(max(0.0, min(0.99, $confidence / 10)), 4);
        }

        if ($confidence > 0) {
            return round(max(0.0, min(0.99, $confidence)), 4);
        }

        return round(max(0.0, min(0.99, $score / 10)), 4);
    }

    private function firstFloat(array $meta, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (isset($meta[$key]) && is_numeric($meta[$key])) {
                return (float) $meta[$key];
            }
        }

        return null;
    }

    private function firstBool(array $meta, array $keys, bool $default = false): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $meta)) {
                return filter_var($meta[$key], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
            }
        }

        return $default;
    }

    private function forecastBias(array $forecast, float $currentPrice): string
    {
        $predicted = (float) ($forecast['price'] ?? 0);
        if ($predicted <= 0 || $currentPrice <= 0) {
            return 'UNKNOWN';
        }

        if ($predicted > $currentPrice) {
            return 'BULLISH';
        }

        if ($predicted < $currentPrice) {
            return 'BEARISH';
        }

        return 'FLAT';
    }
}
