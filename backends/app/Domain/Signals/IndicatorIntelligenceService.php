<?php

namespace App\Domain\Signals;

use App\Models\FutureTradeBotTrade;

class IndicatorIntelligenceService
{
    public const PROFILE_FUTURE_TRADE = 'future_trade';

    public function normalizeSettings(string $profile, array $settings): array
    {
        $catalog = $this->catalogKeyed($profile);
        $legacyWeights = (array) ($settings['indicatorInfluence'] ?? []);
        $storedControls = (array) ($settings['indicatorControls'] ?? []);
        $normalizedControls = [];
        $normalizedWeights = [];

        foreach ($catalog as $key => $item) {
            $stored = (array) ($storedControls[$key] ?? []);
            $defaultWeight = (int) ($item['defaultWeight'] ?? 5);
            $weightSource = array_key_exists('weight', $stored)
                ? $stored['weight']
                : ($legacyWeights[$key] ?? $defaultWeight);
            $weight = (int) max(0, min(10, round((float) $weightSource)));
            $enabled = array_key_exists('enabled', $stored) ? (bool) $stored['enabled'] : $weight > 0;

            $normalizedControls[$key] = [
                'key' => $key,
                'label' => (string) $item['label'],
                'note' => (string) ($item['note'] ?? ''),
                'category' => (string) ($item['category'] ?? 'indicator'),
                'impact' => (string) ($item['impact'] ?? 'positive'),
                'weight' => $enabled ? $weight : 0,
                'enabled' => $enabled,
                'defaultWeight' => $defaultWeight,
                'configurable' => true,
                'aliases' => array_values((array) ($item['aliases'] ?? [])),
            ];

            $normalizedWeights[$key] = $enabled ? $weight : 0;
        }

        $settings['indicatorControls'] = $normalizedControls;
        $settings['indicatorInfluence'] = $normalizedWeights;

        return $settings;
    }

    public function controls(string $profile, array $settings): array
    {
        return (array) ($this->normalizeSettings($profile, $settings)['indicatorControls'] ?? []);
    }

    public function catalog(string $profile): array
    {
        return array_values($this->catalogKeyed($profile));
    }

    public function hardBlockers(string $profile): array
    {
        if ($profile !== self::PROFILE_FUTURE_TRADE) {
            return [];
        }

        return [
            ['key' => 'emergency_stop', 'label' => 'Emergency stop'],
            ['key' => 'open_position_guard', 'label' => 'Already open position check'],
            ['key' => 'duplicate_live_position', 'label' => 'Duplicate live position prevention'],
            ['key' => 'max_daily_loss', 'label' => 'Max daily loss'],
            ['key' => 'max_open_positions', 'label' => 'Max open positions'],
            ['key' => 'max_trades_per_day', 'label' => 'Max trades per day'],
            ['key' => 'wallet_unavailable', 'label' => 'Wallet unavailable'],
            ['key' => 'exchange_failure', 'label' => 'Exchange/API failure'],
            ['key' => 'stale_feed', 'label' => 'Stale feed'],
            ['key' => 'minimum_order_size', 'label' => 'Minimum order size'],
            ['key' => 'spread_too_wide', 'label' => 'Spread too wide'],
            ['key' => 'slippage_too_high', 'label' => 'Slippage too high'],
            ['key' => 'risk_reward_invalid', 'label' => 'Risk-reward invalid'],
            ['key' => 'binance_position_confirmation', 'label' => 'Binance position confirmation'],
        ];
    }

