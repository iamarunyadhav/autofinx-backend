<?php

namespace App\Console\Commands;

use App\Domain\Market\MarketPriceCacheService;
use App\Models\QuickTrade;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SampleQuickTradeResearchCommand extends Command
{
    protected $signature = 'quick-trades:sample-research {--limit=1000}';
    protected $description = 'Persist fresh cached quote observations for Quick Trade research; never places orders.';

    public function handle(MarketPriceCacheService $prices): int
    {
        if (! config('quick_trade_research.enabled', true) || ! Schema::hasTable('quick_trade_research_samples')) {
            $this->warn('Research disabled or sample migration pending.');
            return self::SUCCESS;
        }
        $trades = QuickTrade::where('mode', 'live')->where('provider_key', 'binance')
            ->where(function ($q) {
                $q->where('status', 'open')->orWhere('signal_payload->research->collect_until_ms', '>=', now()->getTimestampMs());
            })->orderBy('entry_at')->limit(max(1, min(5000, (int) $this->option('limit'))))->get();
        $quotes = [];
        $count = 0;
        foreach ($trades as $trade) {
            try {
                // The canonical futures feed is production-market data, not testnet.
                if (data_get($trade->signal_payload, 'research.execution_environment') !== 'live') {
                    continue;
                }
                if (! array_key_exists($trade->symbol, $quotes)) {
                    $quotes[$trade->symbol] = $prices->getLiveFeedSnapshot('binance_futures', $trade->symbol,
                        (int) config('quick_trade_research.max_quote_age_seconds', 5));
                }
                $quote = $quotes[$trade->symbol];
                $time = (int) ($quote['event_timestamp_ms'] ?? 0);
                $price = (float) ($quote['price'] ?? 0);
                if (! ($quote['fresh'] ?? false) || ($quote['market_type'] ?? '') !== 'futures'
                    || ! is_finite($price) || $price <= 0
                    || $time < $trade->entry_at->getTimestampMs() || $time > now()->getTimestampMs()
                    || now()->getTimestampMs() - $time > 5000) {
                    continue;
                }
                $count += DB::table('quick_trade_research_samples')->insertOrIgnore([
                    'quick_trade_id' => $trade->id, 'quote_time_ms' => $time, 'price' => $price,
                    'source' => substr((string) ($quote['source_key'] ?? 'cached_quote'), 0, 100), 'recorded_at' => now(),
                ]);
            } catch (\Throwable $e) {
                $this->warn('Observation unavailable for trade '.$trade->id);
            }
        }
        $this->info('Saved '.$count.' new quote observations.');

        // Phase 1 item 4 -- untraded WAIT shadows ride on this same existing
        // 5s scheduler entry (no new schedule): evaluate the ones whose
        // expiry has arrived from the same cached futures quote, then prune
        // by retention (throttled to hourly). No-op unless
        // quick_trade.shadow_untraded_waits is on and the table exists;
        // never throws.
        $shadows = app(\App\Domain\Trading\QuickTradeWaitShadowService::class);
        if ($shadows->enabled()) {
            $result = $shadows->evaluateDue($prices);
            $pruned = $shadows->pruneExpired();
            $this->info(sprintf('Untraded WAIT shadows: %d evaluated, %d missing expiry price, %d pruned.',
                $result['evaluated'], $result['missing'], $pruned));
        }

        // VNext Dynamic Decision Engine, Phase A -- multi-window forecast
        // audit hindsight evaluation rides this same existing 5s scheduler
        // entry (no new schedule). No-op unless the (currently un-migrated)
        // multi_window_forecast_audits table exists; never throws.
        $multiWindow = app(\App\Domain\Trading\MultiWindowForecastAuditService::class);
        if ($multiWindow->enabled()) {
            $mwResult = $multiWindow->evaluateDue($prices);
            $this->info(sprintf('Multi-window forecast audits: %d evaluated, %d missing expiry price, %d unavailable.',
                $mwResult['evaluated'], $mwResult['missing'], $mwResult['unavailable']));
        }

        return self::SUCCESS;
    }
}
