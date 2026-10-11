<?php

namespace Tests\Support\RatioBotReplay;

use App\Domain\Market\MarketPriceCacheService;
use App\Domain\MarketData\MarketSnapshotService;
use App\Domain\RatioBot\RatioBotStateService;
use App\Jobs\RunRatioBotEvaluationJob;
use App\Models\ExchangeConnection;
use App\Models\MarketOhlc;
use App\Models\RatioBotConfig;
use App\Models\RatioBotState;
use App\Models\Setting;
use App\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Drives the REAL Ratio Bot (RunRatioBotEvaluationJob -> RealtimeEngine ->
 * DecisionService -> ExecutionService -> LiveOrderIntentService ->
 * BinanceSpotClient -> ReconciliationService -> StateService/LotService) over
 * historical 1m candles, against FakeBinanceSpot, in simulated time.
 *
 * Only the two clock-dependent price-source services are swapped for fakes
 * (see ReplayFeed). Everything that decides or executes is production code.
 */
class ReplayHarness
{
    public FakeBinanceSpot $exchange;
    public ReplayFeed $feed;
    public Workspace $workspace;
    public ExchangeConnection $connection;
    /** @var array<string, RatioBotConfig> */
    public array $configs = [];

    /** @var array<string, array<int, array{0:int,1:float,2:float,3:float,4:float,5:float}>> 1m rows by symbol, keyed by open_time ms */
    private array $bars = [];
    private array $bnbBars = [];

    /** Count of the one known production warning we step over so the rest of the order path can be audited (finding F1). */
    public int $knownWarningsSwallowed = 0;
    private mixed $previousErrorHandler = null;

    public int $ticks = 0;
    public int $tickErrors = 0;
    /** @var list<array> */
    public array $anomalies = [];
    /** @var array<int, callable(self, int): void> fault hooks keyed by tick time ms (run just before evaluation) */
    public array $hooks = [];
    /** symbol => feed healthy for this tick? set by hooks */
    public array $feedGap = [];
    /** operator "Resume" after a bot blocks itself, in ticks (0 = never) */
    public int $autoResumeAfterTicks = 12;
    public int $operatorResumes = 0;
    /** @var array<string, int> symbol => tick index when it was first seen blocked */
    private array $blockedSince = [];
    public ?ReplayAuditor $auditor = null;
    public int $nowMs = 0;

    /**
     * @param list<string> $symbols
     * @param array<string,string> $startBalances
     * @param array<string,string> $profiles symbol => profile name
     */
    public function __construct(
        public readonly array $symbols,
        public readonly array $profiles,
        public readonly array $startBalances,
        public readonly string $feeMode,
        public readonly string $candleDir,
        public readonly int $cadenceSeconds = 300,
        public readonly string $evaluationMode = 'polling',
        public readonly array $configOverrides = [],
    ) {
    }

    // ------------------------------------------------------------------ setup

    public static function assertIsolatedDatabase(): void
    {
        $conn = config('database.default');
        $db = (string) config("database.connections.{$conn}.database");
        if ($conn !== 'sqlite' || ($db !== ':memory:' && ! str_contains(str_replace(chr(92), '/', $db), 'ratio_bot_replay'))) {
            throw new \RuntimeException("REFUSING TO RUN: replay must use an isolated sqlite DB, got {$conn}:{$db}");
        }
    }