    public function annotateBreakdown(string $profile, array $breakdown, array $settings): array
    {
        $controls = $this->controls($profile, $settings);
        $annotated = [];

        foreach ($breakdown as $componentKey => $item) {
            $controlKey = (string) ($item['key'] ?? $componentKey);
            $control = (array) ($controls[$controlKey] ?? []);
            $annotated[$componentKey] = array_merge($item, [
                'controlKey' => $controlKey,
                'enabled' => (bool) ($control['enabled'] ?? (((float) ($item['influenceLevel'] ?? 0)) > 0)),
                'configuredWeight' => (int) ($control['weight'] ?? 0),
                'defaultWeight' => (int) ($control['defaultWeight'] ?? 0),
                'category' => (string) ($control['category'] ?? 'indicator'),
                'note' => (string) ($control['note'] ?? ''),
            ]);
        }

        return $annotated;
    }

    /**
     * @param iterable<FutureTradeBotTrade> $trades
     */
    public function performance(string $profile, iterable $trades, array $settings, iterable $trackerLogs = []): array
    {
        $controls = $this->controls($profile, $settings);
        $stats = [];

        foreach ($controls as $controlKey => $control) {
            $stats[$controlKey] = [
                'key' => $controlKey,
                'label' => $control['label'],
                'enabled' => (bool) ($control['enabled'] ?? true),
                'weight' => (int) ($control['weight'] ?? 0),
                'impact' => (string) ($control['impact'] ?? 'positive'),
                'category' => (string) ($control['category'] ?? 'indicator'),
                'samples' => 0,
                'activeSamples' => 0,
                'wins' => 0,
                'losses' => 0,
                'positiveSamples' => 0,
                'negativeSamples' => 0,
                'positiveWinSamples' => 0,
                'positiveLossSamples' => 0,
                'negativeWinSamples' => 0,
                'negativeLossSamples' => 0,
                'trackerSamples' => 0,
                'trackerPositiveEligible' => 0,
                'trackerPositiveBlocked' => 0,
                'trackerNegativeEligible' => 0,
                'trackerNegativeBlocked' => 0,
                'totalContribution' => 0.0,
                'totalNetPnl' => 0.0,
                'lastSeenAt' => null,
            ];
        }

        foreach ($trades as $trade) {
            $netPnl = (float) ($trade->net_pnl ?? 0);
            if ($netPnl === 0.0) {
                continue;
            }

            $breakdown = (array) data_get($trade->meta, 'signal.debug.componentBreakdown', data_get($trade->meta, 'signal.componentBreakdown', []));
            if ($breakdown === []) {
                continue;
            }

            foreach ($breakdown as $component) {
                $controlKey = (string) ($component['key'] ?? '');
                if ($controlKey === '' || ! array_key_exists($controlKey, $stats)) {
                    continue;
                }

                $contribution = (float) ($component['finalContribution'] ?? 0.0);
                $stats[$controlKey]['samples']++;
                $stats[$controlKey]['totalContribution'] += $contribution;
                $stats[$controlKey]['totalNetPnl'] += $netPnl;
                $stats[$controlKey]['lastSeenAt'] = optional($trade->created_at)->toIso8601String();
                if (abs($contribution) > 0.00001) {
                    $stats[$controlKey]['activeSamples']++;
                }

                if ($netPnl > 0) {
                    $stats[$controlKey]['wins']++;
                } elseif ($netPnl < 0) {
                    $stats[$controlKey]['losses']++;
                }

                if ($contribution > 0) {
                    $stats[$controlKey]['positiveSamples']++;
                    if ($netPnl > 0) {
                        $stats[$controlKey]['positiveWinSamples']++;
                    } elseif ($netPnl < 0) {
                        $stats[$controlKey]['positiveLossSamples']++;
                    }
                } elseif ($contribution < 0) {
                    $stats[$controlKey]['negativeSamples']++;
                    if ($netPnl > 0) {
                        $stats[$controlKey]['negativeWinSamples']++;
                    } elseif ($netPnl < 0) {
                        $stats[$controlKey]['negativeLossSamples']++;
                    }
                }
            }
        }

        foreach ($trackerLogs as $log) {
            $context = is_array($log) ? (array) ($log['context'] ?? []) : (array) ($log->context ?? []);
            $breakdown = (array) ($context['componentBreakdown'] ?? []);
            if ($breakdown === []) {
                continue;
            }

            $event = strtoupper(trim((string) (is_array($log) ? ($log['event'] ?? '') : ($log->event ?? ''))));
            $status = strtoupper(trim((string) ($context['status'] ?? $context['decisionStatus'] ?? '')));
            $eligible = in_array($event, ['BUY_EXECUTED', 'SELL_EXECUTED', 'LIVE_BUY_EXECUTED', 'LIVE_SELL_EXECUTED'], true)
                || in_array($status, ['OPEN', 'ELIGIBLE', 'SUBMITTED'], true);
            $blocked = in_array($event, ['SIGNAL_SKIPPED', 'TRADE_BLOCKED'], true)
                || in_array($status, ['SKIPPED', 'BLOCKED'], true);

            if (! $eligible && ! $blocked) {
                continue;
            }

            foreach ($breakdown as $component) {
                $controlKey = (string) ($component['key'] ?? '');
                if ($controlKey === '' || ! array_key_exists($controlKey, $stats)) {
                    continue;
                }

                $contribution = (float) ($component['finalContribution'] ?? 0.0);
                if (abs($contribution) < 0.00001) {
                    continue;
                }

                $stats[$controlKey]['trackerSamples']++;
                if ($contribution > 0 && $eligible) {
                    $stats[$controlKey]['trackerPositiveEligible']++;
                } elseif ($contribution > 0 && $blocked) {
                    $stats[$controlKey]['trackerPositiveBlocked']++;
                } elseif ($contribution < 0 && $eligible) {
                    $stats[$controlKey]['trackerNegativeEligible']++;
                } elseif ($contribution < 0 && $blocked) {
                    $stats[$controlKey]['trackerNegativeBlocked']++;
                }
            }
        }

        foreach ($stats as $key => $item) {
            $positiveSamples = max(0, (int) $item['positiveSamples']);
            $negativeSamples = max(0, (int) $item['negativeSamples']);
            $directionalSamples = $positiveSamples + $negativeSamples;
            $correctSignals = (int) $item['positiveWinSamples'] + (int) $item['negativeLossSamples'];
            $stats[$key]['avgContribution'] = $item['samples'] > 0 ? round($item['totalContribution'] / $item['samples'], 4) : 0.0;
            $stats[$key]['avgNetPnl'] = $item['samples'] > 0 ? round($item['totalNetPnl'] / $item['samples'], 4) : 0.0;
            $stats[$key]['supportiveWinRate'] = $positiveSamples > 0 ? round($item['positiveWinSamples'] / $positiveSamples, 4) : null;
            $stats[$key]['blockingAccuracy'] = $negativeSamples > 0 ? round($item['negativeLossSamples'] / $negativeSamples, 4) : null;
            $stats[$key]['overallAccuracy'] = $directionalSamples > 0 ? round($correctSignals / $directionalSamples, 4) : null;
            $stats[$key]['sampleState'] = $directionalSamples >= 8 ? 'stable' : ($directionalSamples >= 4 ? 'limited' : 'insufficient');
            $trackerDirectionalSamples = (int) $item['trackerPositiveEligible'] + (int) $item['trackerPositiveBlocked'] + (int) $item['trackerNegativeEligible'] + (int) $item['trackerNegativeBlocked'];
            $trackerCorrect = (int) $item['trackerPositiveEligible'] + (int) $item['trackerNegativeBlocked'];
            $stats[$key]['trackerAlignmentRate'] = $trackerDirectionalSamples > 0 ? round($trackerCorrect / $trackerDirectionalSamples, 4) : null;
            $tradePoints = ((int) $item['positiveWinSamples'] * 2.0) + ((int) $item['negativeLossSamples'] * 1.5) - ((int) $item['positiveLossSamples'] * 2.0) - ((int) $item['negativeWinSamples'] * 1.5);
            $trackerPoints = ((int) $item['trackerPositiveEligible'] * 1.0) + ((int) $item['trackerNegativeBlocked'] * 0.75) - ((int) $item['trackerPositiveBlocked'] * 0.5) - ((int) $item['trackerNegativeEligible'] * 0.75);
            $rawSuccessPoints = $tradePoints + $trackerPoints;
            $sampleWeight = min(1.0, (($directionalSamples * 1.2) + $trackerDirectionalSamples) / 30);
            $successRateBase = $directionalSamples > 0 && is_numeric($stats[$key]['overallAccuracy'])
                ? (float) $stats[$key]['overallAccuracy']
                : ($trackerDirectionalSamples > 0 && is_numeric($stats[$key]['trackerAlignmentRate'])
                    ? (float) $stats[$key]['trackerAlignmentRate']
                    : 0.5);
            $stats[$key]['successPoints'] = round($rawSuccessPoints, 2);
            $stats[$key]['successRate'] = round($successRateBase, 4);
            $stats[$key]['successScore'] = (int) round(max(0, min(100, (($successRateBase * 70) + (min(20, max(-20, $rawSuccessPoints)) + 20) * 0.75) * $sampleWeight + (35 * (1 - $sampleWeight)))));
            $stats[$key]['rating'] = match (true) {
                $stats[$key]['successScore'] >= 80 => 'A',
                $stats[$key]['successScore'] >= 68 => 'B',
                $stats[$key]['successScore'] >= 55 => 'C',
                $stats[$key]['successScore'] >= 40 => 'D',
                default => 'E',
            };
            unset($stats[$key]['totalContribution'], $stats[$key]['totalNetPnl']);
        }

        uasort($stats, function (array $left, array $right): int {
            return [$right['samples'], abs((float) $right['avgContribution'])]
                <=> [$left['samples'], abs((float) $left['avgContribution'])];
        });

        return array_values($stats);
    }

