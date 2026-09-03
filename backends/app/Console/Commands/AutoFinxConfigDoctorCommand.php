<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class AutoFinxConfigDoctorCommand extends Command
{
    protected $signature = 'autofinx:config-doctor';

    protected $description = 'Validate production runtime configuration without printing secrets.';

    public function handle(): int
    {
        $fail = false;
        $warn = false;

        $this->line('AUTOFINX CONFIG DOCTOR');
        if (! $this->check('PHP >= 8.2', version_compare(PHP_VERSION, '8.2.0', '>='), PHP_VERSION)) {
            $fail = true;
        }
        if (! $this->check('APP_ENV set', trim((string) config('app.env')) !== '', (string) config('app.env'))) {
            $warn = true;
        }
        if (! $this->check('APP_DEBUG disabled in production', ! app()->environment('production') || ! (bool) config('app.debug'), app()->environment('production') ? 'production' : 'non-production')) {
            $warn = true;
        }
        if (! $this->check('Redis client configured', trim((string) config('database.redis.client')) !== '', (string) config('database.redis.client'))) {
            $fail = true;
        }
        if (! $this->check('Queue connection configured', trim((string) config('queue.default')) !== '', (string) config('queue.default'))) {
            $fail = true;
        }

        $supervisorRuntime = (bool) config('autofinx.runtime.supervisor', false);
        $scheduledRuntime = (bool) config('future_trade.schedule.runtime_commands', false);
        $marketConsumers = (bool) config('autofinx.scheduler.market_consumers', false);
        $schedulerConflict = $supervisorRuntime && ($scheduledRuntime || $marketConsumers);
        if (! $this->check('No scheduler/supervisor market-feed conflict', ! $schedulerConflict, 'supervisor='.($supervisorRuntime ? 'yes' : 'no').', scheduled_runtime='.($scheduledRuntime ? 'yes' : 'no').', market_consumers='.($marketConsumers ? 'yes' : 'no'))) {
            $fail = true;
        }

        $horizonEnabled = (bool) config('queue_control.horizon_enabled', false);
        $horizonInstalled = class_exists(\Laravel\Horizon\Horizon::class) || File::exists(config_path('horizon.php'));
        if (! $this->check('Horizon flag matches installed package', ! $horizonEnabled || $horizonInstalled, 'enabled='.($horizonEnabled ? 'yes' : 'no').', installed='.($horizonInstalled ? 'yes' : 'no'))) {
            $fail = true;
        }

        $catalogQueues = array_keys((array) config('queue_control.queues', []));
        $profileQueues = collect((array) config('queue_control.worker_profiles', []))
            ->flatMap(fn (array $profile) => (array) ($profile['queues'] ?? []))
            ->unique()
            ->values()
            ->all();
        $missingQueues = array_values(array_diff($catalogQueues, $profileQueues));
        if (! $this->check('Every configured queue has worker profile', $missingQueues === [], $missingQueues === [] ? 'covered' : implode(',', $missingQueues))) {
            $fail = true;
        }

        $globalTradingMode = strtolower((string) env('TRADING_MODE', ''));
        $futureLive = (bool) config('future_trade.enable_live_trading', false);
        $futurePaper = (bool) config('future_trade.paper_trading', true);
        if (! $this->check('Future Trade paper/live flags are not mixed', ! ($futureLive && $futurePaper), 'future_live='.($futureLive ? 'true' : 'false').', future_paper='.($futurePaper ? 'true' : 'false'))) {
            $fail = true;
        }
        if (! $this->check('Global trading mode agrees with Future Trade live', ! ($globalTradingMode === 'paper' && $futureLive), 'TRADING_MODE='.($globalTradingMode ?: 'unset').', future_live='.($futureLive ? 'true' : 'false'))) {
            $warn = true;
        }

        if (! $this->check('Future Trade live trading disabled by default', ! (bool) config('future_trade.enable_live_trading'), 'live='.((bool) config('future_trade.enable_live_trading') ? 'true' : 'false'))) {
            $warn = true;
        }

        $exit = $fail ? 2 : ($warn ? 1 : 0);
        $this->line('OVERALL......................... '.($exit === 0 ? 'PASS' : ($exit === 1 ? 'WARN' : 'FAIL')));

        return $exit;
    }

    private function check(string $label, bool $ok, string $detail = ''): bool
    {
        $this->line(sprintf('%-34s %s %s', $label, $ok ? 'PASS' : 'FAIL', $detail));

        return $ok;
    }
}
