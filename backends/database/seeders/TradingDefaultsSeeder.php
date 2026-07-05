<?php

namespace Database\Seeders;

use App\Models\FutureTradeBotConfig;
use App\Models\Guardrails;
use App\Models\RiskProfile;
use App\Models\RobotSession;
use App\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Backfills sane, consistent default risk config for every existing workspace
 * across Future Trade, Robot Trade, and the generic spot Trade Bot (RiskEngine /
 * WorkspaceLiveRiskService). Never overwrites a value a user already set — only
 * fills keys/rows that are genuinely missing. Safe to re-run at any time.
 *
 * Quick Trade is intentionally skipped: it has no stop-loss/take-profit/leverage
 * concept, only stake + payout ratio.
 */
class TradingDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        $riskKeys = ['tp1RR', 'tp2RR', 'takeProfitPercent', 'stopLossPercent', 'trailingTakeProfitPercent', 'rollbackLossPercent', 'leverage', 'maxLeverage'];

        Workspace::all()->each(function (Workspace $workspace) use ($riskKeys) {
            // Future Trade: fill any missing risk key on every slot's persisted settings.
            FutureTradeBotConfig::where('workspace_id', $workspace->id)->get()->each(function (FutureTradeBotConfig $config) use ($riskKeys) {
                $settings = (array) $config->settings;
                $changed = false;

                foreach ($riskKeys as $key) {
                    if (! array_key_exists($key, $settings)) {
                        $settings[$key] = config("future_trade.defaults.$key");
                        $changed = true;
                    }
                }

                if ($changed) {
                    $config->settings = $settings;
                    $config->save();
                }
            });

            // Robot Trade: backfill only genuinely-null risk columns on existing sessions.
            RobotSession::where('workspace_id', $workspace->id)
                ->where(function ($query) {
                    $query->whereNull('stop_loss_pct')->orWhereNull('take_profit_pct');
                })
                ->get()
                ->each(function (RobotSession $session) {
                    if ($session->stop_loss_pct === null) {
                        $session->stop_loss_pct = 12.0;
                    }
                    if ($session->take_profit_pct === null) {
                        $session->take_profit_pct = 2.0;
                    }
                    $session->save();
                });

            // Generic Trade Bot: every workspace needs at least one RiskProfile for
            // WorkspaceLiveRiskService to enforce anything at all (its defaultRules()
            // are all null otherwise) plus a Guardrails row for circuit-breaker limits.
            if (! RiskProfile::where('workspace_id', $workspace->id)->exists()) {
                RiskProfile::create([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $workspace->id,
                    'name' => 'Balanced',
                    'is_default' => true,
                    'rules' => [
                        'max_position_percent' => 20,
                        'max_trade_size_percent' => 10,
                        'daily_loss_limit_percent' => 5,
                        'drawdown_kill_percent' => 10,
                        'slippage_tolerance_percent' => 0.5,
                    ],
                ]);
            }

            Guardrails::firstOrCreate(['workspace_id' => $workspace->id]);
        });
    }
}
