<?php

namespace App\Console\Commands;

use App\Domain\Market\FutureTradeLiveFeedManager;
use Illuminate\Console\Command;
use App\Domain\Ops\TradingRuntimeHealthService;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Throwable;

class RuntimeRepairCommand extends Command
{
    protected $signature = 'runtime:repair
        {--attempts=1 : Number of repair attempts}
        {--sleep=3 : Seconds between attempts}
        {--no-script : Use Artisan fallback instead of the shell script}';

    protected $description = 'Check and repair AutoFinX runtime workers, queues, market feed, and robot evaluator.';

    public function handle(): int
    {
        $attempts = max(1, (int) $this->option('attempts'));
        $sleep = max(1, (int) $this->option('sleep'));
        $script = base_path('scripts/robot-runtime-doctor.sh');

        if (! (bool) $this->option('no-script') && is_file($script)) {
            @chmod($script, 0755);
            $process = new Process(['bash', $script, '--repair', '--attempts='.$attempts, '--sleep='.$sleep], base_path(), null, null, 120);
            $process->run(function ($type, $buffer): void {
                $this->output->write($buffer);
            });

            return $process->isSuccessful() ? self::SUCCESS : self::FAILURE;
        }

        $failed = false;
        $health = app(TradingRuntimeHealthService::class)->snapshot();
        $liveFeedState = (string) ($health['future_trade']['live_feed'] ?? 'red');
        // Ratio Bot's own spot feed (`market:ws-consume --market-type=spot`)
        // is a separate daemon from the shared futures feed above and can go
        // stale independently — a bot stuck with no automated recovery was
        // traced to this key not existing at all, so RuntimeRepairCommand
        // never restarted the spot-side workers for a spot-only staleness.
        $ratioBotLiveFeedState = (string) ($health['ratio_bot']['live_feed'] ?? 'red');
        $anyLiveFeedUnhealthy = $liveFeedState !== 'green' || $ratioBotLiveFeedState !== 'green';

        if ($liveFeedState !== 'green') {
            // Quick Trade's live Binance path reads `binance_futures` from
            // the same shared feed as Future Trade. Restart that actual
            // owner first; restarting only Ratio Bot workers and then doing
            // a one-shot SPOT fetch cannot make a futures quote fresh.
            try {
                $this->line('$ restart shared futures live feed');
                $feed = app(FutureTradeLiveFeedManager::class)->restart();
                if (! (bool) ($feed['feedFresh'] ?? false)) {
                    $this->warn('Shared futures feed restart requested, awaiting a fresh tick.');
                }
            } catch (Throwable $e) {
                $failed = true;
                $this->error('Shared futures feed restart failed: '.$e->getMessage());
            }
        }

        // autofinx-ratio-feed/autofinx-queue-ratio serve Ratio Bot's spot
        // feed and its evaluation queue — restart them for EITHER a stale
        // futures feed (the shared restart above may not touch these spot
        // workers) or a stale spot feed (ratio_bot.live_feed), not only the
        // futures case.
        if ((bool) config('autofinx.runtime.supervisor', false) && $anyLiveFeedUnhealthy) {
            foreach ([
                'autofinx-ratio-feed',
                'autofinx-queue-ratio',
            ] as $program) {
                try {
                    $this->line('$ supervisorctl restart '.$program);
                    $process = new Process(['supervisorctl', 'restart', $program], base_path(), null, null, 30);
                    $process->run(function ($type, $buffer): void {
                        $this->output->write($buffer);
                    });
                    if (! $process->isSuccessful()) {
                        $failed = true;
                        $this->error('supervisor restart '.$program.' returned exit code '.$process->getExitCode());
                    }
                } catch (Throwable $e) {
                    $failed = true;
                    $this->error('supervisor restart '.$program.' failed: '.$e->getMessage());
                }
            }
        } elseif ((bool) config('autofinx.runtime.supervisor', false)) {
            $this->line('Runtime live feed is green; supervisor ratio daemon restart skipped.');
        }

        $commands = [
            ['queue:restart', []],
            ['future-trade:scan-all', []],
            ['robot-sessions:evaluate', ['--limit' => 50]],
            ['future-trade:runtime-status', []],
        ];
        if ($liveFeedState !== 'green') {
            // A bounded fallback improves diagnostics/recovery when no
            // daemon manager is available. It must match the exchange
            // namespace checked by QuickTradeService::assertFreshLiveFeedForEntry().
            $commands[] = ['market:ws-consume', ['--once' => true, '--market-type' => 'futures', '--store-ohlc' => true, '--no-dispatch' => true]];
        }
        if ($ratioBotLiveFeedState !== 'green') {
            // Same bounded diagnostic fallback as the futures branch above,
            // scoped to spot — never fires for a futures-only staleness so a
            // healthy spot feed isn't churned for an unrelated futures issue.
            $commands[] = ['market:ws-consume', ['--once' => true, '--market-type' => 'spot', '--store-ohlc' => true, '--no-dispatch' => true]];
        }

        foreach ($commands as [$command, $args]) {
            try {
                $this->line('$ php artisan '.$command);
                Artisan::call($command, $args);
                $this->output->write(Artisan::output());
            } catch (Throwable $e) {
                $failed = true;
                $this->error($command.' failed: '.$e->getMessage());
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
