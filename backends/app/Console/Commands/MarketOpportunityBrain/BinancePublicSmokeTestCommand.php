<?php

namespace App\Console\Commands\MarketOpportunityBrain;

use App\Domain\MarketOpportunityBrain\Adapters\BinancePublicSnapshotProvider;
use App\Domain\MarketOpportunityBrain\Adapters\BinancePublicUniverseProvider;
use Illuminate\Console\Command;

/**
 * OPT-IN real-network smoke test (MASTER_SPEC.md Phase 2 gate: "Offline mocked tests + opt-in
 * real market smoke, not continuous production polling"). NOT registered on any schedule, NOT
 * called by any job or route. Must be run manually, and still refuses to do anything unless the
 * module's own config flags are explicitly enabled for the invocation (see README output below).
 *
 *   AUTOFINX_MOB_PUBLIC_DATA_ENABLED=true AUTOFINX_MOB_MAX_API_WEIGHT_PER_MINUTE=1200 \
 *   AUTOFINX_MOB_MAX_DISCOVERY_SYMBOLS=10 php artisan mob:binance-smoke-test
 */
class BinancePublicSmokeTestCommand extends Command
{
    protected $signature = 'mob:binance-smoke-test {--symbol=BTCUSDT}';

    protected $description = 'RESEARCH ONLY, opt-in: one real read-only call to Binance public futures REST to prove the Phase 2 adapter against live data. Never places an order.';

    public function handle(): int
    {
        if (! config('market_opportunity_brain.public_data_enabled')) {
            $this->error('market_opportunity_brain.public_data_enabled is false. Re-run with AUTOFINX_MOB_PUBLIC_DATA_ENABLED=true (and the weight/symbol-cap env vars) to actually hit the network.');

            return self::FAILURE;
        }

        $symbol = strtoupper((string) $this->option('symbol'));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $this->info('--- Universe scan (BinancePublicUniverseProvider) ---');
        $universe = new BinancePublicUniverseProvider();
        $records = iterator_to_array($universe->universeAsOf($now), false);
        $eligible = array_values(array_filter($records, fn ($r) => $r->eligibleForAnalysis));
        $this->line('Total symbols seen: '.count($records));
        $this->line('Eligible (ranked) candidates: '.count($eligible));
        foreach (array_slice($eligible, 0, 5) as $r) {
            $this->line(sprintf('  #%s %s score=%.4f vol_evidence_reason=%s', $r->rank, $r->symbol, $r->opportunityScore ?? -1, implode(',', $r->reasonCodes)));
        }

        $this->info("--- Candles (BinancePublicSnapshotProvider) for {$symbol} ---");
        $snapshot = new BinancePublicSnapshotProvider();
        $candles = $snapshot->closedCandles($symbol, '15m', $now, 5);
        foreach ($candles as $c) {
            $this->line(sprintf('  %s -> %s open=%.4f close=%.4f vol=%.2f', $c->openTimeUtc, $c->closeTimeUtc, $c->open, $c->close, $c->volume));
        }

        $this->info('Smoke test complete. execution_authorized is always false; no order was placed.');

        return self::SUCCESS;
    }
}
