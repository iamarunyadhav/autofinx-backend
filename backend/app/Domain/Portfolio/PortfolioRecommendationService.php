<?php

namespace App\Domain\Portfolio;

use App\Models\PortfolioIntelligenceConfig;
use App\Models\PortfolioIntelligenceRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class PortfolioRecommendationService
{
    public function __construct(
        private readonly PortfolioContextBuilderService $contextBuilder,
        private readonly OpportunityScoringService $scoring,
        private readonly SymbolRankingService $ranking,
        private readonly PortfolioRiskBudgetService $riskBudget,
        private readonly OpportunitySelectionService $selection,
        private readonly PortfolioAllocationService $allocation,
    ) {
    }

    public function run(string $workspaceId, array $options = []): array
    {
        return $this->build($workspaceId, $options, true);
    }

    public function preview(string $workspaceId, array $options = []): array
    {
        return $this->build($workspaceId, $options, false);
    }

    public function latestRun(string $workspaceId): ?PortfolioIntelligenceRun
    {
        return PortfolioIntelligenceRun::query()
            ->where('workspace_id', $workspaceId)
            ->orderByDesc('started_at')
            ->first();
    }

    public function latestSnapshot(string $workspaceId): array
    {
        $run = $this->latestRun($workspaceId);
        if ($run) {
            return $this->snapshotFromRun($run);
        }

        return $this->preview($workspaceId);
    }

    public function symbolScore(string $workspaceId, string $symbol, array $options = []): array
    {
        $preview = $this->preview($workspaceId, array_merge($options, ['symbol' => $symbol]));
        foreach ((array) data_get($preview, 'ranked_opportunities', []) as $opportunity) {
            if (strtoupper((string) data_get($opportunity, 'symbol', '')) === strtoupper($symbol)) {
                return $opportunity;
            }
        }

        return [];
    }

    private function build(string $workspaceId, array $options, bool $persist): array
    {
        $startedAt = Carbon::now();
        $config = $this->configForWorkspace($workspaceId);
        $portfolioContext = $this->contextBuilder->build($workspaceId, $options);
        $symbolFilter = strtoupper(trim((string) ($options['symbol'] ?? '')));
        $symbols = (array) data_get($portfolioContext, 'symbols', []);

        if ($symbolFilter !== '') {
            $symbols = array_values(array_filter($symbols, static function (array $symbol) use ($symbolFilter): bool {
                return strtoupper((string) ($symbol['symbol'] ?? '')) === $symbolFilter;
            }));
        }

        $scored = $this->scoring->scoreBatch($symbols, $portfolioContext, $config->toArray());
        $ranked = $this->ranking->rank($scored, $config->toArray());
        $riskBudget = $this->riskBudget->evaluate($portfolioContext, $config->toArray());
        $selection = $this->selection->select($ranked, $portfolioContext, $config->toArray(), $riskBudget);
        $allocation = $this->allocation->allocate($selection['selected'], $portfolioContext, $config->toArray(), $riskBudget);
        $rotationRecommendations = $this->rotationRecommendations($selection, $allocation, $config->toArray(), $portfolioContext);
        $summary = $this->summary($ranked, $selection, $allocation, $riskBudget, $config->toArray());

        $result = [
            'workspace_id' => $workspaceId,
            'generated_at' => now()->toIso8601String(),
            'portfolio_context' => $portfolioContext,
            'risk_budget' => $riskBudget,
            'symbol_universe' => $symbols,
            'ranked_opportunities' => $ranked,
            'selection' => $selection,
            'allocation' => $allocation,
            'recommendations' => [
                'selected' => $selection['selected'],
                'blocked' => $selection['blocked'],
                'watchlist' => $selection['watchlist'],
                'reduce_recommendations' => $selection['reduce_recommendations'],
                'rotation_recommendations' => $rotationRecommendations,
                'allocation' => $allocation,
                'summary' => $summary,
            ],
            'summary' => $summary,
            'config' => $config->toArray(),
        ];

        if (! $persist) {
            return $result;
        }

        $run = PortfolioIntelligenceRun::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'portfolio_intelligence_config_id' => $config->id,
            'trigger_type' => (string) ($options['trigger_type'] ?? 'manual'),
            'status' => (string) ($summary['state'] ?? 'completed'),
            'correlation_id' => (string) ($options['correlation_id'] ?? ('portfolio:' . $workspaceId . ':' . now()->format('YmdHis') . ':' . Str::lower(Str::random(8)))),
            'symbol_universe_json' => $symbols,
            'portfolio_context_json' => $portfolioContext,
            'ranked_opportunities_json' => $ranked,
            'allocation_json' => $allocation,
            'risk_budget_json' => $riskBudget,
            'recommendations_json' => $result['recommendations'],
            'selection_summary_json' => $summary,
            'config_snapshot_json' => $config->toArray(),
            'external_context_json' => data_get($portfolioContext, 'portfolio.external_context', []),
            'equity_estimate' => data_get($portfolioContext, 'portfolio.capital.equity_estimate', 0),
            'allocated_capital' => $allocation['allocated_capital'],
            'free_capital' => data_get($portfolioContext, 'portfolio.capital.free_capital', 0),
            'selected_count' => $summary['selected_count'],
            'blocked_count' => $summary['blocked_count'],
            'paused_count' => $summary['watchlist_count'],
            'started_at' => $startedAt,
            'finished_at' => Carbon::now(),
            'duration_ms' => $startedAt->diffInMilliseconds(Carbon::now()),
        ]);

        $result['run'] = $run->fresh();

        return $result;
    }

    private function configForWorkspace(string $workspaceId): PortfolioIntelligenceConfig
    {
        return PortfolioIntelligenceConfig::firstOrCreate(
            ['workspace_id' => $workspaceId],
            [
                'id' => (string) Str::uuid(),
                ...PortfolioIntelligenceConfig::defaults(),
            ]
        );
    }

    /**
     * @param array<string, mixed> $selection
     */
    private function rotationRecommendations(array $selection, array $allocation, array $config, array $portfolioContext): array
    {
        if (! (bool) ($config['enable_rotation_recommendations'] ?? false)) {
            return [];
        }

        $selected = array_values((array) ($selection['selected'] ?? []));
        $reduceRecommendations = array_values((array) ($selection['reduce_recommendations'] ?? []));
        if ($selected === [] || $reduceRecommendations === []) {
            return [];
        }

        $topSelected = $selected[0];
        $rotation = [];

        foreach ($reduceRecommendations as $candidate) {
            $fromSymbol = strtoupper((string) data_get($candidate, 'symbol', ''));
            $toSymbol = strtoupper((string) data_get($topSelected, 'symbol', ''));

            if ($fromSymbol === '' || $toSymbol === '' || $fromSymbol === $toSymbol) {
                continue;
            }

            $rotation[] = [
                'from_symbol' => $fromSymbol,
                'to_symbol' => $toSymbol,
                'from_score' => (float) data_get($candidate, 'score', 0),
                'to_score' => (float) data_get($topSelected, 'score', 0),
                'approval_required' => (bool) data_get($candidate, 'approval_required', true) || (bool) data_get($topSelected, 'approval_required', true),
                'reason' => 'Rotate capital away from a weaker managed position into the strongest allowed opportunity.',
                'size_cap_pct' => min(
                    (float) data_get($candidate, 'suggested_size_cap_pct', 0),
                    (float) data_get($topSelected, 'suggested_size_cap_pct', 0)
                ),
            ];
        }

        return array_slice($rotation, 0, 3);
    }

    /**
     * @param array<int, array<string, mixed>> $ranked
     * @param array<string, mixed> $selection
     * @param array<string, mixed> $allocation
     * @param array<string, mixed> $riskBudget
     */
    private function summary(array $ranked, array $selection, array $allocation, array $riskBudget, array $config): array
    {
        $top = $ranked[0] ?? [];
        $state = (string) data_get($riskBudget, 'state', 'healthy');

        if (! (bool) data_get($riskBudget, 'allowed', false)) {
            $state = 'blocked';
        } elseif ((bool) data_get($riskBudget, 'reduce_risk_mode', false)) {
            $state = 'constrained';
        }

        return [
            'state' => $state,
            'selected_count' => count((array) ($selection['selected'] ?? [])),
            'blocked_count' => count((array) ($selection['blocked'] ?? [])),
            'watchlist_count' => count((array) ($selection['watchlist'] ?? [])),
            'reduce_count' => count((array) ($selection['reduce_recommendations'] ?? [])),
            'allocated_count' => count((array) data_get($allocation, 'allocations', [])),
            'auto_allowed' => (bool) data_get($selection, 'auto_allowed', false),
            'top_symbol' => data_get($top, 'symbol'),
            'top_score' => data_get($top, 'score'),
            'threshold' => (float) ($config['opportunity_score_threshold'] ?? 65),
            'no_trade' => count((array) ($selection['selected'] ?? [])) === 0 && count((array) ($selection['reduce_recommendations'] ?? [])) === 0,
            'reason_codes' => array_values(array_unique(array_merge(
                (array) data_get($riskBudget, 'reason_codes', []),
                count((array) ($selection['selected'] ?? [])) === 0 ? ['no_selected_opportunities'] : []
            ))),
        ];
    }

    private function snapshotFromRun(PortfolioIntelligenceRun $run): array
    {
        return [
            'workspace_id' => (string) $run->workspace_id,
            'generated_at' => optional($run->finished_at ?? $run->started_at ?? $run->created_at)->toIso8601String(),
            'portfolio_context' => $run->portfolio_context_json ?? [],
            'risk_budget' => $run->risk_budget_json ?? [],
            'symbol_universe' => $run->symbol_universe_json ?? [],
            'ranked_opportunities' => $run->ranked_opportunities_json ?? [],
            'selection' => $run->selection_summary_json ?? [],
            'allocation' => $run->allocation_json ?? [],
            'recommendations' => $run->recommendations_json ?? [],
            'summary' => $run->selection_summary_json ?? [],
            'config' => $run->config_snapshot_json ?? [],
            'run' => $run,
        ];
    }
}