    public function suggestions(string $profile, array $settings, array $performance): array
    {
        $suggestions = [];

        foreach ($performance as $item) {
            $weight = (int) ($item['weight'] ?? 0);
            $samples = (int) (($item['positiveSamples'] ?? 0) + ($item['negativeSamples'] ?? 0));
            $supportiveWinRate = $item['supportiveWinRate'];
            $blockingAccuracy = $item['blockingAccuracy'];
            $label = (string) ($item['label'] ?? $item['key'] ?? 'Indicator');

            if ($samples < 4) {
                continue;
            }

            if (($item['positiveSamples'] ?? 0) >= 4 && is_numeric($supportiveWinRate)) {
                if ((float) $supportiveWinRate <= 0.42 && $weight >= 6) {
                    $suggestions[] = [
                        'key' => (string) $item['key'],
                        'action' => 'reduce',
                        'severity' => 'medium',
                        'title' => sprintf('Reduce %s support weight', $label),
                        'reason' => sprintf('%s only helped winning trades %.0f%% of the times it was positive.', $label, (float) $supportiveWinRate * 100),
                    ];
                } elseif ((float) $supportiveWinRate >= 0.62 && $weight <= 5) {
                    $suggestions[] = [
                        'key' => (string) $item['key'],
                        'action' => 'increase',
                        'severity' => 'medium',
                        'title' => sprintf('Increase %s support weight', $label),
                        'reason' => sprintf('%s aligned with winners %.0f%% of the times it was positive.', $label, (float) $supportiveWinRate * 100),
                    ];
                }
            }

            if (($item['negativeSamples'] ?? 0) >= 4 && is_numeric($blockingAccuracy)) {
                if ((float) $blockingAccuracy <= 0.35 && $weight >= 5) {
                    $suggestions[] = [
                        'key' => (string) $item['key'],
                        'action' => 'soften',
                        'severity' => 'high',
                        'title' => sprintf('Soften %s penalty', $label),
                        'reason' => sprintf('%s blocked or penalized trades that still won too often (warning accuracy %.0f%%).', $label, (float) $blockingAccuracy * 100),
                    ];
                } elseif ((float) $blockingAccuracy >= 0.65 && $weight <= 5) {
                    $suggestions[] = [
                        'key' => (string) $item['key'],
                        'action' => 'strengthen',
                        'severity' => 'medium',
                        'title' => sprintf('Strengthen %s penalty', $label),
                        'reason' => sprintf('%s warned correctly before losers %.0f%% of the times it turned negative.', $label, (float) $blockingAccuracy * 100),
                    ];
                }
            }
        }

        if ($suggestions === []) {
            $suggestions[] = [
                'key' => 'system',
                'action' => 'hold',
                'severity' => 'low',
                'title' => 'No immediate weight change recommended',
                'reason' => 'Recent samples do not show a strong case to strengthen or weaken any configurable indicator yet.',
            ];
        }

        return array_slice($suggestions, 0, 8);
    }

