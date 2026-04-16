<?php

namespace App\Domain\Risk;

use App\Domain\Market\MarketDataService;
use App\Domain\Risk\DTO\RiskDecision;
use App\Domain\Ops\KillSwitchService;
use App\Models\EquitySnapshot;
use App\Models\InstanceRiskProfile;
use App\Models\Ledger;
use App\Models\Position;
use App\Models\RiskProfile;
use App\Models\StrategyInstance;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class RiskEngine
{
    public function __construct(
        private MarketDataService $marketData,
        private KillSwitchService $killSwitch
    ) {
    }

    public function assess(
        StrategyInstance $instance,
        string $symbol,
        string $side,
        float $price,
        float $quantity
    ): RiskDecision {
        $workspaceId = $instance->workspace_id;
        $rules = $this->loadRules($instance);
        $equity = $this->currentEquity($workspaceId, $symbol, $price);
        $notional = $price * $quantity;

        $limitsHit = [];

        $maxTrade = (float) ($rules['max_trade_pct'] ?? env('RISK_DEFAULT_MAX_TRADE_PCT', 2));
        if ($equity > 0 && ($notional / $equity) * 100 > $maxTrade) {
            $limitsHit[] = 'max_trade_pct';
        }

        $maxPos = (float) ($rules['max_position_pct'] ?? env('RISK_DEFAULT_MAX_POSITION_PCT', 10));
        $positionValueAfter = $this->positionValueAfter($workspaceId, $symbol, $side, $quantity, $price);
        if ($equity > 0 && ($positionValueAfter / $equity) * 100 > $maxPos) {
            $limitsHit[] = 'max_position_pct';
        }

        if ($this->hitDailyLoss($workspaceId, $equity, (float) ($rules['daily_loss_pct'] ?? env('RISK_DEFAULT_DAILY_LOSS_PCT', 5)))) {
            $limitsHit[] = 'daily_loss';
            $this->killSwitch->activate($workspaceId, null, 'daily_loss_limit');
        }

        if ($this->hitDrawdown($workspaceId, $equity, (float) ($rules['drawdown_pct'] ?? env('RISK_DEFAULT_DRAWDOWN_PCT', 10)))) {
            $limitsHit[] = 'drawdown';
            $this->killSwitch->activate($workspaceId, null, 'drawdown_limit');
        }

        $slippageBps = (float) ($rules['slippage_bps'] ?? env('RISK_DEFAULT_SLIPPAGE_BPS', 50));
        if (!empty($rules['observed_slippage_bps']) && $rules['observed_slippage_bps'] > $slippageBps) {
            $limitsHit[] = 'slippage';
        }

        $volBreakerBps = (float) ($rules['volatility_bps'] ?? env('RISK_DEFAULT_VOLATILITY_BPS', 250));
        if ($this->volatilityTooHigh($symbol, $volBreakerBps)) {
            $limitsHit[] = 'volatility';
        }

        if (!empty($limitsHit)) {
            return new RiskDecision('REJECT', 'risk_limits', $limitsHit, [
                'equity' => $equity,
                'notional' => $notional,
            ]);
        }

        $this->snapshotEquity($workspaceId, $equity);

        return new RiskDecision('APPROVE', 'ok', [], [
            'equity' => $equity,
            'notional' => $notional,
        ]);
    }

    private function loadRules(StrategyInstance $instance): array
    {
        $link = InstanceRiskProfile::where('instance_id', $instance->id)->first();
        if ($link) {
            $profile = RiskProfile::find($link->risk_profile_id);
            if ($profile && $profile->rules) {
                return $profile->rules;
            }
        }
        return [
            'max_trade_pct' => env('RISK_DEFAULT_MAX_TRADE_PCT', 2),
            'max_position_pct' => env('RISK_DEFAULT_MAX_POSITION_PCT', 10),
            'daily_loss_pct' => env('RISK_DEFAULT_DAILY_LOSS_PCT', 5),
            'drawdown_pct' => env('RISK_DEFAULT_DRAWDOWN_PCT', 10),
            'slippage_bps' => env('RISK_DEFAULT_SLIPPAGE_BPS', 50),
            'volatility_bps' => env('RISK_DEFAULT_VOLATILITY_BPS', 250),
        ];
    }

    private function currentEquity(string $workspaceId, string $symbol, float $price): float
    {
        $ledger = Ledger::where('workspace_id', $workspaceId)->first();
        $cash = $ledger?->balance ?? 0;

        $positions = Position::where('workspace_id', $workspaceId)->get();
        $marketValue = 0.0;
        foreach ($positions as $pos) {
            $latest = $pos->symbol?->symbol === $symbol
                ? $price
                : $this->marketData->latestPrice($pos->symbol?->symbol ?? $symbol);
            if ($latest === null) {
                continue;
            }
            $marketValue += $pos->quantity * $latest;
        }

        return $cash + $marketValue;
    }

    private function positionValueAfter(string $workspaceId, string $symbol, string $side, float $qty, float $price): float
    {
        $pos = Position::where('workspace_id', $workspaceId)
            ->whereHas('symbol', function ($q) use ($symbol) {
                $q->where('symbol', $symbol);
            })
            ->first();

        $currentQty = $pos?->quantity ?? 0;
        $newQty = $side === 'buy' ? $currentQty + $qty : $currentQty - $qty;
        return abs($newQty * $price);
    }

    private function hitDailyLoss(string $workspaceId, float $equity, float $limitPct): bool
    {
        $todayStart = CarbonImmutable::now()->startOfDay();
        $today = EquitySnapshot::where('workspace_id', $workspaceId)
            ->where('recorded_at', '>=', $todayStart)
            ->orderBy('recorded_at')
            ->first();

        if (!$today) {
            return false;
        }

        $dropPct = (($equity - $today->equity) / max(1, $today->equity)) * 100;
        return $dropPct < -$limitPct;
    }

    private function hitDrawdown(string $workspaceId, float $equity, float $limitPct): bool
    {
        $peak = EquitySnapshot::where('workspace_id', $workspaceId)
            ->orderByDesc('equity')
            ->first();
        if (!$peak || $peak->equity <= 0) {
            return false;
        }
        $ddPct = (($equity - $peak->equity) / $peak->equity) * 100;
        return $ddPct < -$limitPct;
    }

    private function volatilityTooHigh(string $symbol, float $thresholdBps): bool
    {
        $candles = $this->marketData->candles($symbol, '1m', 30);
        if (count($candles) < 10) {
            return false;
        }
        $closes = array_map(fn ($c) => $c['close'], $candles);
        $mean = array_sum($closes) / count($closes);
        $variance = 0.0;
        foreach ($closes as $c) {
            $variance += pow($c - $mean, 2);
        }
        $sd = sqrt($variance / count($closes));
        $bps = $mean > 0 ? ($sd / $mean) * 10000 : 0;
        return $bps > $thresholdBps;
    }

    private function snapshotEquity(string $workspaceId, float $equity): void
    {
        EquitySnapshot::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'equity' => $equity,
            'recorded_at' => CarbonImmutable::now(),
        ]);
    }
}
