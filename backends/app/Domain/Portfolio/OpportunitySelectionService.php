<?php

namespace App\Domain\Portfolio;

use App\Models\PortfolioIntelligenceConfig;

class OpportunitySelectionService
{
    /**
     * @param array<int, array<string, mixed>> $rankedOpportunities
     */
    public function select(array $rankedOpportunities, array $portfolioContext, mixed $config = [], array $riskBudget = []): array
    {
        $config = $this->normalizeConfig($config);
        $threshold = (float) ($config['opportunity_score_threshold'] ?? 65);
        $mode = (string) data_get($portfolioContext, 'portfolio.mode.ai_mode', 'advisor');
        $allowReduce = (bool) ($config['enable_reduce_recommendations'] ?? true);
        $allowRotation = (bool) ($config['enable_rotation_recommendations'] ?? false);
        $requireRotationApproval = (bool) ($config['require_approval_for_rotation'] ?? true);
        $autoAllowed = $mode === 'restricted_auto'
            && (bool) data_get($portfolioContext, 'portfolio.mode.trading_enabled', false)
            && (bool) data_get($riskBudget, 'new_entries_allowed', false)
            && (bool) data_get($riskBudget, 'allowed', false)
            && ! (bool) data_get($riskBudget, 'reduce_risk_mode', false);
        $maxSelections = max(0, (int) data_get($riskBudget, 'remaining.new_trades', (int) ($config['max_simultaneous_new_trades'] ?? 3)));

        $selected = [];
        $blocked = [];
        $watchlist = [];
        $reduceRecommendations = [];

        foreach ($rankedOpportunities as $opportunity) {
            $entry = $opportunity;
            $action = (string) ($entry['recommended_action'] ?? 'no_trade');
            $score = (float) ($entry['score'] ?? 0);
            $blockedReasons = (array) ($entry['blockers'] ?? []);
            $selectionStatus = 'blocked';
            $approvalRequired = true;

            if ($action === 'reduce') {
                if ($allowReduce) {
                    $entry['selection_status'] = 'reduce_recommendation';
                    $entry['approval_required'] = $requireRotationApproval || $mode !== 'restricted_auto';
                    $entry['auto_allowed'] = false;
                    $reduceRecommendations[] = $entry;
                    continue;
                }

                $blockedReasons[] = 'reduce_recommendations_disabled';
            }

            if ($action === 'buy' && $score >= $threshold && empty($blockedReasons)) {
                if ($maxSelections > 0) {
                    $selectionStatus = 'selected';
                    $approvalRequired = $mode !== 'restricted_auto' || ! $autoAllowed;
                    $entry['selection_status'] = $approvalRequired ? 'approval_required' : 'selected';
                    $entry['approval_required'] = $approvalRequired;
                    $entry['auto_allowed'] = $autoAllowed;
                    $selected[] = $entry;
                    $maxSelections--;
                    continue;
                }

                $entry['selection_status'] = 'watchlist';
                $entry['approval_required'] = $mode !== 'restricted_auto';
                $entry['auto_allowed'] = false;
                $entry['blocked_reasons'] = ['max_simultaneous_new_trades_reached'];
                $watchlist[] = $entry;
                continue;
            }

            $entry['selection_status'] = 'blocked';
            $entry['approval_required'] = false;
            $entry['auto_allowed'] = false;
            $entry['blocked_reasons'] = array_values(array_unique($blockedReasons ?: ['score_below_threshold']));
            $blocked[] = $entry;
        }

        if ($allowRotation && $reduceRecommendations !== []) {
            foreach ($reduceRecommendations as &$recommendation) {
                $recommendation['rotation_possible'] = true;
                $recommendation['approval_required'] = $requireRotationApproval || $mode !== 'restricted_auto';
            }
        }

        return [
            'selected' => $selected,
            'blocked' => $blocked,
            'watchlist' => $watchlist,
            'reduce_recommendations' => $reduceRecommendations,
            'auto_allowed' => $autoAllowed,
            'threshold' => $threshold,
            'summary' => [
                'selected_count' => count($selected),
                'blocked_count' => count($blocked),
                'watchlist_count' => count($watchlist),
                'reduce_count' => count($reduceRecommendations),
                'auto_allowed' => $autoAllowed,
            ],
        ];
    }

    private function normalizeConfig(mixed $config): array
    {
        if ($config instanceof PortfolioIntelligenceConfig) {
            return $config->toArray();
        }

        return is_array($config) ? $config : [];
    }
}
