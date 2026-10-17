<?php

namespace App\Console\Commands;

use App\Domain\MarketIntelligence\V5\Application\MaturedOutcomeEvaluationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

final class V5ResearchEvaluateOutcomesCommand extends Command
{
    protected $signature = 'v5:research-evaluate-outcomes {--as-of=} {--limit=500}';

    protected $description = 'Evaluate matured V5 shadow predictions from persisted closed-candle observations';

    public function handle(MaturedOutcomeEvaluationService $service): int
    {
        $limit = (int) $this->option('limit');
        if ($limit < 1 || $limit > 5000) {
            $this->error('--limit must be between 1 and 5000.');

            return self::FAILURE;
        }

        try {
            $asOf = $this->option('as-of')
                ? Carbon::parse((string) $this->option('as-of'))->utc()
                : now()->utc();
            $result = $service->evaluate($asOf, $limit);
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            report($exception);
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
