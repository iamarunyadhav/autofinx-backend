<?php

namespace App\Domain\Portfolio;

use App\Models\PortfolioIntelligenceConfig;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

class OpportunityScoringService
{
    private const DEFAULT_WEIGHTS = [
        'setup_quality' => 0.25,
        'signal_confidence' => 0.15,
        'market_regime' => 0.10,
        'external_context' => 0.15,
        'correlation' => 0.15,
        'portfolio_fit' => 0.10,
        'liquidity' => 0.05,
        'execution_quality' => 0.05,
        'operator_preference' => 0.05,
    ];

    public function score(array $symbolContext, array $portfolioContext, mixed $config = []): array
    {
        $config = $this->normalizeConfig($config);
        $symbol = strtoupper((string) data_get($symbolContext, 'symbol', ''));
        $threshold = (float) ($config['opportunity_score_threshold'] ?? 65);
        $hardBlockers = $this->hardBlockers($symbolContext, $portfolioContext, $config);

        $trend = $this->trendScore($symbolContext);
        $signal = $this->signalScore($symbolContext, $trend);
        $marketRegime = $this->marketRegimeScore($symbolContext);
        $external = $this->externalContextScore($symbolContext, $portfolioContext, $config);
        $correlation = $this->correlationScore($symbolContext);
        $portfolioFit = $this->portfolioFitScore($symbolContext, $portfolioContext, $trend['direction']);
        $liquidity = $this->liquidityScore($symbolContext);
        $executionQuality = $this->executionQualityScore($symbolContext);
        $operatorPreference = $this->operatorPreferenceScore($symbolContext, $portfolioContext);

        $weighted = $this->weightedScore([
            'setup_quality' => $trend['score'],
            'signal_confidence' => $signal['score'],
            'market_regime' => $marketRegime['score'],
            'external_context' => $external['score'],
            'correlation' => $correlation['score'],
            'portfolio_fit' => $portfolioFit['score'],
            'liquidity' => $liquidity['score'],
            'execution_quality' => $executionQuality['score'],
            'operator_preference' => $operatorPreference['score'],
        ], $config);

        if ($hardBlockers !== []) {
            $weighted = 0.0;
        }

        $strengths = $this->strengths([
            'setup_quality' => $trend,
            'signal_confidence' => $signal,
            'market_regime' => $marketRegime,
            'external_context' => $external,
            'correlation' => $correlation,
            'portfolio_fit' => $portfolioFit,
            'liquidity' => $liquidity,
            'execution_quality' => $executionQuality,
            'operator_preference' => $operatorPreference,
        ]);

        $cautions = $this->cautions([
            'setup_quality' => $trend,
            'signal_confidence' => $signal,
            'market_regime' => $marketRegime,
            'external_context' => $external,
            'correlation' => $correlation,
            'portfolio_fit' => $portfolioFit,
            'liquidity' => $liquidity,
            'execution_quality' => $executionQuality,
            'operator_preference' => $operatorPreference,
        ], $hardBlockers);

        $recommended = $this->recommendedAction($symbolContext, $portfolioContext, $trend['direction'], $weighted, $threshold, $hardBlockers, $config, $external);
        $symbolExposure = (array) data_get($symbolContext, 'correlation', []);
        $position = (array) data_get($symbolContext, 'position', []);
        $openOrders = (array) data_get($symbolContext, 'orders', []);
        $baseCap = (float) ($config['max_symbol_allocated_pct'] ?? 8);
        $sizeCap = $this->suggestedSizeCap($weighted, $baseCap, (float) data_get($symbolExposure, 'concentration_score', 60), $external['score'], $recommended['action'], ! empty($position));

        return [
            'symbol' => $symbol,
            'score' => round(max(0.0, min(100.0, $weighted)), 4),
            'confidence' => round(max(0.01, min(0.99, $weighted / 100)), 4),
            'trend_direction' => $trend['direction'],
            'recommended_action' => $recommended['action'],
            'requires_approval' => $recommended['requires_approval'],
            'auto_allowed' => $recommended['auto_allowed'],
            'blocked' => $hardBlockers !== [],
            'blockers' => $hardBlockers,
            'strengths' => $strengths,
            'cautions' => $cautions,
            'score_breakdown' => [
                $this->factor('setup_quality', $trend['score'], $trend['note'], $trend['details'], $config),
                $this->factor('signal_confidence', $signal['score'], $signal['note'], $signal['details'], $config),
                $this->factor('market_regime', $marketRegime['score'], $marketRegime['note'], $marketRegime['details'], $config),
                $this->factor('external_context', $external['score'], $external['note'], $external['details'], $config),
                $this->factor('correlation', $correlation['score'], $correlation['note'], $correlation['details'], $config),
                $this->factor('portfolio_fit', $portfolioFit['score'], $portfolioFit['note'], $portfolioFit['details'], $config),
                $this->factor('liquidity', $liquidity['score'], $liquidity['note'], $liquidity['details'], $config),
                $this->factor('execution_quality', $executionQuality['score'], $executionQuality['note'], $executionQuality['details'], $config),
                $this->factor('operator_preference', $operatorPreference['score'], $operatorPreference['note'], $operatorPreference['details'], $config),
            ],
            'suggested_size_cap_pct' => round($sizeCap, 4),
            'threshold' => round($threshold, 4),
            'portfolio_impact' => [
                'equity_estimate' => (float) data_get($portfolioContext, 'portfolio.capital.equity_estimate', 0),
                'allocated_capital_pct' => (float) data_get($portfolioContext, 'portfolio.capital.equity_estimate', 0) > 0
                    ? round(((float) data_get($portfolioContext, 'portfolio.capital.allocated_capital', 0) / (float) data_get($portfolioContext, 'portfolio.capital.equity_estimate', 1)) * 100, 4)
                    : null,
                'symbol_exposure_pct' => data_get($symbolExposure, 'symbol_exposure_pct'),
                'group_exposure_pct' => data_get($symbolExposure, 'group_exposure_pct'),
                'group_key' => data_get($symbolExposure, 'group_key'),
                'group_label' => data_get($symbolExposure, 'group_label'),
                'open_position' => ! empty($position),
                'open_orders_count' => count($openOrders),
            ],
            'market_price' => (float) data_get($symbolContext, 'price.latest', 0),
            'exchange_metadata' => (array) data_get($symbolContext, 'exchange_metadata', []),
            'correlation' => (array) data_get($symbolContext, 'correlation', []),
            'metadata' => [
                'latest_signal_at' => data_get($symbolContext, 'signal_hints.latest.decision_ts'),
                'external_freshness' => data_get($symbolContext, 'external_context.freshness_state', 'unavailable'),
                'has_price' => (bool) data_get($symbolContext, 'data_quality.has_price', false),
                'has_candles' => (bool) data_get($symbolContext, 'data_quality.has_candles', false),
            ],
        ];
    }

