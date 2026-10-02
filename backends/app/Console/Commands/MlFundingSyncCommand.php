<?php

namespace App\Console\Commands;

use App\Domain\Ml\Shadow\FundingRateService;
use Illuminate\Support\Carbon;
use Throwable;

final class MlFundingSyncCommand extends MlShadowCommand
{
    protected $signature = 'ml:funding:sync {--symbol=*} {--from= : Start (UTC) when nothing is stored yet; default ml.funding.default_months back} {--force}';

    protected $description = 'Idempotent, resumable sync of public Binance Futures funding-rate history (cost of holding 1h-1d shadow positions).';

    public function handle(FundingRateService $funding): int
    {
        if ($this->disabled()) {
            return self::SUCCESS;
        }
        $from = $this->option('from') ? Carbon::parse((string) $this->option('from'), 'UTC')->getTimestampMs() : null;
        $failed = false;
        foreach ($this->symbols() as $symbol) {
            try {
                $result = $funding->sync($symbol, $from);
                $this->line(sprintf('%s: funding rows +%d (pages %d, rejected %d)', $symbol, $result['written'], $result['pages'], $result['rejected']));
            } catch (Throwable $exception) {
                $failed = true;
                $this->error("{$symbol}: funding sync failed - {$exception->getMessage()} (re-run resumes after the newest stored row)");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