    private function catalogKeyed(string $profile): array
    {
        if ($profile !== self::PROFILE_FUTURE_TRADE) {
            return [];
        }

        return [
            'vwapPositive' => ['key' => 'vwapPositive', 'label' => 'VWAP positive', 'note' => 'Direction alignment contribution', 'impact' => 'positive', 'category' => 'core', 'defaultWeight' => 7],
            'emaPositive' => ['key' => 'emaPositive', 'label' => 'EMA positive', 'note' => '5m EMA trend alignment contribution', 'impact' => 'positive', 'category' => 'core', 'defaultWeight' => 7],
            'adxPositive' => ['key' => 'adxPositive', 'label' => 'ADX positive', 'note' => 'Trend strength contribution', 'impact' => 'positive', 'category' => 'core', 'defaultWeight' => 6],
            'macdPositive' => ['key' => 'macdPositive', 'label' => 'MACD positive', 'note' => 'Directional momentum contribution', 'impact' => 'positive', 'category' => 'core', 'defaultWeight' => 7],
            'rsiPositive' => ['key' => 'rsiPositive', 'label' => 'RSI positive', 'note' => 'Momentum window contribution', 'impact' => 'positive', 'category' => 'indicator', 'defaultWeight' => 2],
            'volumePositive' => ['key' => 'volumePositive', 'label' => 'Volume positive', 'note' => 'Participation confirmation contribution', 'impact' => 'positive', 'category' => 'indicator', 'defaultWeight' => 2],
            'htfPositive' => ['key' => 'htfPositive', 'label' => 'HTF positive', 'note' => '1h higher timeframe alignment', 'impact' => 'positive', 'category' => 'context', 'defaultWeight' => 2],
            'macroPositive' => ['key' => 'macroPositive', 'label' => '4h HTF positive', 'note' => 'Macro filter contribution', 'impact' => 'positive', 'category' => 'context', 'defaultWeight' => 2],
            'structurePositive' => ['key' => 'structurePositive', 'label' => 'Structure positive', 'note' => '15m structure/order block/MSB', 'impact' => 'positive', 'category' => 'context', 'defaultWeight' => 2],
            'sequencePositive' => ['key' => 'sequencePositive', 'label' => 'Sequence positive', 'note' => 'Recent candle direction strength', 'impact' => 'positive', 'category' => 'indicator', 'defaultWeight' => 4],
            'extensionPositive' => ['key' => 'extensionPositive', 'label' => 'Entry extension positive', 'note' => 'Reward when entry is not overextended', 'impact' => 'positive', 'category' => 'indicator', 'defaultWeight' => 2],
            'symbolPerfPositive' => ['key' => 'symbolPerfPositive', 'label' => 'Symbol performance positive', 'note' => 'Positive recent symbol performance', 'impact' => 'positive', 'category' => 'adaptive', 'defaultWeight' => 1],
            'symbolPerfPenalty' => ['key' => 'symbolPerfPenalty', 'label' => 'Symbol performance penalty', 'note' => 'Negative recent symbol performance', 'impact' => 'negative', 'category' => 'adaptive', 'defaultWeight' => 1],
            'macdHistogramBonus' => ['key' => 'macdHistogramBonus', 'label' => 'MACD histogram bonus', 'note' => 'Extra momentum bonus', 'impact' => 'positive', 'category' => 'indicator', 'defaultWeight' => 4],
            'directionalSignalBonus' => ['key' => 'directionalSignalBonus', 'label' => 'Directional bonus', 'note' => 'Non-WAIT directional bonus', 'impact' => 'positive', 'category' => 'adaptive', 'defaultWeight' => 5],
            'trendContinuationBonus' => ['key' => 'trendContinuationBonus', 'label' => 'Trend continuation', 'note' => 'Core trend continuation bonus', 'impact' => 'positive', 'category' => 'adaptive', 'defaultWeight' => 5],
            'volumePenalty' => ['key' => 'volumePenalty', 'label' => 'Volume penalty', 'note' => 'Penalty when volume confirmation fails', 'impact' => 'negative', 'category' => 'guard', 'defaultWeight' => 2],
            'macdPenalty' => ['key' => 'macdPenalty', 'label' => 'MACD penalty', 'note' => 'Penalty when MACD direction fails', 'impact' => 'negative', 'category' => 'guard', 'defaultWeight' => 2],
            'htfPenalty' => ['key' => 'htfPenalty', 'label' => 'HTF penalty', 'note' => 'Penalty when HTF disagrees', 'impact' => 'negative', 'category' => 'guard', 'defaultWeight' => 2],
            'structurePenalty' => ['key' => 'structurePenalty', 'label' => 'Structure penalty', 'note' => 'Penalty when structure is missing', 'impact' => 'negative', 'category' => 'guard', 'defaultWeight' => 2],
            'extensionPenalty' => ['key' => 'extensionPenalty', 'label' => 'Extension penalty', 'note' => 'Penalty when entry is stretched', 'impact' => 'negative', 'category' => 'guard', 'defaultWeight' => 2],
            'rsiPenalty' => ['key' => 'rsiPenalty', 'label' => 'RSI penalty', 'note' => 'Penalty when RSI window fails', 'impact' => 'negative', 'category' => 'guard', 'defaultWeight' => 2],
            'choppyPenalty' => ['key' => 'choppyPenalty', 'label' => 'Choppy market', 'note' => 'Penalty when choppy regime detected', 'impact' => 'negative', 'category' => 'guard', 'defaultWeight' => 2],
            'weakSetupPenalty' => ['key' => 'weakSetupPenalty', 'label' => 'Weak setup', 'note' => 'Penalty when weak setup is detected', 'impact' => 'negative', 'category' => 'guard', 'defaultWeight' => 1],
            'recentAccuracyWeight' => ['key' => 'recentAccuracyWeight', 'label' => 'Recent accuracy', 'note' => 'Semi/fully dynamic recent accuracy influence', 'impact' => 'dynamic', 'category' => 'dynamic', 'defaultWeight' => 3],
            'lossStreakWeight' => ['key' => 'lossStreakWeight', 'label' => 'Loss streak', 'note' => 'Recent loss cooldown/threshold influence', 'impact' => 'dynamic', 'category' => 'dynamic', 'defaultWeight' => 3],
            'atrWeight' => ['key' => 'atrWeight', 'label' => 'ATR', 'note' => 'ATR-driven TP/SL influence', 'impact' => 'dynamic', 'category' => 'dynamic', 'defaultWeight' => 3],
            'volatilityWeight' => ['key' => 'volatilityWeight', 'label' => 'Volatility', 'note' => 'Volatility cooldown/size influence', 'impact' => 'dynamic', 'category' => 'dynamic', 'defaultWeight' => 3],
        ];
    }
}
