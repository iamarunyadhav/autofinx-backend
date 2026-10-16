<?php

namespace App\Console\Commands;

use App\Domain\MarketOpportunityBrain\Adapters\BinancePublicHistoryFetcher;
use App\Domain\MarketOpportunityBrain\Forecast\ChronosBatchRunner;
use App\Domain\MarketOpportunityBrain\Validation\ExperimentV1Orchestrator;
use Illuminate\Console\Command;

/**
 * RESEARCH_ONLY. Runs the pre-registered mob-exp-v1 offline replay (public Binance klines before the
 * sealed holdout + Chronos-2 forecasts). No API keys, no orders, no DB writes. The public-data flags
 * are enabled in-process only for this command; .env defaults stay OFF.
 */
class MobExperimentV1Command extends Command
{
    protected $signature = 'mob:experiment-v1 {--out=D:\autofinx-ml\mob-experiment-v1} {--max-windows= : smoke-test cap per symbol (NOT a valid experiment run)}';

    protected $description = 'MOB Phase 5 research-only replay experiment (arms A and C). Never trades.';

    public function handle(): int
    {
        config([
            'market_opportunity_brain.public_data_enabled' => true,
            'market_opportunity_brain.max_api_weight_per_minute' => 100,
            'market_opportunity_brain.max_discovery_symbols' => 1,
            'market_opportunity_brain.forecast_enabled' => true,
            'cache.default' => 'array',
        ]);
        $max = $this->option('max-windows');
        $result = (new ExperimentV1Orchestrator(new BinancePublicHistoryFetcher(), new ChronosBatchRunner()))
            ->run((string) $this->option('out'), $max !== null && $max !== '' ? (int) $max : null);
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return ($result['verdict'] ?? '') === 'BLOCKED_MODEL_UNAVAILABLE' ? 2 : 0;
    }
}