    public function boot(bool $resumeIds = false, array $ids = []): void
    {
        self::assertIsolatedDatabase();

        // FINDING F1: LiveOrderIntentService::place() reads `$integration->provider_key` while $integration is null
        // for every connection_id-routed order (what RatioBotExecutionService sends). Laravel turns that E_WARNING
        // into an ErrorException, so the order never reaches the exchange. We step over exactly that one warning
        // (counted, reported) and let every other warning/error through untouched.
        $this->previousErrorHandler = set_error_handler(null);
        set_error_handler(function (int $level, string $message, string $file, int $line) {
            if ($level === E_WARNING && str_contains($message, 'provider_key') && str_ends_with(str_replace(chr(92), '/', $file), 'Domain/Trading/LiveOrderIntentService.php')) {
                $this->knownWarningsSwallowed++;

                return true;
            }

            return is_callable($this->previousErrorHandler) ? ($this->previousErrorHandler)($level, $message, $file, $line) : false;
        });

        $this->exchange = new FakeBinanceSpot();
        $this->feed = new ReplayFeed();
        app()->instance(MarketSnapshotService::class, new ReplaySnapshotService($this->feed));
        app()->instance(MarketPriceCacheService::class, new ReplayPriceCache($this->feed));
        config([
            'services.live_trading_enabled' => true,
            'services.binance.api_url' => 'https://api.binance.com',
            'ratio_bot_outcomes.evaluate_in_loop' => false,
        ]);
        $this->exchange->feeMode = $this->feeMode;
        $this->exchange->install();

        if ($resumeIds) {
            $this->workspace = Workspace::findOrFail($ids['workspace_id']);
            $this->connection = ExchangeConnection::findOrFail($ids['connection_id']);
            foreach ($this->symbols as $sym) {
                $this->configs[$sym] = RatioBotConfig::findOrFail($ids['configs'][$sym]);
            }

            return;
        }

        foreach ($this->startBalances as $asset => $amount) {
            $this->exchange->setFree($asset, $amount);
        }

        $this->workspace = Workspace::factory()->create();
        foreach (['trading_mode' => 'live', 'live_enabled' => true] as $key => $value) {
            Setting::create(['id' => (string) Str::uuid(), 'workspace_id' => $this->workspace->id, 'group' => 'trading', 'key' => $key, 'value_json' => $value]);
        }
        $this->connection = ExchangeConnection::create([
            'workspace_id' => $this->workspace->id, 'name' => 'Replay', 'provider' => 'binance', 'exchange' => 'binance',
            'is_active' => true, 'is_enabled' => true, 'is_testnet' => false,
            'api_key_encrypted' => Crypt::encryptString('replay-key'), 'api_secret_encrypted' => Crypt::encryptString('replay-secret'),
        ]);

        foreach ($this->symbols as $sym) {
            $this->configs[$sym] = RatioBotConfig::create(array_merge([
                'workspace_id' => $this->workspace->id, 'symbol' => $sym, 'name' => 'Replay ' . $sym,
                'market_type' => 'spot', 'exchange' => 'binance', 'execution_mode' => 'live', 'mode' => 'restricted_auto',
                'enabled' => true, 'connection_id' => $this->connection->id, 'evaluation_mode' => $this->evaluationMode,
                'poll_interval_seconds' => $this->cadenceSeconds, 'timeframe' => '5m',
            ], self::profileAttributes($this->profiles[$sym], $this->feeMode), $this->configOverrides));
            RatioBotState::create(['ratio_bot_config_id' => $this->configs[$sym]->id, 'position_state' => RatioBotState::STATE_IDLE, 'feed_status' => 'healthy']);
        }
    }

    public function ids(): array
    {
        return [
            'workspace_id' => (string) $this->workspace->id, 'connection_id' => (string) $this->connection->id,
            'configs' => array_map(fn ($c) => (string) $c->id, $this->configs),
        ];
    }

    public function shutdown(): void
    {
        restore_error_handler(); // ours
        if ($this->previousErrorHandler !== null) {
            set_error_handler($this->previousErrorHandler);
        }
        Carbon::setTestNow();
    }

