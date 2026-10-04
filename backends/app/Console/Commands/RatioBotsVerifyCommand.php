<?php

namespace App\Console\Commands;

use App\Casts\UtcDateTime;
use App\Domain\Exchange\ExchangeClientFactory;
use App\Domain\Ops\SafeLiveGate;
use App\Models\ExchangeConnection;
use App\Models\MarketFeedStatus;
use App\Models\MarketOhlc;
use App\Models\Notification;
use App\Models\Order;
use App\Models\RatioBotConfig;
use App\Models\RatioBotDecision;
use App\Models\RatioBotState;
use App\Models\SchedulerRun;
use App\Support\Database\TimestampAutoUpdateRepair;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Read-only production health check for Ratio Bots. It never writes to the
 * database and never places, cancels or changes an order; --exchange only
 * reads account balances. Exit code 1 when any check FAILs, so it can be
 * scripted after a deploy.
 */
class RatioBotsVerifyCommand extends Command
{
    protected $signature = 'ratio-bots:verify
        {--bot= : Check one ratio bot config id}
        {--exchange : Also compare live bot holdings with Binance balances (read-only API call)}';

    protected $description = 'Read-only health check of Ratio Bots: runtime, feeds, candles, live gate, orders and state.';

    private const PASS = 'PASS';

    private const WARN = 'WARN';

    private const FAIL = 'FAIL';

    /** @var array<string, int> */
    private array $totals = [self::PASS => 0, self::WARN => 0, self::FAIL => 0];