    public function scoreBatch(array $symbolContexts, array $portfolioContext, mixed $config = []): array
    {
        $results = [];
        foreach ($symbolContexts as $symbolContext) {
            $results[] = $this->score((array) $symbolContext, $portfolioContext, $config);
        }

        return $results;
    }

    /**
     * @param array<int, array<string, mixed>> $symbolScores
     */
    public function weightedScore(array $symbolScores, array $config): float
    {
        $weights = $this->weights($config);
        $score = 0.0;

        foreach ($weights as $factor => $weight) {
            $factorScore = (float) ($symbolScores[$factor] ?? 0);
            $score += $factorScore * $weight;
        }

        return max(0.0, min(100.0, $score));
    }

    private function normalizeConfig(mixed $config): array
    {
        if ($config instanceof PortfolioIntelligenceConfig) {
            return $config->toArray();
        }

        return is_array($config) ? $config : [];
    }

    private function hardBlockers(array $symbolContext, array $portfolioContext, array $config): array
    {
        $blockers = [];
        $symbol = strtoupper((string) data_get($symbolContext, 'symbol', ''));
        $risk = (array) data_get($portfolioContext, 'portfolio.risk', []);
        $aiConfig = (array) data_get($portfolioContext, 'ai_trading_config', []);
        $external = (array) data_get($symbolContext, 'external_context', []);
        $dataQuality = (array) data_get($symbolContext, 'data_quality', []);
        $allowedSymbols = $this->normalizeSymbols(data_get($aiConfig, 'allowed_symbols', []));
        $enabledSymbols = $this->normalizeSymbols(data_get($aiConfig, 'enabled_symbols', []));
        $blockedSymbols = $this->normalizeSymbols(data_get($aiConfig, 'blocked_symbols', []));
        $useExternalContext = (bool) data_get($aiConfig, 'use_external_context', false);

        if ($symbol === '') {
            $blockers[] = 'symbol_missing';
        }

        if ($blockedSymbols !== [] && in_array($symbol, $blockedSymbols, true)) {
            $blockers[] = 'symbol_blocked';
        }

        if ($allowedSymbols !== [] && ! in_array($symbol, $allowedSymbols, true)) {
            $blockers[] = 'symbol_not_allowlisted';
        }

        if ($enabledSymbols !== [] && ! in_array($symbol, $enabledSymbols, true)) {
            $blockers[] = 'symbol_disabled';
        }

        if ((bool) data_get($risk, 'kill_switch.active', false)) {
            $blockers[] = 'kill_switch_active';
        }

        if ((bool) data_get($risk, 'provider_breaker.active', false)) {
            $blockers[] = 'exchange_provider_breaker_active';
        }

        if ((bool) data_get($risk, 'stale_feed_breaker.active', false)) {
            $blockers[] = 'stale_feed_active';
        }

        if ((float) data_get($symbolContext, 'price.latest', 0) <= 0) {
            $blockers[] = 'missing_live_price';
        }

        if ((int) data_get($symbolContext, 'candles.count', 0) < 5) {
            $blockers[] = 'insufficient_candle_history';
        }

        if (($dataQuality['blockers'] ?? []) !== []) {
            foreach ((array) $dataQuality['blockers'] as $blocker) {
                $blockers[] = (string) $blocker;
            }
        }

        if ($useExternalContext) {
            if (! (bool) data_get($external, 'available', false)) {
                $blockers[] = 'external_context_unavailable';
            }

            if (in_array((string) data_get($external, 'freshness_state', 'unavailable'), ['stale', 'unavailable'], true)) {
                $blockers[] = 'external_context_stale';
            }
        }

        $highSeverity = array_filter((array) data_get($external, 'high_severity_events', []), static function ($event): bool {
            $severity = strtoupper((string) data_get($event, 'severity', ''));

            return in_array($severity, ['HIGH', 'CRITICAL'], true);
        });

        if ($highSeverity !== [] || (bool) data_get($external, 'pause_review_hint', false) || (bool) data_get($external, 'no_trade_hint', false)) {
            $blockers[] = 'external_context_blocker';
        }

        return array_values(array_unique($blockers));
    }