    /** Documented profiles. Percent fields > 1 are percent, <= 1 are fractions (see RatioBotDecisionService::ratioFromConfigPct). */
    public static function profileAttributes(string $profile, string $feeMode): array
    {
        $fee = $feeMode === 'bnb' ? 7.5 : 10;
        $common = [
            'fee_bps' => $fee, 'max_slippage_bps' => 5, 'min_net_profit_bps' => 5, 'cooldown_seconds_after_trade' => 300,
            'base_quote_amount' => 1000, 'max_entry_price_drift_bps' => 50, 'stale_feed_timeout_seconds' => 30,
            'trigger_confirm_updates' => 1, 'trigger_confirm_seconds' => 0, 'max_spike_deviation_bps' => 0,
            'max_trades_per_hour' => 0, 'max_trades_per_day' => 0,
        ];

        return match ($profile) {
            // Plain threshold ladder: buy after a 1.2% drop from the last sell, sell after +1.2%, 5% hard stop.
            'classic' => $common + [
                'algorithm' => 'ratio_threshold', 'v2_enabled' => false,
                'sell_threshold_ratio' => 0.012, 'buy_threshold_ratio' => -0.012,
                'stop_loss_pct' => 5, 'take_profit_pct' => 0,
            ],
            // V2 stage plan: 3 buy stages, 3 sell stages, reversal confirmation, FIFO lot profit guard.
            'staged' => $common + [
                'algorithm' => 'ratio_threshold', 'v2_enabled' => true,
                'sell_threshold_ratio' => 0.012, 'buy_threshold_ratio' => -0.012,
                'stage_buy_allocations' => [50, 25, 25], 'stage_buy_drops' => [1.5, 1.5, 2.0],
                'stage_sell_allocations' => [25, 25, 50], 'stage_sell_rises' => [1.2, 1.2, 2.0],
                'selling_loss_ratio' => 0.002, 'buying_loss_ratio' => 0.002,
                'strategy_reserve_pct' => 0, 'stop_loss_pct' => 8, 'take_profit_pct' => 0,
            ],
            default => throw new \InvalidArgumentException("unknown profile {$profile}"),
        };
    }

    // ------------------------------------------------------------------ data

    public function loadMonth(string $ym): void
    {
        foreach ($this->symbols as $sym) {
            foreach ($this->readCsv("{$this->candleDir}/{$sym}-{$ym}.csv") as $r) {
                $this->bars[$sym][$r[0]] = $r;
            }
        }
        if ($this->feeMode === 'bnb') {
            foreach ($this->readCsv("{$this->candleDir}/BNBUSDT-{$ym}.csv") as $r) {
                $this->bnbBars[$r[0]] = $r[4];
            }
        }
    }

    /** Replace/insert synthetic 1m bars (open_time_ms => [t,o,h,l,c,v]) for scenario tests. */
    public function setBars(string $symbol, array $rows): void
    {
        foreach ($rows as $r) {
            $this->bars[$symbol][$r[0]] = $r;
        }
    }

    public function removeBars(string $symbol, int $fromMs, int $toMs): void
    {
        for ($t = $fromMs; $t < $toMs; $t += 60000) {
            unset($this->bars[$symbol][$t]);
        }
    }

    private function readCsv(string $path): array
    {
        $out = [];
        $h = fopen($path, 'r');
        while (($line = fgets($h)) !== false) {
            $p = explode(',', trim($line));
            $out[] = [(int) $p[0], (float) $p[1], (float) $p[2], (float) $p[3], (float) $p[4], (float) $p[5]];
        }
        fclose($h);

        return $out;
    }

    public function dropBarsBefore(int $ms): void
    {
        foreach ($this->bars as $sym => $rows) {
            foreach ($rows as $k => $_) {
                if ($k >= $ms) {
                    break;
                }
                unset($this->bars[$sym][$k]);
            }
        }
    }

    public function bar(string $symbol, int $openMs): ?array
    {
        return $this->bars[$symbol][$openMs] ?? null;
    }

    /** Seed already-closed 5m candles ending just before $endMs so indicators have history before the first tick. */
    public function seedHistory(int $endMs, int $count = 200): void
    {
        foreach ($this->symbols as $sym) {
            for ($i = $count; $i >= 2; $i--) {
                $this->insertCandle($sym, $endMs - $i * 300000);
            }
        }
    }

    public function insertCandle(string $sym, int $open5mMs): void
    {
        $o = $h = $l = $c = $v = null;
        for ($k = 0; $k < 5; $k++) {
            $b = $this->bars[$sym][$open5mMs + $k * 60000] ?? null;
            if (! $b) {
                continue;
            }
            $o ??= $b[1];
            $h = max($h ?? $b[2], $b[2]);
            $l = min($l ?? $b[3], $b[3]);
            $c = $b[4];
            $v = ($v ?? 0) + $b[5];
        }
        if ($o === null) {
            return;
        }
        MarketOhlc::create([
            'id' => (string) Str::uuid(), 'exchange' => 'binance', 'symbol' => $sym, 'timeframe' => '5m',
            'ts' => Carbon::createFromTimestampMs($open5mMs, 'UTC'), 'open' => $o, 'high' => $h, 'low' => $l, 'close' => $c, 'volume' => $v,
        ]);
    }

