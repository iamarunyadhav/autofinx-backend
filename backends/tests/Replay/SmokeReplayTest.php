<?php

namespace Tests\Replay;

use Tests\Support\RatioBotReplay\ReplayHarness;

class SmokeReplayTest extends ReplayTestCase
{
    public function test_smoke(): void
    {
        $this->freshReplayDatabase();
        $h = new ReplayHarness('BTCUSDT', 'classic', ['USDT' => '1000'], 'std', 'D:/autofinx-ml-v4/ratio_bot_replay/candles');
        $h->loadMonth('2024-09');
        $h->loadMonth('2024-10');
        $h->boot();
        $start = strtotime('2024-10-01 00:00:00 UTC') * 1000;
        $h->seedHistory($start, 120);
        $t0 = microtime(true);
        $n = 0;
        for ($t = $start; $t < $start + 86400000 * 3; $t += 300000) {
            $h->tick($t);
            $n++;
        }
        $dt = microtime(true) - $t0;
        fwrite(STDERR, sprintf("\nticks=%d time=%.2fs (%.1f ms/tick) errors=%d mem=%.0fMB\n", $n, $dt, $dt / $n * 1000, $h->tickErrors, memory_get_peak_usage() / 1e6));
        foreach (array_slice($h->anomalies, 0, 5) as $a) {
            fwrite(STDERR, json_encode($a) . "\n");
        }
        fwrite(STDERR, json_encode($h->exchange->free) . ' trades=' . count($h->exchange->trades) . "\n");
        $state = $h->config->fresh('state')->state;
        fwrite(STDERR, $state->position_state . ' ' . $state->block_reason_code . ' ' . $state->block_reason_detail . "\n");
        foreach (\App\Models\RatioBotDecision::orderBy('decided_at')->limit(12)->get() as $d) {
            fwrite(STDERR, $d->decided_at . ' ' . $d->decision . ' ' . $d->execution_status . ' @' . $d->evaluated_price . ' | ' . substr((string) $d->reason, 0, 160) . "
");
        }
        foreach (\App\Models\RatioBotEvent::orderBy('occurred_at')->limit(15)->get() as $e) {
            fwrite(STDERR, 'EVT ' . $e->event_type . ' ' . substr(json_encode($e->context), 0, 300) . "
");
        }
        fwrite(STDERR, json_encode(array_slice($h->exchange->rejections, 0, 5)) . "
");
        fwrite(STDERR, json_encode(array_count_values(array_map(fn ($c) => $c['method'] . ' ' . $c['path'], $h->exchange->calls))) . "
");
        foreach (['orders', 'order_fills', 'ratio_bot_lots', 'ratio_bot_states', 'trades', 'positions', 'wallet_ledger', 'ledger_entries'] as $tbl) {
            if (! \Illuminate\Support\Facades\Schema::hasTable($tbl)) { fwrite(STDERR, "NO TABLE $tbl
"); continue; }
            foreach (\Illuminate\Support\Facades\DB::table($tbl)->limit(3)->get() as $row) {
                $a = (array) $row; foreach ($a as $k => $v) { if (is_string($v) && strlen($v) > 120) $a[$k] = substr($v, 0, 120) . '...'; if ($v === null) unset($a[$k]); }
                fwrite(STDERR, "$tbl: " . json_encode($a) . "
");
            }
        }
        $this->assertTrue(true);
    }
}