    private function trendScore(array $symbolContext): array
    {
        $indicators = (array) data_get($symbolContext, 'indicators', []);
        $price = (float) data_get($symbolContext, 'price.latest', 0);
        $sma9 = (float) data_get($indicators, 'sma_9', 0);
        $sma21 = (float) data_get($indicators, 'sma_21', 0);
        $ema12 = (float) data_get($indicators, 'ema_12', 0);
        $ema26 = (float) data_get($indicators, 'ema_26', 0);
        $rsi = (float) data_get($indicators, 'rsi_14', 0);
        $macd = (array) data_get($indicators, 'macd', []);
        $histogram = (float) data_get($macd, 'histogram', 0);

        $bullish = 0.0;
        $bearish = 0.0;
        $details = [];

        if ($price > 0 && $sma21 > 0) {
            $bullish += $price > $sma21 ? 20 : 0;
            $bearish += $price < $sma21 ? 20 : 0;
            $details[] = sprintf('Price %s SMA21.', $price > $sma21 ? 'above' : 'below');
        }

        if ($sma9 > 0 && $sma21 > 0) {
            $bullish += $sma9 > $sma21 ? 20 : 0;
            $bearish += $sma9 < $sma21 ? 20 : 0;
            $details[] = sprintf('SMA9 %s SMA21.', $sma9 > $sma21 ? 'above' : 'below');
        }

        if ($ema12 > 0 && $ema26 > 0) {
            $bullish += $ema12 > $ema26 ? 20 : 0;
            $bearish += $ema12 < $ema26 ? 20 : 0;
            $details[] = sprintf('EMA12 %s EMA26.', $ema12 > $ema26 ? 'above' : 'below');
        }

        if ($histogram !== 0.0) {
            $bullish += $histogram > 0 ? 20 : 0;
            $bearish += $histogram < 0 ? 20 : 0;
            $details[] = sprintf('MACD histogram is %s.', $histogram > 0 ? 'positive' : 'negative');
        }

        if ($rsi > 0) {
            $bullish += $rsi >= 45 && $rsi <= 68 ? 20 : 0;
            $bearish += $rsi <= 55 && $rsi >= 32 ? 20 : 0;
            $details[] = sprintf('RSI sits at %.2f.', $rsi);
        }

        $bullish = max(0.0, min(100.0, $bullish));
        $bearish = max(0.0, min(100.0, $bearish));
        $direction = 'neutral';
        $score = 50.0;

        if ($bullish > $bearish) {
            $direction = 'buy';
            $score = $bullish;
        } elseif ($bearish > $bullish) {
            $direction = 'sell';
            $score = $bearish;
        } elseif ($bullish === 0.0 && $bearish === 0.0) {
            $score = 45.0;
        }

        return [
            'score' => $score,
            'direction' => $direction,
            'note' => $direction === 'buy'
                ? 'Bullish trend and momentum are aligned.'
                : ($direction === 'sell' ? 'Bearish trend and momentum are aligned.' : 'Trend is mixed or undecided.'),
            'details' => array_values(array_filter($details)),
        ];
    }

