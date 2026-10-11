<?php

namespace Tests\Replay;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\RatioBotReplay\FakeBinanceSpot;
use Tests\Support\RatioBotReplay\ReplayAuditor;
use Tests\Support\RatioBotReplay\ReplayHarness;

/**
 * The long replay. Configured entirely by environment variables (see docs/ratio-bot-replay/PLAN.md):
 *   REPLAY_RUN_ID, REPLAY_SYMBOLS=BTCUSDT[,ETHUSDT], REPLAY_PROFILE=classic|staged, REPLAY_START=usdt|base,
 *   REPLAY_FEE=std|bnb, REPLAY_FROM=2024-10, REPLAY_TO=2026-09, REPLAY_CADENCE=300, REPLAY_RESUME=1,
 *   REPLAY_USDT=1000 (per symbol), DB_DATABASE=<sqlite file under ratio_bot_replay>
 * Results (machine readable) go to D:/autofinx-ml-v4/ratio_bot_replay/results/<run id>/.
 * Checkpoint at each month end; REPLAY_RESUME=1 continues after the last completed month.
 */
class RatioBotReplayRunTest extends ReplayTestCase
{
    public function test_replay(): void
    {
        $runId = getenv('REPLAY_RUN_ID');
        if (! $runId) {
            $this->markTestSkipped('REPLAY_RUN_ID not set: this is an on-demand dev tool.');
        }
        $symbols = explode(',', getenv('REPLAY_SYMBOLS') ?: 'BTCUSDT');
        $profile = getenv('REPLAY_PROFILE') ?: 'classic';
        $start = getenv('REPLAY_START') ?: 'usdt';
        $fee = getenv('REPLAY_FEE') ?: 'std';
        $from = getenv('REPLAY_FROM') ?: '2024-10';
        $to = getenv('REPLAY_TO') ?: '2026-09';
        $cadence = (int) (getenv('REPLAY_CADENCE') ?: 300);
        $usdt = (float) (getenv('REPLAY_USDT') ?: 1000);
        $resume = getenv('REPLAY_RESUME') === '1';
        $candles = 'D:/autofinx-ml-v4/ratio_bot_replay/candles';
        $outDir = 'D:/autofinx-ml-v4/ratio_bot_replay/results/' . $runId;
        @mkdir($outDir, 0777, true);
        $ckptFile = "{$outDir}/checkpoint.json";

        $months = [];
        for ($c = Carbon::parse($from . '-01', 'UTC'); $c->format('Y-m') <= $to; $c->addMonth()) {
            $months[] = $c->format('Y-m');
        }
        $ckpt = ($resume && is_file($ckptFile)) ? json_decode(file_get_contents($ckptFile), true) : null;
        if (! $ckpt) {
            $this->freshReplayDatabase();
        } else {
            DB::connection()->getPdo()->exec('PRAGMA journal_mode=WAL; PRAGMA synchronous=OFF;');
        }

        $profiles = array_fill_keys($symbols, $profile);
        $firstMs = Carbon::parse($months[0] . '-01', 'UTC')->getTimestampMs();

        // starting wallet, sized from the first available price
        $probe = new ReplayHarness($symbols, $profiles, [], $fee, $candles, $cadence);
        $probe->loadMonth(Carbon::parse($months[0] . '-01', 'UTC')->subMonth()->format('Y-m'));
        $balances = [];
        foreach ($symbols as $sym) {
            $px = $probe->bar($sym, $firstMs - 60000)[4];
            if ($start === 'usdt') {
                $balances['USDT'] = (string) (((float) ($balances['USDT'] ?? 0)) + $usdt);
            } else {
                $step = (new FakeBinanceSpot())->symbols[$sym]['step'];
                $units = (int) floor($usdt / $px * FakeBinanceSpot::SCALE);
                $units -= $units % $step;
                $balances[explode('USDT', $sym)[0]] = FakeBinanceSpot::fmt($units);
            }
        }
        unset($probe);
        if ($fee === 'bnb') {
            $balances['BNB'] = '1';
        }

        $h = new ReplayHarness($symbols, $profiles, $balances, $fee, $candles, $cadence);
        $h->boot($ckpt !== null, $ckpt['ids'] ?? []);
        if ($fee === 'bnb') {
            $h->exchange->bnbBookPrice = 600.0;
        }
        $aud = new ReplayAuditor($h, $outDir, $runId);
        $h->auditor = $aud;

        $doneThrough = null;
        if ($ckpt) {
            $h->exchange->restore($ckpt['exchange']);
            $aud->importState($ckpt['auditor']);
            $doneThrough = $ckpt['done_through'];
            $h->ticks = $ckpt['ticks'];
        }

        $meta = ['run_id' => $runId, 'symbols' => $symbols, 'profile' => $profile, 'start' => $start, 'fee' => $fee, 'from' => $from, 'to' => $to, 'cadence_s' => $cadence, 'start_balances' => $balances, 'config' => ReplayHarness::profileAttributes($profile, $fee)];
        file_put_contents("{$outDir}/meta.json", json_encode($meta, JSON_PRETTY_PRINT));

        $t0 = microtime(true);
        foreach ($months as $i => $ym) {
            if ($doneThrough !== null && $ym <= $doneThrough) {
                continue;
            }
            $mStart = Carbon::parse($ym . '-01', 'UTC');
            $mStartMs = $mStart->getTimestampMs();
            $nextStartMs = $mStart->copy()->addMonth()->getTimestampMs();
            $h->dropBarsBefore($mStartMs - 3 * 86400000);
            if (! $h->bar($symbols[0], $mStartMs - 60000)) {
                $h->loadMonth($mStart->copy()->subMonth()->format('Y-m')); // the first tick needs the previous month's last bar
            }
            $h->loadMonth($ym);
            $freshStart = ($ckpt === null && $i === 0);
            if ($freshStart) {
                $h->seedHistory($mStartMs + 300000, 150);
            } else {
                $h->pruneCandles($mStartMs - 600 * 300000);
            }
            $first = $freshStart ? $mStartMs + 300000 : $mStartMs;
            $last = $nextStartMs - $cadence * 1000;
            for ($t = $first; $t <= $last; $t += $cadence * 1000) {
                if ($t % ($cadence * 1000) !== 0) {
                    continue;
                }
                $h->tick($t);
            }
            $aud->flushMonth($ym);
            file_put_contents($ckptFile, json_encode([
                'done_through' => $ym, 'ids' => $h->ids(), 'exchange' => $h->exchange->snapshot(), 'auditor' => $aud->exportState(), 'ticks' => $h->ticks,
            ]));
            file_put_contents("{$outDir}/progress.txt", sprintf("%s done, ticks=%d, elapsed=%.0fs, peak_mem=%.0fMB, anomalies=%s\n", $ym, $h->ticks, microtime(true) - $t0, memory_get_peak_usage() / 1e6, json_encode($aud->anomalyCounts)), FILE_APPEND);
            gc_collect_cycles();
            if (memory_get_usage() > 1.3e9) {
                $this->fail('memory guard tripped at ' . $ym);
            }
        }

        file_put_contents("{$outDir}/result.json", json_encode([
            'meta' => $meta, 'summary' => $aud->summary(), 'monthly' => $aud->monthly, 'decision_stats' => $aud->decisionStats, 'ledger' => $aud->ledger,
            'anomalies' => $aud->anomalies, 'notes' => $aud->notes, 'harness_anomalies' => $h->anomalies, 'known_warning_swallowed_F1' => $h->knownWarningsSwallowed,
            'operator_resumes' => $h->operatorResumes, 'final_balances' => array_map(fn ($u) => FakeBinanceSpot::toFloat($u), $h->exchange->free),
            'exchange_calls' => array_count_values(array_map(fn ($c) => $c['method'] . ' ' . $c['path'], $h->exchange->calls)),
            'unhandled_endpoints' => array_values(array_unique($h->exchange->unhandled)), 'ticks' => $h->ticks, 'elapsed_s' => microtime(true) - $t0,
        ], JSON_PRETTY_PRINT));
        $h->shutdown();
        $this->assertTrue(true);
    }
}
