<?php

namespace App\Domain\QuickTradeManual;

use App\Domain\QuickTradeManual\Exchange\ManualExchangeException;
use App\Domain\QuickTradeManual\Exchange\ManualExchangeGateway;
use App\Domain\QuickTradeManual\Exchange\ManualExchangeGatewayFactory;
use App\Domain\Trading\TradingIntegrationResolver;
use App\Models\Integration;
use RuntimeException;

/**
 * Read-only pre-trade computation shared by the confirmation screen (preview) and by the
 * real entry (which re-runs it against fresh exchange data and refuses on any blocking error).
 */
class ManualPreviewService
{
    /** Same safety margin the Time-Based provider keeps on top of the stake. */
    public const MARGIN_BUFFER_PCT = 10.0;

    public function __construct(
        private readonly ManualFeatureGate $gate,
        private readonly ManualExchangeGatewayFactory $gateways,
        private readonly TradingIntegrationResolver $integrations,
    ) {
    }

    /**
     * Validates and normalises user input. Throws RuntimeException('code: message').
     *
     * @return array{symbol: string, direction: string, stake: float, leverage: float, sl_pct: float, tp_pct: ?float}
     */
    public function normalizeInput(array $in): array
    {
        $symbol = strtoupper(trim((string) ($in['symbol'] ?? '')));
        if (! in_array($symbol, (array) config('quick_trade_manual.symbols', []), true)) {
            throw new RuntimeException('invalid_symbol: Manual SL/TP supports '.implode(', ', (array) config('quick_trade_manual.symbols', [])).'.');
        }

        $direction = strtolower(trim((string) ($in['direction'] ?? '')));
        $direction = ['buy' => 'long', 'sell' => 'short'][$direction] ?? $direction;
        if (! in_array($direction, ['long', 'short'], true)) {
            throw new RuntimeException('invalid_direction: Choose BUY/LONG or SELL/SHORT.');
        }

        $stake = round((float) ($in['stake_amount'] ?? 0), 8);
        $minStake = (float) config('quick_trade_manual.min_stake', 20);
        $maxStake = (float) config('quick_trade_manual.max_stake', 500);
        if ($stake < $minStake) {
            throw new RuntimeException("stake_too_small: Minimum stake is {$minStake} USDT.");
        }
        if ($stake > $maxStake) {
            throw new RuntimeException("stake_too_large: Maximum stake is {$maxStake} USDT.");
        }

        $leverage = (float) ($in['leverage'] ?? 1);
        $maxLeverage = (float) config('quick_trade_manual.max_leverage', 20);
        if ($leverage < 1 || $leverage > $maxLeverage || abs($leverage - round($leverage)) > 1e-9) {
            throw new RuntimeException("invalid_leverage: Leverage must be a whole number from 1 to {$maxLeverage}.");
        }

        if (! isset($in['sl_pct']) || $in['sl_pct'] === '' || ! is_numeric($in['sl_pct'])) {
            throw new RuntimeException('stop_loss_required: A stop-loss is mandatory in Manual SL/TP mode.');
        }
        $slPct = round((float) $in['sl_pct'], 4);
        $minSl = (float) config('quick_trade_manual.min_sl_pct', 0.1);
        $maxSl = (float) config('quick_trade_manual.max_sl_pct', 15);
        if ($slPct < $minSl || $slPct > $maxSl) {
            throw new RuntimeException("invalid_stop_loss: Stop-loss must be between {$minSl}% and {$maxSl}% of price movement.");
        }

        $tpPct = null;
        if (isset($in['tp_pct']) && $in['tp_pct'] !== '' && $in['tp_pct'] !== null) {
            if (! is_numeric($in['tp_pct']) || (float) $in['tp_pct'] <= 0 || (float) $in['tp_pct'] > 1000) {
                throw new RuntimeException('invalid_take_profit: Take-profit must be a positive percentage of price movement.');
            }
            $tpPct = round((float) $in['tp_pct'], 4);
        }

        return [
            'symbol' => $symbol,
            'direction' => $direction,
            'stake' => $stake,
            'leverage' => $leverage,
            'sl_pct' => $slPct,
            'tp_pct' => $tpPct,
        ];
    }

    public function resolveIntegration(string $workspaceId, ?string $integrationId): Integration
    {
        $integration = $this->integrations->resolve($workspaceId, 'binance', $integrationId);
        $this->gate->assertEnvironmentAllowed($this->gateways->environmentOf($integration));

        return $integration;
    }