    private function signalScore(array $symbolContext, array $trend): array
    {
        $signal = (array) data_get($symbolContext, 'signal_hints.latest', []);
        if ($signal === []) {
            return [
                'score' => 55.0,
                'note' => 'No recent trade signal is available; the score is neutral.',
                'details' => [],
            ];
        }

        $signalSide = strtolower((string) data_get($signal, 'side', ''));
        $confidence = data_get($signal, 'meta_json.confidence');
        $ageMinutes = null;
        if ($decisionTs = data_get($signal, 'decision_ts')) {
            $ageMinutes = Carbon::parse((string) $decisionTs)->diffInMinutes(now());
        }

        $score = 70.0;
        $details = [];

        if (is_numeric($confidence)) {
            $score = max($score, min(100.0, ((float) $confidence) * 100));
            $details[] = 'Signal carries an explicit confidence hint.';
        }

        if ($trend['direction'] !== 'neutral' && $signalSide !== '' && $signalSide !== $trend['direction']) {
            $score -= 25;
            $details[] = 'Signal direction conflicts with current trend alignment.';
        } elseif ($trend['direction'] !== 'neutral' && $signalSide === $trend['direction']) {
            $score += 15;
            $details[] = 'Signal direction confirms the trend.';
        }

        if ($ageMinutes !== null) {
            if ($ageMinutes > 720) {
                $score -= 20;
                $details[] = 'Signal is stale.';
            } elseif ($ageMinutes <= 60) {
                $score += 10;
                $details[] = 'Signal is fresh.';
            }
        }

        return [
            'score' => max(0.0, min(100.0, $score)),
            'note' => $signalSide !== ''
                ? sprintf('Latest signal direction is %s.', strtoupper($signalSide))
                : 'Latest signal exists but has no direction.',
            'details' => array_values(array_filter($details)),
        ];
    }

    private function marketRegimeScore(array $symbolContext): array
    {
        $indicators = (array) data_get($symbolContext, 'indicators', []);
        $volatility = (float) data_get($indicators, 'volatility_pct', 0);
        $candleCount = (int) data_get($indicators, 'candle_count', 0);

        if ($candleCount < 5) {
            return [
                'score' => 35.0,
                'note' => 'Candle history is too shallow to judge regime confidently.',
                'details' => [],
            ];
        }

        if ($volatility <= 0.5) {
            return [
                'score' => 55.0,
                'note' => 'Volatility is quiet; follow-through may be limited.',
                'details' => [],
            ];
        }

        if ($volatility >= 15) {
            return [
                'score' => 35.0,
                'note' => 'Volatility is elevated enough to reduce regime suitability.',
                'details' => [],
            ];
        }

        $distance = abs($volatility - 4.0);
        $score = max(45.0, 90.0 - ($distance * 8.0));

        return [
            'score' => max(0.0, min(100.0, $score)),
            'note' => 'Volatility sits in a tradable regime band.',
            'details' => [
                sprintf('Volatility is %.2f%%.', $volatility),
            ],
        ];
    }

