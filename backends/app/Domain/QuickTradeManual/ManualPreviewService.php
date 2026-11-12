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
     * @return array{symbol: string, direction: string, stake: float, leverage: float, sl_pct: ?float, no_stop_loss: bool, tp_pct: ?float, protection_pairs: list<array<string, mixed>>}
     */
    public function normalizeInput(array $in, bool $requireNoStopAck = true): array
    {
        $symbol = strtoupper(trim((string) ($in['symbol'] ?? '')));
        // Binance exchangeInfo is the source of truth; symbolRules() below
        // still fail-closes if the selected symbol is not actually listed.
        if (! preg_match('/^[A-Z0-9]{3,24}USDT$/', $symbol)) {
            throw new RuntimeException('invalid_symbol: Choose a Binance USDT-margined futures symbol.');
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

        $noStop = self::truthy($in['no_stop_loss'] ?? false);
        $slProvided = isset($in['sl_pct']) && $in['sl_pct'] !== '' && $in['sl_pct'] !== null;
        $slPct = null;
        if ($noStop) {
            // Explicit per-trade opt-in only. Never inferred from a missing stop-loss.
            if (! (bool) config('quick_trade_manual.allow_no_stop_loss', false)) {
                throw new RuntimeException('no_stop_loss_disabled: Trading without a stop-loss is disabled on this server (QUICK_TRADE_MANUAL_ALLOW_NO_STOP_LOSS).');
            }
            if ($slProvided) {
                throw new RuntimeException('stop_loss_conflict: Send either a stop-loss or the no-stop-loss opt-in, not both.');
            }
            if ($requireNoStopAck && ! self::truthy($in['no_stop_loss_ack'] ?? false)) {
                throw new RuntimeException('no_stop_loss_ack_required: Trading without a stop-loss requires explicit acknowledgement (no_stop_loss_ack).');
            }
            $maxNoSl = (float) config('quick_trade_manual.no_stop_loss_max_leverage', 5);
            if ($leverage > $maxNoSl) {
                throw new RuntimeException("no_stop_loss_leverage_too_high: Without a stop-loss the maximum leverage is {$maxNoSl}x.");
            }
        } else {
            if (! $slProvided || ! is_numeric($in['sl_pct'])) {
                throw new RuntimeException('stop_loss_required: A stop-loss is required unless you explicitly opt in to trading without one.');
            }
            $slPct = round((float) $in['sl_pct'], 4);
            $minSl = (float) config('quick_trade_manual.min_sl_pct', 0.1);
            $maxSl = (float) config('quick_trade_manual.max_sl_pct', 15);
            if ($slPct < $minSl || $slPct > $maxSl) {
                throw new RuntimeException("invalid_stop_loss: Stop-loss must be between {$minSl}% and {$maxSl}% of price movement.");
            }
        }

        $tpPct = null;
        if (isset($in['tp_pct']) && $in['tp_pct'] !== '' && $in['tp_pct'] !== null) {
            if (! is_numeric($in['tp_pct']) || (float) $in['tp_pct'] <= 0 || (float) $in['tp_pct'] > 1000) {
                throw new RuntimeException('invalid_take_profit: Take-profit must be a positive percentage of price movement.');
            }
            $tpPct = round((float) $in['tp_pct'], 4);
        }

        $trailingPct = null;
        $activationPct = null;
        if (isset($in['trailing_pct']) && $in['trailing_pct'] !== '' && $in['trailing_pct'] !== null) {
            if ($noStop) {
                throw new RuntimeException('trailing_requires_stop_loss: Trailing protection is only available together with a stop-loss.');
            }
            $trailingPct = round((float) $in['trailing_pct'], 4);
            $minTrail = (float) config('quick_trade_manual.min_trailing_pct', 0.1);
            $maxTrail = (float) config('quick_trade_manual.max_trailing_pct', 5.0);
            if ($trailingPct < $minTrail || $trailingPct > $maxTrail) throw new RuntimeException("invalid_trailing: Trailing distance must be between {$minTrail}% and {$maxTrail}%.");
            if (isset($in['trailing_activation_pct']) && $in['trailing_activation_pct'] !== '' && $in['trailing_activation_pct'] !== null) {
                $activationPct = round((float) $in['trailing_activation_pct'], 4);
                $maxActivation = (float) config('quick_trade_manual.max_trailing_activation_pct', 100);
                if ($activationPct < 0 || $activationPct > $maxActivation) throw new RuntimeException("invalid_trailing_activation: Activation must be between 0% and {$maxActivation}%.");
            }
        }

        $pairs = [];
        $rawPairs = $in['protection_pairs'] ?? [];
        if ($rawPairs !== [] && ! is_array($rawPairs)) {
            throw new RuntimeException('invalid_protection_pairs: Protection pairs must be an array.');
        }
        if (count($rawPairs) > 20) {
            throw new RuntimeException('too_many_protection_pairs: A maximum of 20 TP/SL pairs is supported.');
        }
        if ($rawPairs !== [] && $noStop) {
            throw new RuntimeException('protection_pairs_require_stop_loss: TP/SL reversal pairs require a normal stop-loss.');
        }
        if ($rawPairs !== [] && $tpPct !== null) {
            throw new RuntimeException('protection_pairs_conflict: Use TP/SL pairs or the legacy single take-profit, not both.');
        }
        foreach (array_values($rawPairs) as $i => $pair) {
            if (! is_array($pair)) throw new RuntimeException('invalid_protection_pair: Each protection pair must be an object.');
            $level = $i + 1;
            $tpMode = strtolower((string) ($pair['tp_mode'] ?? 'percent'));
            $slMode = strtolower((string) ($pair['sl_mode'] ?? 'fixed'));
            $slInput = strtolower((string) ($pair['sl_input_mode'] ?? 'percent'));
            if (! in_array($tpMode, ['percent', 'price'], true)) throw new RuntimeException("invalid_tp_mode: TP{$level} mode must be percent or price.");
            if (! in_array($slMode, ['fixed', 'trailing'], true)) throw new RuntimeException("invalid_sl_mode: SL{$level} mode must be fixed or trailing.");
            if (! in_array($slInput, ['percent', 'price', 'distance'], true)) throw new RuntimeException("invalid_sl_input_mode: SL{$level} input mode is invalid.");
            if ($slMode === 'trailing' && $slInput === 'price') throw new RuntimeException("invalid_trailing_input_mode: SL{$level} trailing protection must use a percentage or absolute distance, not a fixed price.");
            $tpValue = $pair['tp_value'] ?? $pair['tp_pct'] ?? $pair['tp_price'] ?? null;
            $slValue = $pair['sl_value'] ?? $pair['sl_pct'] ?? $pair['sl_price'] ?? $pair['trailing_distance'] ?? null;
            if (! is_numeric($tpValue) || (float) $tpValue <= 0) throw new RuntimeException("invalid_tp_value: TP{$level} must be positive.");
            if (! is_numeric($slValue) || (float) $slValue <= 0) throw new RuntimeException("invalid_sl_value: SL{$level} must be positive.");
            $pairs[] = [
                'level' => $level, 'tp_mode' => $tpMode, 'tp_value' => (float) $tpValue,
                'sl_mode' => $slMode, 'sl_input_mode' => $slInput, 'sl_value' => (float) $slValue,
            ];
        }

        return [
            'symbol' => $symbol,
            'direction' => $direction,
            'stake' => $stake,
            'leverage' => $leverage,
            'sl_pct' => $slPct,
            'no_stop_loss' => $noStop,
            'tp_pct' => $tpPct,
            'trailing_pct' => $trailingPct,
            'trailing_activation_pct' => $activationPct,
            'protection_pairs' => $pairs,
        ];
    }

    private static function truthy(mixed $v): bool
    {
        return $v === true || $v === 1 || $v === '1' || (is_string($v) && strtolower(trim($v)) === 'true');
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
            'no_stop_loss' => (bool) ($in['no_stop_loss'] ?? false),
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
        if (($in['no_stop_loss'] ?? false) && (bool) config('quick_trade_manual.no_stop_loss_require_isolated', true) && $position['margin_type'] !== 'isolated') {
            $est['errors'][] = ['code' => 'no_stop_loss_requires_isolated_margin', 'message' => 'Without a stop-loss the symbol must use ISOLATED margin (cross margin can lose the whole futures wallet). Switch the margin type on Binance, or use a stop-loss.'];
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
