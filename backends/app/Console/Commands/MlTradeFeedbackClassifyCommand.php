<?php

namespace App\Console\Commands;

use App\Domain\Ml\Shadow\TradeFeedbackEligibility;
use App\Models\MlTradeFeedbackTier;
use App\Models\QuickTrade;

final class MlTradeFeedbackClassifyCommand extends MlShadowCommand
{
    protected $signature = 'ml:trade-feedback:classify {--json} {--dry-run : Report without storing tiers}';

    protected $description = 'Classify every Quick Trade into TIER_EXCLUDED / TIER_COST_ONLY / TIER_FULL from its recorded fields, store the tier, and report counts.';

    public function handle(TradeFeedbackEligibility $eligibility): int
    {
        $summary = ['total' => 0, 'tiers' => [], 'reasons' => [], 'decision_sources' => []];
        QuickTrade::query()->orderBy('id')->chunk(500, function ($trades) use ($eligibility, &$summary): void {
            foreach ($trades as $trade) {
                $result = $eligibility->classify($trade);
                $summary['total']++;
                $summary['tiers'][$result['tier']] = ($summary['tiers'][$result['tier']] ?? 0) + 1;
                $summary['decision_sources'][$result['decision_source']] = ($summary['decision_sources'][$result['decision_source']] ?? 0) + 1;
                foreach ($result['reasons'] as $reason) {
                    $key = $result['tier'].' | '.$reason;
                    $summary['reasons'][$key] = ($summary['reasons'][$key] ?? 0) + 1;
                }
                if (! $this->option('dry-run')) {
                    MlTradeFeedbackTier::query()->updateOrCreate(['quick_trade_id' => $trade->id], [
                        'tier' => $result['tier'], 'reasons' => $result['reasons'], 'decision_source' => $result['decision_source'],
                        'rules_version' => TradeFeedbackEligibility::RULES_VERSION, 'classified_at' => now('UTC'),
                    ]);
                }
            }
        });
        ksort($summary['reasons']);

        if ($this->option('json')) {
            $this->line((string) json_encode($summary, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }
        $this->info("Quick Trades classified: {$summary['total']} (rules ".TradeFeedbackEligibility::RULES_VERSION.', cutoff '.config('ml.trade_feedback_min_entry_at').')');
        foreach ([TradeFeedbackEligibility::TIER_FULL, TradeFeedbackEligibility::TIER_COST_ONLY, TradeFeedbackEligibility::TIER_EXCLUDED] as $tier) {
            $this->line(sprintf('  %-15s %d', $tier, $summary['tiers'][$tier] ?? 0));
        }
        $this->line('Reasons (a trade can have several):');
        foreach ($summary['reasons'] as $reason => $count) {
            $this->line("  {$reason}: {$count}");
        }
        $this->line('Decision sources: '.json_encode($summary['decision_sources']).' - manual trades are never scored as model decisions.');

        return self::SUCCESS;
    }
}