    private function externalContextScore(array $symbolContext, array $portfolioContext, array $config): array
    {
        $external = (array) data_get($symbolContext, 'external_context', []);
        $useExternal = (bool) data_get($portfolioContext, 'ai_trading_config.use_external_context', false);
        $freshness = (string) data_get($external, 'freshness_state', 'unavailable');
        $severity = $this->maxSeverity((array) data_get($external, 'high_severity_events', []));
        $bias = strtolower((string) data_get($external, 'recommended_bias', 'neutral'));
        $score = 55.0;
        $details = [];

        if (! (bool) data_get($external, 'available', false)) {
            $score = $useExternal ? 25.0 : 55.0;
            $details[] = 'External context is unavailable.';
        } elseif ($freshness === 'stale') {
            $score = $useExternal ? 35.0 : 50.0;
            $details[] = 'External context is stale.';
        } else {
            $score = 75.0;
            $details[] = 'External context is fresh.';
        }

        if ($severity === 'CRITICAL') {
            $score = 0.0;
            $details[] = 'Critical external risk is active.';
        } elseif ($severity === 'HIGH') {
            $score -= 35;
            $details[] = 'High severity external risk is active.';
        } elseif ($severity === 'MEDIUM') {
            $score -= 15;
            $details[] = 'Moderate external risk is active.';
        }

        if ((bool) data_get($external, 'pause_review_hint', false)) {
            $score = min($score, 20.0);
            $details[] = 'External context recommends pause or review.';
        }

        if ((bool) data_get($external, 'no_trade_hint', false)) {
            $score = min($score, 10.0);
            $details[] = 'External context recommends no trade.';
        }

        if ($bias === 'bullish') {
            $score += 5;
        } elseif ($bias === 'bearish') {
            $score -= 5;
        }

        return [
            'score' => max(0.0, min(100.0, $score)),
            'note' => $useExternal
                ? 'External context is part of the operator policy surface.'
                : 'External context is advisory only.',
            'details' => array_values(array_filter($details)),
        ];
    }

    private function correlationScore(array $symbolContext): array
    {
        $correlation = (array) data_get($symbolContext, 'correlation', []);
        $score = (float) data_get($correlation, 'concentration_score', 60);
        $details = [];

        if ($score >= 80) {
            $details[] = 'Concentration is low.';
        } elseif ($score >= 60) {
            $details[] = 'Concentration is moderate.';
        } elseif ($score >= 40) {
            $details[] = 'Concentration is elevated.';
        } else {
            $details[] = 'Concentration is high.';
        }

        return [
            'score' => max(0.0, min(100.0, $score)),
            'note' => (string) data_get($correlation, 'concentration_note', 'Concentration heuristic applied.'),
            'details' => $details,
        ];
    }

    private function portfolioFitScore(array $symbolContext, array $portfolioContext, string $direction): array
    {
        $position = (array) data_get($symbolContext, 'position', []);
        $orders = (array) data_get($symbolContext, 'orders', []);
        $hasPosition = ! empty($position);
        $score = 75.0;
        $details = [];

        if ($hasPosition && $direction === 'buy') {
            $score = 55.0;
            $details[] = 'Symbol is already held; adding more increases concentration.';
        } elseif ($hasPosition && $direction === 'sell') {
            $score = 90.0;
            $details[] = 'Direction supports risk reduction or profit taking.';
        } elseif (! $hasPosition && $direction === 'buy') {
            $score = 90.0;
            $details[] = 'Symbol is available for a fresh entry.';
        } elseif ($direction === 'neutral') {
            $score = 60.0;
            $details[] = 'Directional edge is not clear.';
        }

        if ($orders !== []) {
            $score -= 15;
            $details[] = 'An open order already exists for this symbol.';
        }

        $openCount = (int) data_get($portfolioContext, 'portfolio.exposure.spot_positions', 0);
        if ($hasPosition && $openCount > 0) {
            $score -= 5;
        }

        return [
            'score' => max(0.0, min(100.0, $score)),
            'note' => $hasPosition ? 'Existing exposure already exists.' : 'No current exposure in this symbol.',
            'details' => array_values(array_filter($details)),
        ];
    }

