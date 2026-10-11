<?php

namespace App\Domain\QuickTradeManual\Exchange;

use App\Domain\Exchange\BinanceAlgoOrderApi;

/**
 * Binance USD-S-M Futures ALGO ORDER API shapes for Quick Trade Manual, in ONE place.
 *
 * VERIFIED against Binance's docs on 2026-10-10 (NOT yet run against a live account/testnet):
 *  - POST /fapi/v1/algoOrder, signed; `algoType=CONDITIONAL` (required), `symbol`, `side`, `type`
 *    (STOP_MARKET / TAKE_PROFIT_MARKET / STOP / TAKE_PROFIT / TRAILING_STOP_MARKET), `positionSide`
 *    (send it in hedge mode), `triggerPrice` (NOT stopPrice), `workingType` (MARK_PRICE|CONTRACT_PRICE),
 *    `closePosition` (true; cannot be combined with `quantity` or `reduceOnly`), `clientAlgoId`
 *    (^[.A-Z:/a-z0-9_-]{1,36}$, unique among open orders), `newOrderRespType` ACK|RESULT.
 *  - Response fields: algoId, clientAlgoId, algoType, orderType, symbol, side, positionSide, algoStatus
 *    (e.g. NEW), triggerPrice, workingType, closePosition, reduceOnly, createTime, updateTime, ...
 *  - DELETE /fapi/v1/algoOrder (algoId | clientAlgoId; params in the QUERY STRING) answers
 *    {algoId, clientAlgoId, code: "200", msg: "success"}. DELETE /fapi/v1/algoOpenOrders (symbol).
 *  - GET /fapi/v1/openAlgoOrders (symbol optional), GET /fapi/v1/allAlgoOrders (symbol required).
 * STILL UNVERIFIED: the body of GET /fapi/v1/algoOrder (third-party sourced), the full algoStatus
 * vocabulary (only NEW is confirmed; TRIGGERED/FINISHED/... below are guesses), /fapi/v1/forceOrders
 * use for algo orders, and everything on testnet.
 *
 * The service layer stays fail-closed: protection is "verified" only if the exchange ANSWERS a query
 * for the order we just placed with a working status, the same symbol/side/type and the same trigger
 * price. Any shape surprise => not verified => the position is emergency-closed.
 * The routes/field names are shared with BinanceFuturesClient (App\Domain\Exchange\BinanceAlgoOrderApi),
 * which Future Trade and Ratio Bot use for their exchange-side stops.
 * (tests/Unit/QuickTradeManual/BinanceAlgoApiTest pins the shapes.)
 */
final class BinanceAlgoApi
{
    public const PLACE_PATH = BinanceAlgoOrderApi::PLACE_PATH;
    public const QUERY_PATH = BinanceAlgoOrderApi::QUERY_PATH;
    public const CANCEL_PATH = BinanceAlgoOrderApi::CANCEL_PATH;
    public const CANCEL_ALL_PATH = BinanceAlgoOrderApi::CANCEL_ALL_PATH;
    public const OPEN_PATH = BinanceAlgoOrderApi::OPEN_PATH;
    public const HISTORY_PATH = BinanceAlgoOrderApi::HISTORY_PATH;
    public const FORCE_ORDERS_PATH = '/fapi/v1/forceOrders';   // UNVERIFIED for algo orders

    public const ALGO_TYPE = BinanceAlgoOrderApi::ALGO_TYPE;
    public const TRIGGER_PARAM = 'triggerPrice';
    public const CLIENT_ID_PARAM = 'clientAlgoId';

    /** Statuses that mean "armed and waiting on the exchange" (NEW is verified by the docs example; WORKING is a guess). Anything else is NOT protection. */
    public const WORKING_STATUSES = ['NEW', 'WORKING'];

    /** UNVERIFIED vocabulary: statuses that mean the trigger fired. */
    public const TRIGGERED_STATUSES = ['TRIGGERED', 'TRIGGERING', 'FINISHED', 'EXECUTED', 'FILLED'];

    /** UNVERIFIED vocabulary: statuses that mean the order is gone without firing. */
    public const INACTIVE_STATUSES = ['CANCELED', 'CANCELLED', 'EXPIRED', 'REJECTED'];

    public const TYPE_STOP = 'STOP_MARKET';
    public const TYPE_TAKE_PROFIT = 'TAKE_PROFIT_MARKET';

    /**
     * @param  array{symbol: string, side: string, position_side: ?string, type: string, trigger_price: string, client_algo_id: string, working_type: string}  $p
     */
    public static function placeParams(array $p): array
    {
        $params = [
            'algoType' => self::ALGO_TYPE,
            'symbol' => $p['symbol'],
            'side' => $p['side'],
            'type' => $p['type'],
        ];
        if ($p['type'] === 'TRAILING_STOP_MARKET') {
            $params['closePosition'] = 'true';
            $params['callbackRate'] = $p['callback_rate'];
            if (! empty($p['activation_price'])) $params['activationPrice'] = $p['activation_price'];
        } else {
            $params['triggerPrice'] = $p['trigger_price'];
            $params['closePosition'] = 'true';
        }
        $params += [
            'workingType' => $p['working_type'],
            self::CLIENT_ID_PARAM => $p['client_algo_id'],
        ];
        if (! empty($p['position_side'])) {
            $params['positionSide'] = $p['position_side'];   // hedge mode only
        }

        return $params;
    }

    /** @return array{algo_id: string, client_algo_id: string, status: string, symbol: string, side: string, type: string, trigger_price: float, close_position: ?bool} */
    public static function parse(array $json): array
    {
        $closePosition = $json['closePosition'] ?? null;

        return [
            'algo_id' => isset($json['algoId']) ? (string) $json['algoId'] : '',
            'client_algo_id' => (string) ($json[self::CLIENT_ID_PARAM] ?? ''),
            'status' => strtoupper((string) ($json['algoStatus'] ?? $json['status'] ?? '')),
            'symbol' => strtoupper((string) ($json['symbol'] ?? '')),
            'side' => strtoupper((string) ($json['side'] ?? '')),
            'type' => strtoupper((string) ($json['orderType'] ?? $json['type'] ?? '')),
            'trigger_price' => (float) ($json[self::TRIGGER_PARAM] ?? $json['stopPrice'] ?? $json['activationPrice'] ?? 0),
            'callback_rate' => isset($json['callbackRate']) ? (float) $json['callbackRate'] : null,
            'activation_price' => isset($json['activationPrice']) ? (float) $json['activationPrice'] : null,
            'close_position' => $closePosition === null ? null : filter_var($closePosition, FILTER_VALIDATE_BOOLEAN),
        ];
    }
}