    public function handle(SafeLiveGate $gate, ExchangeClientFactory $clients): int
    {
        $this->line('Ratio Bot verify @ '.now()->toDateTimeString().' (app timezone '.config('app.timezone').')');

        $this->section('Platform');
        $this->platformChecks();

        $bots = RatioBotConfig::query()->with('state')
            ->when($this->option('bot'), fn ($query, $id) => $query->whereKey($id))
            ->orderBy('name')->get();
        if ($bots->isEmpty()) {
            $this->report(self::WARN, 'No ratio bots found.');
        }

        foreach ($bots as $bot) {
            $this->section(sprintf('%s | %s %s | %s / %s | %s', $bot->name, $bot->normalizedSymbol(), $bot->marketType(),
                $bot->execution_mode, $bot->mode, $bot->enabled ? 'enabled' : 'DISABLED'));
            $this->botChecks($bot, $gate, $clients);
        }

        $this->newLine();
        $this->line(sprintf('Summary: %d PASS, %d WARN, %d FAIL', $this->totals[self::PASS], $this->totals[self::WARN], $this->totals[self::FAIL]));

        return $this->totals[self::FAIL] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function platformChecks(): void
    {
        $castOk = (new MarketOhlc())->getCasts()['ts'] ?? null;
        $this->report($castOk === UtcDateTime::class ? self::PASS : self::FAIL,
            'Timezone fixes deployed (market_ohlc.ts read as UTC).', $castOk === UtcDateTime::class ? null : 'Pull and deploy the latest backend.');

        if (TimestampAutoUpdateRepair::supported()) {
            $autoUpdate = collect(TimestampAutoUpdateRepair::columns())->map(fn ($c) => "{$c->TABLE_NAME}.{$c->COLUMN_NAME}");
            $this->report($autoUpdate->isEmpty() ? self::PASS : self::FAIL, 'No TIMESTAMP columns rewritten on UPDATE',
                $autoUpdate->isEmpty() ? null : $autoUpdate->count().' found ('.$autoUpdate->take(5)->implode(', ').'): run php artisan migrate');
        }

        foreach (['ratio-bots:evaluate' => 180, 'ratio-bots:reconcile' => 900] as $command => $maxAge) {
            $run = SchedulerRun::query()->where('command', $command)->orderByDesc('started_at')->first();
            $age = $run?->started_at ? (int) $run->started_at->diffInSeconds(now(), true) : null;
            $this->report($age !== null && $age <= $maxAge ? self::PASS : self::FAIL,
                "Scheduler ran {$command}", $age === null ? 'never recorded: is the scheduler (cron / schedule:work) running?' : "{$age}s ago (max {$maxAge}s)");
        }

        $alerts = Notification::query()->where('type', 'ratio_bot_alert')->where('created_at', '>=', now()->subDay())->count();
        $this->report($alerts === 0 ? self::PASS : self::WARN, 'Ratio Bot alerts in the last 24h', (string) $alerts);
    }

    private function botChecks(RatioBotConfig $bot, SafeLiveGate $gate, ExchangeClientFactory $clients): void
    {
        $state = $bot->state;
        if (! $state) {
            $this->report(self::FAIL, 'State row', 'missing');

            return;
        }
        $live = $bot->isLiveMode();

        // 1. Runtime state.
        $stuck = $state->position_state === RatioBotState::STATE_AWAITING_EXECUTION
            && ($state->awaiting_since ?? $state->updated_at)?->lt(now()->subMinutes(3));
        $bad = in_array($state->position_state, [RatioBotState::STATE_BLOCKED, RatioBotState::STATE_ERROR_REVIEW], true) || $stuck;
        $this->report($bad ? self::FAIL : ($bot->enabled ? self::PASS : self::WARN), 'State',
            trim($state->position_state.' '.($state->block_reason_code ? "({$state->block_reason_code}: ".mb_strimwidth((string) $state->block_reason_detail, 0, 90, '...').')' : '')
                .($stuck ? ' stuck awaiting > 3 min' : '').' failures='.(int) $state->consecutive_order_failures));

        if (! $bot->enabled) {
            $this->line('    (disabled: freshness checks below are informational)');
        }
        $severity = $bot->enabled ? self::FAIL : self::WARN;

        // 2. Is the evaluator actually running this bot (queue worker + scheduler/feed)?
        $evalAge = $state->last_evaluated_at ? (int) $state->last_evaluated_at->diffInSeconds(now(), true) : null;
        $this->report($evalAge !== null && $evalAge <= 180 ? self::PASS : $severity, 'Last evaluation',
            $evalAge === null ? 'never' : "{$evalAge}s ago".($evalAge > 180 && $bot->enabled ? ': check the ratio_bot queue worker' : ''));

        // 3. Timestamp of the price the bot holds. A ~19800s age is the old timezone bug.
        $priceAge = $state->last_price_timestamp ? (int) $state->last_price_timestamp->diffInSeconds(now(), true) : null;
        $window = $bot->staleFeedWindowSeconds();
        $this->report($priceAge !== null && $priceAge <= max($window, 120) ? self::PASS : $severity, 'Bot price timestamp age',
            $priceAge === null ? 'never' : "{$priceAge}s (stale window {$window}s)".($priceAge >= 19000 && $priceAge <= 20600 ? ' <- looks like the 5.5h timezone bug' : ''));

        // 4. Real exchange feed for this symbol (what the live gate checks).
        $feed = MarketFeedStatus::query()->where('workspace_id', $bot->workspace_id)->where('symbol', $bot->normalizedSymbol())
            ->where('market_type', $bot->marketType())->whereNotNull('last_tick_at')->orderByDesc('last_tick_at')->first();
        $feedAge = $feed?->last_tick_at ? (int) $feed->last_tick_at->diffInSeconds(now(), true) : null;
        $this->report($feedAge !== null && $feedAge <= 60 ? self::PASS : $severity, 'Websocket feed (market_feed_status)',
            $feedAge === null ? 'no row: is the market feed worker running for this symbol?' : "{$feedAge}s old via {$feed->exchange}/{$feed->market_type}");

        // 5. Closed candles for indicators / brain.
        $timeframe = $bot->timeFrame();
        $tfSeconds = $this->timeframeSeconds($timeframe);
        $series = fn () => MarketOhlc::query()->where('exchange', $bot->exchangeKey())->where('symbol', $bot->normalizedSymbol())
            ->where('timeframe', $timeframe);
        // ts is UTC wall time, so the cutoff is bound in UTC. A candle cannot
        // open in the future: such rows are corrupted (MySQL ON UPDATE
        // CURRENT_TIMESTAMP rewrote their open time) and sort ahead of every
        // real candle in "latest N" reads.
        $futureCutoff = now('UTC')->addMinute();
        $future = $series()->where('ts', '>', $futureCutoff)->count();
        $candle = $series()->where('ts', '<=', $futureCutoff)->orderByDesc('ts')->first();
        $candleCount = $series()->count();
        $candleAge = $candle?->ts ? (int) $candle->ts->diffInSeconds(now(), true) : null;
        $candlesOk = $candleAge !== null && $candleAge <= $tfSeconds * 3 && $candleCount >= 30;
        $this->report($candlesOk ? self::PASS : self::WARN, "Stored {$timeframe} candles ({$bot->exchangeKey()})",
            $candleAge === null ? 'none: indicators/brain have no history (run the feed with --store-ohlc or market:ohlc-backfill)'
                : "{$candleCount} rows, newest opened {$candleAge}s ago");
        $this->report($future === 0 ? self::PASS : self::FAIL, 'Candles with a future open time',
            $future === 0 ? 'none' : "{$future} corrupted rows; they are read as the newest candles");

        // 6. Live trading gate, exactly as an order would hit it (read-only).
        if ($live && $bot->marketType() === 'spot') {
            try {
                $gate->ensureLiveAllowed((string) $bot->workspace_id, $bot->normalizedSymbol(), 0);
                $this->report(self::PASS, 'Live trading gate', 'an order would pass');
            } catch (Throwable $e) {
                $this->report($severity, 'Live trading gate', 'BLOCKED: '.$e->getMessage());
            }
        }

        // 7. Orders this bot could not resolve.
        $uncertain = Order::query()->where('workspace_id', $bot->workspace_id)->where('meta->ratio_bot_config_id', (string) $bot->id)
            ->where(fn ($query) => $query->where('status', Order::STATUS_UNCERTAIN)
                ->orWhereIn('reconciliation_state', [Order::RECONCILIATION_UNCERTAIN, Order::RECONCILIATION_MISMATCH]))
            ->where('created_at', '>=', now()->subDay())->count();
        $this->report($uncertain === 0 ? self::PASS : self::FAIL, 'Uncertain/mismatched orders (24h)', (string) $uncertain);

        // 8. Double-booked lots (one buy decision -> several lots).
        $duplicates = DB::table('ratio_bot_lots')->where('ratio_bot_config_id', $bot->id)->whereNotNull('buy_decision_id')
            ->select('buy_decision_id', DB::raw('MAX(created_at) as latest'))->groupBy('buy_decision_id')->havingRaw('COUNT(*) > 1')->get();
        $recentDuplicates = $duplicates->filter(fn ($row) => $row->latest && Carbon::parse($row->latest)->gte(now()->subDays(2)))->count();
        $this->report($recentDuplicates > 0 ? self::FAIL : ($duplicates->isEmpty() ? self::PASS : self::WARN), 'Double-booked lots',
            $duplicates->isEmpty() ? 'none' : "{$duplicates->count()} total, {$recentDuplicates} in the last 2 days (older ones predate the fix)");

        // 9. What the bot has been deciding. ratio_bot_decisions can hold
        // millions of rows per bot and is only indexed on the bot id, so read
        // the newest rows through that index (ids are time-ordered UUIDv7)
        // instead of filtering or sorting on a timestamp.
        $recent = RatioBotDecision::query()->where('ratio_bot_config_id', $bot->id)
            ->orderByDesc('id')->limit(1000)->get(['id', 'reason', 'created_at'])
            ->filter(fn (RatioBotDecision $decision) => $decision->created_at?->gte(now()->subHour()));
        $staleBlocks = $recent->filter(fn (RatioBotDecision $decision) => str_contains(strtolower((string) $decision->reason), 'stale'))->count();
        $this->report($staleBlocks > 0 ? $severity : self::PASS, 'Decisions in the last hour',
            ($recent->count() >= 1000 ? '1000+' : (string) $recent->count())." total, {$staleBlocks} stale-price blocks");
        $recent->countBy(fn (RatioBotDecision $decision) => mb_strimwidth((string) $decision->reason, 0, 70, '...'))
            ->sortDesc()->take(3)->each(fn (int $n, string $reason) => $this->line(sprintf('      %5d  %s', $n, $reason)));
        $this->line('    last trade: '.($state->last_trade_time
            ? "{$state->last_trade_side} @ ".($state->last_fill_price ?? ($state->last_trade_side === 'SELL' ? $state->last_sell_price : $state->last_buy_price))." on {$state->last_trade_time}"
            : 'none'));

        // 10. Bot holdings vs the real exchange account.
        if ($this->option('exchange') && $live && $bot->marketType() === 'spot') {
            $this->exchangeCheck($bot, $state, $clients);
        }
    }

    private function exchangeCheck(RatioBotConfig $bot, RatioBotState $state, ExchangeClientFactory $clients): void
    {
        try {
            $connection = ExchangeConnection::query()->find($bot->connection_id);
            if (! $connection) {
                $this->report(self::FAIL, 'Exchange balance', 'bot has no exchange connection');

                return;
            }
            $asset = strtoupper((string) $bot->baseAsset());
            $balance = collect($clients->makeFromConnection($connection)->fetchBalances())
                ->first(fn ($row) => strtoupper((string) ($row['asset'] ?? '')) === $asset);
            $onExchange = (float) ($balance['free'] ?? 0) + (float) ($balance['locked'] ?? 0);
            $botQty = in_array($state->position_state, [RatioBotState::STATE_FLAT, RatioBotState::STATE_IDLE], true)
                ? 0.0 : (float) ($state->last_buy_quantity ?? 0);
            // Base-asset trading fees make the account hold slightly less than the bot booked.
            $ok = $botQty <= 0 || $onExchange + 1e-8 >= $botQty * 0.995;
            $this->report($ok ? self::PASS : self::FAIL, "Binance {$asset} balance vs bot",
                "exchange {$onExchange}, bot holds {$botQty}".($ok ? '' : ': the bot thinks it holds more than the account has'));
        } catch (Throwable $e) {
            $this->report(self::WARN, 'Exchange balance', 'could not read: '.$e->getMessage());
        }
    }

    private function timeframeSeconds(string $timeframe): int
    {
        if (! preg_match('/^(\d+)([mhdw])$/', $timeframe, $match)) {
            return 60;
        }

        return (int) $match[1] * ['m' => 60, 'h' => 3600, 'd' => 86400, 'w' => 604800][$match[2]];
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->line("== {$title}");
    }

    private function report(string $status, string $check, ?string $detail = null): void
    {
        $this->totals[$status]++;
        $label = match ($status) {
            self::PASS => '<fg=green>PASS</>',
            self::WARN => '<fg=yellow>WARN</>',
            default => '<fg=red>FAIL</>',
        };
        $this->line("  {$label}  {$check}".($detail !== null && $detail !== '' ? ": {$detail}" : ''));
    }
}