    private function liquidityScore(array $symbolContext): array
    {
        $metadata = (array) data_get($symbolContext, 'exchange_metadata', []);
        $price = (float) data_get($symbolContext, 'price.latest', 0);
        $candleCount = (int) data_get($symbolContext, 'indicators.candle_count', 0);
        $score = 70.0;
        $details = [];

        if ($price <= 0 || $candleCount < 5) {
            return [
                'score' => 0.0,
                'note' => 'Liquidity cannot be assessed because live price or candle history is missing.',
                'details' => [],
            ];
        }

        if ($metadata !== []) {
            $score += 10;
            $details[] = 'Exchange symbol metadata is available.';
        }

        if ((float) data_get($metadata, 'min_notional', 0) > 0) {
            $score += 5;
            $details[] = 'Minimum notional is known.';
        }

        if ($candleCount >= 20) {
            $score += 10;
            $details[] = 'Recent candle history is adequate.';
        }

        return [
            'score' => max(0.0, min(100.0, $score)),
            'note' => 'Liquidity and exchange filters appear serviceable.',
            'details' => $details,
        ];
    }

    private function executionQualityScore(array $symbolContext): array
    {
        $recentTrades = (array) data_get($symbolContext, 'recent_trades', []);
        $openOrders = (array) data_get($symbolContext, 'orders', []);
        $score = 65.0;
        $details = [];

        if ($recentTrades !== []) {
            $realized = array_map(static fn (array $trade): float => (float) ($trade['realized_pnl'] ?? 0), $recentTrades);
            $averageRealized = array_sum($realized) / max(1, count($realized));

            if ($averageRealized > 0) {
                $score += 15;
                $details[] = 'Recent realized PnL is positive.';
            } elseif ($averageRealized < 0) {
                $score -= 15;
                $details[] = 'Recent realized PnL is negative.';
            }
        }

        if ($openOrders !== []) {
            $score -= 5;
            $details[] = 'Open orders are already pending on the symbol.';
        }

        return [
            'score' => max(0.0, min(100.0, $score)),
            'note' => 'Recent execution quality looks reasonable.',
            'details' => $details,
        ];
    }

    private function operatorPreferenceScore(array $symbolContext, array $portfolioContext): array
    {
        $aiConfig = (array) data_get($portfolioContext, 'ai_trading_config', []);
        $symbol = strtoupper((string) data_get($symbolContext, 'symbol', ''));
        $allowedSymbols = $this->normalizeSymbols(data_get($aiConfig, 'allowed_symbols', []));
        $enabledSymbols = $this->normalizeSymbols(data_get($aiConfig, 'enabled_symbols', []));
        $score = 65.0;
        $details = [];

        if ($enabledSymbols !== [] && in_array($symbol, $enabledSymbols, true)) {
            $score = 100.0;
            $details[] = 'Symbol is explicitly enabled.';
        } elseif ($allowedSymbols !== [] && in_array($symbol, $allowedSymbols, true)) {
            $score = 95.0;
            $details[] = 'Symbol is on the operator allowlist.';
        } elseif ($allowedSymbols === [] && $enabledSymbols === []) {
            $score = 75.0;
            $details[] = 'No explicit allowlist preference is configured.';
        } else {
            $details[] = 'Symbol is not explicitly preferred.';
        }

        return [
            'score' => max(0.0, min(100.0, $score)),
            'note' => 'Operator preference has been applied.',
            'details' => $details,
        ];
    }

    private function weights(array $config): array
    {
        $raw = (array) ($config['score_weights_json'] ?? []);
        $weights = array_merge(self::DEFAULT_WEIGHTS, array_filter($raw, static fn ($value): bool => is_numeric($value)));
        $sum = array_sum($weights);

        if ($sum <= 0) {
            return self::DEFAULT_WEIGHTS;
        }

        foreach ($weights as $key => $weight) {
            $weights[$key] = (float) $weight / $sum;
        }

        return $weights;
    }