    /**
     * @param  array{symbol: string, direction: string, stake: float, leverage: float, sl_pct: float, tp_pct: ?float}  $in  normalised
     * @return array<string, mixed>
     */
    public function compute(ManualExchangeGateway $gw, array $in): array
    {
        try {
            $mark = $gw->markPrice($in['symbol']);
            $maxAge = (int) config('quick_trade_manual.mark_max_age_seconds', 15);
            $ageMs = $mark['time_ms'] > 0 ? (int) floor(microtime(true) * 1000) - $mark['time_ms'] : null;
            if ($ageMs !== null && $ageMs > $maxAge * 1000) {
                throw new RuntimeException(sprintf('stale_mark_price: Exchange mark price is %ds old (max %ds). Try again.', intdiv($ageMs, 1000), $maxAge));
            }

            $rules = $gw->symbolRules($in['symbol']);
            $hedge = $gw->isHedgeMode();
            $positionSide = $hedge ? ($in['direction'] === 'long' ? 'LONG' : 'SHORT') : null;
            $position = $gw->position($in['symbol'], $positionSide);

            $notionalTarget = $in['stake'] * $in['leverage'];
            $brackets = $gw->leverageBrackets($in['symbol']);
            $tier = $brackets !== null ? $this->tierFor($brackets, $notionalTarget) : null;
            $mmr = $tier !== null && isset($tier['maintMarginRatio']) && is_numeric($tier['maintMarginRatio']) ? (float) $tier['maintMarginRatio'] : null;
            $realMaxLeverage = $tier !== null ? (float) ($tier['initialLeverage'] ?? 0) : null;

            $balance = $gw->availableBalance();
        } catch (ManualExchangeException $e) {
            throw new RuntimeException('exchange_unavailable: '.$e->getMessage());
        }

        $est = ManualTradeMath::estimate([
            'direction' => $in['direction'],
            'stake' => $in['stake'],
            'leverage' => $in['leverage'],
            'price' => $mark['mark_price'],
            'sl_pct' => $in['sl_pct'],
            'tp_pct' => $in['tp_pct'],
            'tick_size' => $rules['tick_size'],
            'step_size' => $rules['step_size'],
            'min_qty' => $rules['min_qty'],
            'min_notional' => $rules['min_notional'],
            'maint_margin_rate' => $mmr,
            'taker_fee_rate' => (float) config('quick_trade_manual.taker_fee_rate', 0.0005),
            'slippage_buffer_pct' => (float) config('quick_trade_manual.slippage_buffer_pct', 0.04),
            'sl_liq_ratio' => (float) config('quick_trade_manual.sl_liq_ratio', 0.5),
            'position_mode' => $hedge ? 'hedge' : 'one_way',
            'margin_type' => $position['margin_type'],
        ]);

        if ($realMaxLeverage === null || $realMaxLeverage <= 0) {
            $est['errors'][] = ['code' => 'unable_to_verify_exchange_max_leverage', 'message' => 'Exchange leverage limits could not be verified. Refused.'];
        } elseif ($in['leverage'] > $realMaxLeverage + 1e-9) {
            $est['errors'][] = ['code' => 'exceeds_exchange_max_leverage_for_notional', 'message' => sprintf('Leverage %dx exceeds the exchange maximum %dx for this position size.', $in['leverage'], $realMaxLeverage)];
        }

        $requiredMargin = round($est['margin_required'] * (1 + self::MARGIN_BUFFER_PCT / 100), 8);
        if ($requiredMargin > $balance) {
            $est['errors'][] = ['code' => 'insufficient_margin', 'message' => sprintf('Required margin %.2f USDT (incl. %d%% buffer) exceeds available futures balance %.2f USDT.', $requiredMargin, (int) self::MARGIN_BUFFER_PCT, $balance)];
        }
        if (abs($position['amount']) > 0) {
            $est['errors'][] = ['code' => 'exchange_position_exists', 'message' => 'An open exchange position already exists on this symbol/side. Manual mode only opens when the symbol is flat.'];
        }

        if ($mark['funding_rate'] !== null) {
            $est['warnings'][] = sprintf('Current funding rate %.4f%% (next funding %s UTC).', $mark['funding_rate'] * 100, $mark['next_funding_ms'] ? gmdate('H:i', intdiv($mark['next_funding_ms'], 1000)) : 'unknown');
        }

        $est['errors'] = array_values($est['errors']);
        $est['ok'] = $est['errors'] === [];
        $est['symbol'] = $in['symbol'];
        $est['position_mode'] = $hedge ? 'hedge' : 'one_way';
        $est['margin_type'] = $position['margin_type'];
        $est['exchange_leverage_current'] = $position['leverage'];
        $est['funding_rate'] = $mark['funding_rate'];
        $est['next_funding_ms'] = $mark['next_funding_ms'];
        $est['available_balance'] = $balance;
        $est['mark_age_ms'] = $ageMs;
        $est['environment'] = $gw->environment();
        $est['rules'] = $rules;
        $est['maint_margin_rate'] = $mmr;

        return $est;
    }

    /** First tier whose [floor, cap) holds the notional; else the highest-cap tier (most conservative). */
    private function tierFor(array $brackets, float $notional): ?array
    {
        foreach ($brackets as $b) {
            $floor = (float) ($b['notionalFloor'] ?? 0);
            $cap = (float) ($b['notionalCap'] ?? 0);
            if ($cap > 0 && $notional >= $floor && $notional < $cap) {
                return $b;
            }
        }
        usort($brackets, fn ($a, $b) => (float) ($b['notionalCap'] ?? 0) <=> (float) ($a['notionalCap'] ?? 0));

        return $brackets[0] ?? null;
    }
}
