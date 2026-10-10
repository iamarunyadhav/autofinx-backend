<?php

namespace App\Domain\Exchange;

/**
 * Binance USD-S-M Futures Algo Order API shapes used by BinanceFuturesClient for protective
 * (conditional) orders. Field/route names below were VERIFIED against Binance's docs on
 * 2026-10-10 (see docs/binance-algo-migration/CHECKLIST.md); what is NOT verified on exchange
 * is marked UNVERIFIED. Nothing here has been run against a live account.
 */
final class BinanceAlgoOrderApi
{
    public const PLACE_PATH = '/fapi/v1/algoOrder';           // POST
    public const QUERY_PATH = '/fapi/v1/algoOrder';           // GET (algoId | clientAlgoId); response body not confirmed
    public const CANCEL_PATH = '/fapi/v1/algoOrder';          // DELETE (algoId | clientAlgoId), params in QUERY STRING
    public const CANCEL_ALL_PATH = '/fapi/v1/algoOpenOrders'; // DELETE (symbol required)
    public const OPEN_PATH = '/fapi/v1/openAlgoOrders';       // GET (weight 1 with symbol, 40 without)
    public const HISTORY_PATH = '/fapi/v1/allAlgoOrders';     // GET (symbol required)

    public const ALGO_TYPE = 'CONDITIONAL';

    /** The legacy endpoint's "this type moved to the Algo Order API" code (reported, unconfirmed). */
    public const LEGACY_ALGO_REQUIRED_CODE = -4120;

    public const CONDITIONAL_TYPES = ['STOP_MARKET', 'TAKE_PROFIT_MARKET', 'STOP', 'TAKE_PROFIT', 'TRAILING_STOP_MARKET'];

    /**
     * UNVERIFIED: HTTP statuses / Binance codes that mean "this endpoint/type/param is not
     * supported here" (testnet without algo, route missing). Deliberately narrow: anything else
     * (duplicate clientAlgoId, bad precision, would-trigger-immediately, auth, rate limit) must
     * NOT fall back to the legacy endpoint.
     *  -5000 = "Path ... is invalid", -1116 = invalid orderType, -1104 = unread parameter.
     */
    public const UNSUPPORTED_HTTP = [404, 405];
    public const UNSUPPORTED_CODES = [-5000, -1116, -1104];

    public static function isConditionalType(string $type): bool
    {
        return in_array(strtoupper($type), self::CONDITIONAL_TYPES, true);
    }

    public static function isValidClientAlgoId(string $id): bool
    {
        return preg_match('/^[\.A-Z\:\/a-z0-9_-]{1,36}$/', $id) === 1;
    }

    public static function isUnsupportedRejection(?int $httpStatus, ?int $code): bool
    {
        return ($httpStatus !== null && in_array($httpStatus, self::UNSUPPORTED_HTTP, true))
            || ($code !== null && in_array($code, self::UNSUPPORTED_CODES, true));
    }

    /**
     * Map the legacy POST /fapi/v1/order conditional payload to POST /fapi/v1/algoOrder params.
     * stopPrice -> triggerPrice, newClientOrderId -> clientAlgoId, algoType=CONDITIONAL.
     * closePosition=true never carries quantity or reduceOnly (the API forbids the combination).
     *
     * @throws \InvalidArgumentException when clientAlgoId would be invalid (nothing is sent)
     */
    public static function placeParams(array $payload): array
    {
        $clientId = (string) ($payload['newClientOrderId'] ?? '');
        if (! self::isValidClientAlgoId($clientId)) {
            throw new \InvalidArgumentException('clientAlgoId must match ^[.A-Z:/a-z0-9_-]{1,36}$ (got length '.strlen($clientId).').');
        }

        $params = [
            'algoType' => self::ALGO_TYPE,
            'symbol' => BinanceSymbolNormalizer::normalize((string) ($payload['symbol'] ?? '')),
            'side' => strtoupper((string) ($payload['side'] ?? '')),
            'type' => strtoupper((string) ($payload['type'] ?? '')),
            'clientAlgoId' => $clientId,
        ];

        if (isset($payload['stopPrice'])) {
            $params['triggerPrice'] = $payload['stopPrice'];
        }
        if (isset($payload['workingType'])) {
            $params['workingType'] = $payload['workingType'];
        }
        if (! empty($payload['positionSide'])) {
            $params['positionSide'] = strtoupper((string) $payload['positionSide']);   // required in hedge mode
        }

        if (filter_var($payload['closePosition'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $params['closePosition'] = 'true';
        } else {
            foreach (['quantity', 'reduceOnly', 'price', 'timeInForce', 'priceProtect'] as $key) {
                if (isset($payload[$key])) {
                    $params[$key] = $payload[$key];
                }
            }
        }
        if (isset($payload['activationPrice'])) {
            $params['activatePrice'] = $payload['activationPrice'];
        } elseif (isset($payload['activatePrice'])) {
            $params['activatePrice'] = $payload['activatePrice'];
        }
        if (isset($payload['callbackRate'])) {
            $params['callbackRate'] = $payload['callbackRate'];
        }

        return $params;
    }

    /**
     * Normalise an algo order row (place ack / open list / query) into the field names the
     * legacy-order callers already read (orderId, clientOrderId, status, stopPrice, reduceOnly),
     * plus explicit `algo`/`endpoint` markers so cancel/query go to the right API.
     * A closePosition order is reported reduceOnly=true (as the legacy open-orders list did) so
     * "cancel every reduce-only order" logic also sees algo stops.
     */
    public static function normalize(array $row): array
    {
        $closePosition = filter_var($row['closePosition'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $algoId = isset($row['algoId']) ? (string) $row['algoId'] : '';

        return array_merge($row, [
            'orderId' => $algoId,
            'algoId' => $algoId,
            'clientOrderId' => (string) ($row['clientAlgoId'] ?? ''),
            'type' => strtoupper((string) ($row['orderType'] ?? $row['type'] ?? '')),
            'status' => strtoupper((string) ($row['algoStatus'] ?? $row['status'] ?? '')),
            'stopPrice' => $row['triggerPrice'] ?? ($row['stopPrice'] ?? null),
            'reduceOnly' => $closePosition || filter_var($row['reduceOnly'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'algo' => true,
            'endpoint' => 'algo',
        ]);
    }
}