    private function factor(string $name, float $score, string $note, array $details, array $config): array
    {
        $weight = $this->weights($config)[$name] ?? 0.0;

        return [
            'factor' => $name,
            'weight' => round($weight, 6),
            'score' => round($score, 4),
            'contribution' => round($score * $weight, 4),
            'note' => $note,
            'details' => array_values(array_filter($details)),
        ];
    }

    private function strengths(array $factors): array
    {
        $strengths = [];

        foreach ($factors as $name => $factor) {
            if ((float) $factor['score'] >= 75) {
                $strengths[] = sprintf('%s: %s', $name, (string) ($factor['note'] ?? 'strong'));
            }
        }

        return $strengths;
    }

    private function cautions(array $factors, array $blockers): array
    {
        $cautions = array_map(static fn (string $blocker): string => $blocker, $blockers);

        foreach ($factors as $name => $factor) {
            if ((float) $factor['score'] <= 50) {
                $cautions[] = sprintf('%s: %s', $name, (string) ($factor['note'] ?? 'weak'));
            }
        }

        return array_values(array_unique($cautions));
    }

    private function recommendedAction(array $symbolContext, array $portfolioContext, string $trendDirection, float $score, float $threshold, array $blockers, array $config, array $external): array
    {
        $position = (array) data_get($symbolContext, 'position', []);
        $hasPosition = ! empty($position);
        $externalRisk = in_array('external_context_blocker', $blockers, true);
        $reduceRiskMode = (bool) data_get($portfolioContext, 'portfolio.risk.portfolio_intelligence.reduce_size_under_external_risk', false);

        if ($blockers !== []) {
            return [
                'action' => 'no_trade',
                'requires_approval' => true,
                'auto_allowed' => false,
            ];
        }

        if ($hasPosition && ($trendDirection === 'sell' || $score < ($threshold * 0.75) || $externalRisk || $reduceRiskMode)) {
            return [
                'action' => 'reduce',
                'requires_approval' => (bool) data_get($config, 'require_approval_for_rotation', true),
                'auto_allowed' => false,
            ];
        }

        if (! $hasPosition && $trendDirection === 'buy' && $score >= $threshold) {
            return [
                'action' => 'buy',
                'requires_approval' => false,
                'auto_allowed' => true,
            ];
        }

        if ($hasPosition && $score >= $threshold) {
            return [
                'action' => 'hold',
                'requires_approval' => false,
                'auto_allowed' => false,
            ];
        }

        if (! $hasPosition && $trendDirection === 'sell') {
            return [
                'action' => 'no_trade',
                'requires_approval' => true,
                'auto_allowed' => false,
            ];
        }

        return [
            'action' => 'no_trade',
            'requires_approval' => false,
            'auto_allowed' => false,
        ];
    }

    private function suggestedSizeCap(float $score, float $baseCapPct, float $concentrationScore, float $externalScore, string $action, bool $hasPosition): float
    {
        if ($action !== 'buy') {
            return 0.0;
        }

        $scoreFactor = max(0.25, min(1.0, $score / 100));
        $concentrationFactor = max(0.25, min(1.0, $concentrationScore / 100));
        $externalFactor = max(0.25, min(1.0, $externalScore / 100));
        $positionFactor = $hasPosition ? 0.6 : 1.0;

        return max(0.0, $baseCapPct * $scoreFactor * $concentrationFactor * $externalFactor * $positionFactor);
    }

    private function maxSeverity(array $events): string
    {
        $severityRank = [
            'LOW' => 1,
            'MEDIUM' => 2,
            'HIGH' => 3,
            'CRITICAL' => 4,
        ];

        $max = 'LOW';

        foreach ($events as $event) {
            $severity = strtoupper((string) data_get($event, 'severity', 'LOW'));
            if (($severityRank[$severity] ?? 0) > ($severityRank[$max] ?? 0)) {
                $max = $severity;
            }
        }

        return $max;
    }

    private function normalizeSymbols(mixed $symbols): array
    {
        $list = is_array($symbols) ? $symbols : explode(',', (string) $symbols);

        return array_values(array_filter(array_map(
            static fn ($symbol): string => strtoupper(trim((string) $symbol)),
            $list
        )));
    }
}