    public function pruneCandles(int $keepFromMs): void
    {
        MarketOhlc::query()->where('ts', '<', Carbon::createFromTimestampMs($keepFromMs, 'UTC'))->delete();
    }

    // ------------------------------------------------------------------ the replay loop

    /** Run one evaluation tick at simulated time $tMs (the instant the 1m bar opening at tMs-60s has just closed). */
    public function tick(int $tMs): void
    {
        Carbon::setTestNow(Carbon::createFromTimestampMs($tMs, 'UTC'));
        $this->nowMs = $tMs;
        $this->exchange->nowMs = $tMs;
        $this->feed->nowMs = $tMs;

        $live = [];
        foreach ($this->symbols as $sym) {
            $closed = $this->bars[$sym][$tMs - 60000] ?? null;
            $next = $this->bars[$sym][$tMs] ?? null;
            if ($closed === null) {
                continue; // data gap: no new price for this symbol this tick
            }
            $this->exchange->lastPrice[$sym] = $closed[4];
            $this->exchange->nextOpen[$sym] = $next[1] ?? $closed[4];
            $this->exchange->nextRange[$sym] = [$next[2] ?? $closed[4], $next[3] ?? $closed[4]];
            if (empty($this->feedGap[$sym])) {
                $this->feed->tick($sym, $closed[4], $tMs);
            }
            if ($tMs % 300000 === 0) {
                $this->insertCandle($sym, $tMs - 300000);
            }
            $live[] = $sym;
        }
        if ($this->feeMode === 'bnb') {
            $this->exchange->bnbPrice = $this->bnbBars[$tMs - 60000] ?? $this->exchange->bnbPrice;
        }

        if (isset($this->hooks[$tMs])) {
            ($this->hooks[$tMs])($this, $tMs);
        }

        $this->auditor?->beforeTick($tMs);
        foreach ($this->symbols as $sym) {
            $this->evaluate($sym);
        }
        $this->ticks++;
        $this->auditor?->afterTick($tMs);
        $this->operatorResumeIfBlocked();
    }

    public function evaluate(string $sym): void
    {
        try {
            $job = new RunRatioBotEvaluationJob((string) $this->configs[$sym]->id);
            app()->call([$job, 'handle']);
        } catch (\Throwable $e) {
            $this->tickErrors++;
            $this->anomalies[] = ['type' => 'tick_exception', 'symbol' => $sym, 'at' => now()->toIso8601String(), 'error' => get_class($e) . ': ' . $e->getMessage(), 'where' => basename($e->getFile()) . ':' . $e->getLine()];
        }
    }

    /** Simulates the operator pressing "Resume" some time after the bot blocked itself (production resume path). */
    private function operatorResumeIfBlocked(): void
    {
        if ($this->autoResumeAfterTicks <= 0) {
            return;
        }
        foreach ($this->symbols as $sym) {
            $state = RatioBotState::where('ratio_bot_config_id', $this->configs[$sym]->id)->first();
            $blocked = $state && in_array($state->position_state, [RatioBotState::STATE_BLOCKED, RatioBotState::STATE_PAUSED], true);
            if (! $blocked) {
                unset($this->blockedSince[$sym]);
                continue;
            }
            $this->blockedSince[$sym] ??= $this->ticks;
            if ($this->ticks - $this->blockedSince[$sym] >= $this->autoResumeAfterTicks) {
                $cfg = $this->configs[$sym]->fresh();
                $this->auditor?->note('operator_resume', $sym, ['was' => $state->position_state, 'code' => $state->block_reason_code, 'detail' => mb_substr((string) $state->block_reason_detail, 0, 200)]);
                app(RatioBotStateService::class)->resume($cfg);
                $this->operatorResumes++;
                unset($this->blockedSince[$sym]);
            }
        }
    }
}
