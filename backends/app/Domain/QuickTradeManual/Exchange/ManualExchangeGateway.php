<?php

namespace App\Domain\QuickTradeManual\Exchange;

/**
 * The ONLY door the manual SL/TP mode uses to reach the exchange. Small on purpose so a
 * test can fake the whole exchange (see tests/Support/FakeManualExchange). Prices and
 * quantities passed in are already formatted strings (tick/step-correct); every method
 * throws ManualExchangeException on failure, with `ambiguous` set as documented there.
 *
 * Normalized shapes (all numbers are floats, ids are strings):
 *  order     = {order_id, client_order_id, status, executed_qty, avg_price}
 *  protective= {algo_id, client_algo_id, status, symbol, side, type, trigger_price, close_position}
 *  position  = {amount (signed), entry_price, mark_price, liquidation_price, leverage, margin_type, unrealized_pnl}
 */
interface ManualExchangeGateway
{
    /** 'testnet' | 'live' */
    public function environment(): string;

    public function isHedgeMode(): bool;

    /** @return array{tick_size: float, step_size: float, min_qty: float, min_notional: float} */
    public function symbolRules(string $symbol): array;

    /** @return array{mark_price: float, time_ms: int, funding_rate: ?float, next_funding_ms: ?int} */
    public function markPrice(string $symbol): array;

    public function availableBalance(): float;

    /** Real leverage bracket table (same shape BinanceFuturesClient::leverageBrackets returns) or null. */
    public function leverageBrackets(string $symbol): ?array;

    /** @return array{amount: float, entry_price: float, mark_price: float, liquidation_price: ?float, leverage: ?float, margin_type: ?string, unrealized_pnl: float} */
    public function position(string $symbol, ?string $positionSide): array;

    public function setLeverage(string $symbol, int $leverage): void;

    /**
     * MARKET entry. @param array{symbol: string, side: string, quantity: string, position_side: ?string, client_order_id: string} $p
     * @return array{order_id: string, client_order_id: string, status: string, executed_qty: float, avg_price: float}
     */
    public function placeEntry(array $p): array;

    /** Reduce-only (one-way) / positionSide-scoped (hedge) MARKET close. Same param/return shape as placeEntry. */
    public function placeClose(array $p): array;

    /** @return array{order_id: string, client_order_id: string, status: string, executed_qty: float, avg_price: float}|null null = order not found */
    public function queryOrder(string $symbol, ?string $orderId, ?string $clientOrderId): ?array;

    /**
     * Exchange-native conditional close-the-whole-position order.
     * @param array{symbol: string, side: string, position_side: ?string, type: string, trigger_price: string, client_algo_id: string, working_type: string} $p
     * @return array{algo_id: string, client_algo_id: string, status: string}
     */
    public function placeProtective(array $p): array;

    /** @return array{algo_id: string, client_algo_id: string, status: string, symbol: string, side: string, type: string, trigger_price: float, close_position: ?bool}|null null = not found */
    public function queryProtective(string $symbol, ?string $algoId, ?string $clientAlgoId): ?array;

    public function cancelProtective(string $symbol, ?string $algoId, ?string $clientAlgoId): void;

    /** @return array{commission: float, realized_pnl: float, quantity: float, avg_price: float, fills: int}|null null = unknown (lookup failed or mixed fee asset) */
    public function orderFills(string $symbol, string $orderId): ?array;

    /** Signed funding income (negative = charged) between the two timestamps, or null if it cannot be established. */
    public function fundingIncome(string $symbol, int $fromMs, int $toMs): ?float;

    /** true = a forced liquidation/ADL record exists since the timestamp, false = none, null = cannot be established. */
    public function liquidationSince(string $symbol, int $sinceMs): ?bool;

    /**
     * Fills of the symbol since a timestamp (any order), used to price an exchange-side exit
     * (SL/TP/liquidation) that this app did not send itself.
     * @return list<array{order_id: string, side: string, price: float, quantity: float, commission: float, realized_pnl: float, time_ms: int}>|null
     */
    public function tradesSince(string $symbol, int $sinceMs): ?array;
}
