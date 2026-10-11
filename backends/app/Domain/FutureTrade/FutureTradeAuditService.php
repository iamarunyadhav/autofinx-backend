<?php

namespace App\Domain\FutureTrade;

use App\Models\FutureTradeBotConfig;
use App\Models\FutureTradeOrderAudit;
use App\Models\FutureTradeWebhookAudit;
use App\Models\RobotSession;
use App\Models\RobotTradeAudit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class FutureTradeAuditService
{
    public function recordUnauthenticatedWebhookRequest(string $workspace, array $payload, array $context = []): FutureTradeWebhookAudit
    {
        $receivedAt = isset($context['receivedAt']) ? Carbon::parse((string) $context['receivedAt']) : now();
        $symbol = $this->normalizeSymbol((string) ($payload['symbol'] ?? $payload['ticker'] ?? ''));
        $signal = strtoupper(str_replace(['-', ' '], '_', trim((string) ($payload['signal'] ?? $payload['action'] ?? $payload['side'] ?? ''))));
        $barTime = $this->parseBarTime($payload);

        return FutureTradeWebhookAudit::create([
            'future_trade_bot_config_id' => null, 'workspace_id' => $workspace, 'source' => 'tradingview',
            'received_at' => $receivedAt, 'request_ip' => $context['remoteIp'] ?? null, 'token_valid' => false,
            'raw_payload' => $this->safePayload($payload), 'symbol' => $symbol ?: null,
            'timeframe' => (string) ($payload['timeframe'] ?? $payload['interval'] ?? ''),
            'signal' => $signal ?: null, 'normalized_signal' => $this->normalizeSignal($signal) ?: null,
            'price' => $this->positiveFloat($payload['price'] ?? $payload['close'] ?? null), 'bar_time' => $barTime,
            'bar_confirmed' => $this->barConfirmed($payload), 'profile' => (string) ($payload['profile'] ?? $payload['profile_id'] ?? ''),
            'latency_from_bar_close_ms' => $barTime ? max(0, $barTime->diffInMilliseconds($receivedAt, false)) : null,
            'validation_status' => 'rejected', 'reject_reason' => 'invalid_token',
            'context' => ['parseStatus' => $context['parseStatus'] ?? null, 'headersSummary' => $context['headersSummary'] ?? []],
        ]);
    }

    public function recordWebhookRequest(FutureTradeBotConfig $config, array $payload, array $context = []): FutureTradeWebhookAudit
    {
        $receivedAt = isset($context['receivedAt']) ? Carbon::parse((string) $context['receivedAt']) : now();
        $symbol = $this->normalizeSymbol((string) ($payload['symbol'] ?? $payload['ticker'] ?? ''));
        $signal = strtoupper(str_replace(['-', ' '], '_', trim((string) ($payload['signal'] ?? $payload['action'] ?? $payload['side'] ?? ''))));
        $normalizedSignal = $this->normalizeSignal($signal);
        $barTime = $this->parseBarTime($payload);
        $tokenValid = (string) ($context['tokenStatus'] ?? '') === 'valid' || (bool) ($context['tokenValid'] ?? false);

        return FutureTradeWebhookAudit::create([
            'future_trade_bot_config_id' => $config->id,
            'workspace_id' => $config->workspace_id,
            'source' => 'tradingview',
            'received_at' => $receivedAt,
            'request_ip' => $context['remoteIp'] ?? null,
            'token_valid' => $tokenValid,
            'raw_payload' => $this->safePayload($payload),
            'symbol' => $symbol ?: null,
            'timeframe' => (string) ($payload['timeframe'] ?? $payload['interval'] ?? ''),
            'signal' => $signal ?: null,
            'normalized_signal' => $normalizedSignal ?: null,
            'price' => $this->positiveFloat($payload['price'] ?? $payload['close'] ?? null),
            'bar_time' => $barTime,
            'bar_confirmed' => $this->barConfirmed($payload),
            'profile' => (string) ($payload['profile'] ?? $payload['profile_id'] ?? ''),
            'latency_from_bar_close_ms' => $barTime ? max(0, $barTime->diffInMilliseconds($receivedAt, false)) : null,
            'validation_status' => $tokenValid ? 'received' : 'rejected',
            'reject_reason' => $tokenValid ? null : 'invalid_token',
            'context' => [
                'parseStatus' => $context['parseStatus'] ?? null,
                'headersSummary' => $context['headersSummary'] ?? [],
                'rawBodyPreview' => isset($context['rawBody']) ? Str::limit((string) $context['rawBody'], 4000, '...') : null,
            ],
        ]);
    }

    public function markWebhookRejected(FutureTradeBotConfig $config, array $payload, string $reason, array $context = []): void
    {
        $audit = $this->latestWebhookAudit($config);
        if (! $audit) {
            $audit = $this->recordWebhookRequest($config, $payload, ['tokenValid' => true]);
        }

        $audit->update([
            'validation_status' => 'rejected',
            'reject_reason' => $this->mapRejectReason($reason),
            'decision' => 'BLOCKED',
            'decision_reason' => $reason,
            'context' => array_merge((array) $audit->context, ['rejectContext' => $context]),
        ]);
    }

    public function markWebhookDecision(FutureTradeBotConfig $config, array $signal, array $response): void
    {
        $audit = $this->latestWebhookAudit($config);
        if (! $audit) {
            return;
        }

        $execution = (string) ($response['execution'] ?? '');
        $decision = match (true) {
            (bool) ($response['executed'] ?? false) => 'EXECUTE',
            ($response['decision'] ?? null) === 'WAIT' || ($response['signal'] ?? null) === 'WAIT' => 'WAIT',
            str_contains($execution, 'close_only') => 'CLOSE_ONLY',
            default => 'BLOCKED',
        };

        $audit->update([
            'validation_status' => 'validated',
            'reject_reason' => null,
            'decision' => $decision,
            'decision_reason' => (string) ($response['reason'] ?? $response['blockReason'] ?? $execution ?: 'webhook_processed'),
            'total_latency_ms' => (int) data_get($response, 'latency.totalMs', 0) ?: null,
            'context' => array_merge((array) $audit->context, [
                'signal' => $signal,
                'response' => $response,
            ]),
        ]);
    }

    public function recordOrderRequest(FutureTradeBotConfig $config, array $payload, array $context = []): FutureTradeOrderAudit
    {
        $latestWebhook = $this->latestWebhookAudit($config);

        return FutureTradeOrderAudit::create([
            'future_trade_bot_config_id' => $config->id,
            'future_trade_webhook_audit_id' => $latestWebhook?->id,
            'workspace_id' => $config->workspace_id,
            'source' => (string) ($context['source'] ?? 'tradingview'),
            'order_request_at' => now(),
            'binance_symbol' => $payload['symbol'] ?? null,
            'side' => $payload['side'] ?? null,
            'position_side' => $context['positionSide'] ?? $payload['positionSide'] ?? null,
            'quantity' => $this->positiveFloat($payload['quantity'] ?? null),
            'leverage' => $this->positiveFloat($context['leverage'] ?? null),
            'margin_type' => $context['marginType'] ?? null,
            'reduce_only' => filter_var($payload['reduceOnly'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'order_type' => $payload['type'] ?? null,
            'price' => $this->positiveFloat($context['price'] ?? null),
            'stop_loss' => $this->positiveFloat($context['stopLoss'] ?? null),
            'take_profit' => $this->positiveFloat($context['takeProfit'] ?? null),
            'trailing_stop' => $this->positiveFloat($context['trailingStop'] ?? null),
            'request_payload' => $payload,
            'client_order_id' => $payload['newClientOrderId'] ?? null,
            'execution_status' => 'SUBMITTED',
            'context' => $context,
        ]);
    }

    public function markOrderSuccess(FutureTradeOrderAudit $audit, array $response): void
    {
        $status = strtoupper((string) ($response['status'] ?? 'ACK'));
        $audit->update([
            'response_payload' => $response,
            'binance_order_id' => isset($response['orderId']) ? (string) $response['orderId'] : null,
            'execution_status' => in_array($status, ['FILLED', 'PARTIALLY_FILLED', 'NEW'], true) ? 'SUCCESS' : $status,
        ]);
    }

    public function markOrderFailed(FutureTradeOrderAudit $audit, \Throwable $e): void
    {
        $audit->update([
            'execution_status' => 'FAILED',
            'error_code' => $this->extractErrorCode($e->getMessage()),
            'error_message' => $e->getMessage(),
        ]);
    }

    public function recordRobotDecision(RobotSession $session, array $candles, array $decision): void
    {
        if ($candles === []) {
            return;
        }

        $latest = $candles[array_key_last($candles)];
        $previous = count($candles) > 1 ? $candles[array_key_last(array_slice($candles, 0, -1, true))] : null;
        $open = (float) ($latest['open'] ?? 0);
        $close = (float) ($latest['close'] ?? 0);
        $high = (float) ($latest['high'] ?? max($open, $close));
        $low = (float) ($latest['low'] ?? min($open, $close));
        $body = abs($close - $open);

        RobotTradeAudit::create([
            'robot_session_id' => $session->id,
            'workspace_id' => $session->workspace_id,
            'symbol' => $session->symbol,
            'timeframe' => $session->timeframe,
            'candle_open_time' => isset($latest['opens_at']) ? Carbon::parse((string) $latest['opens_at']) : null,
            'candle_open_price' => $open ?: null,
            'current_price' => $close ?: null,
            'candle_body_size' => $body,
            'upper_wick' => max(0, $high - max($open, $close)),
            'lower_wick' => max(0, min($open, $close) - $low),
            'candle_direction' => $this->candleDirection($latest),
            'previous_candle_direction' => is_array($previous) ? $this->candleDirection($previous) : null,
            'trend_alignment' => data_get($decision, 'signal.trend_bias'),
            'noisy_candle' => (bool) data_get($decision, 'signal.noisy_candle', false),
            'robot_signal' => match ((string) ($decision['action'] ?? 'HOLD')) {
                'BUY', 'SELL', 'CLOSE' => (string) $decision['action'],
                default => 'WAIT',
            },
            'confidence_score' => $this->positiveFloat($decision['confidence'] ?? data_get($decision, 'signal.confidence')),
            'decision_reason' => (string) ($decision['reason'] ?? 'robot_decision'),
            'context' => ['decision' => $decision],
        ]);
    }

    private function latestWebhookAudit(FutureTradeBotConfig $config): ?FutureTradeWebhookAudit
    {
        return FutureTradeWebhookAudit::query()
            ->where('future_trade_bot_config_id', $config->id)
            ->latest('received_at')
            ->first();
    }

    private function normalizeSymbol(string $symbol): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/', '', str_replace('.P', '', strtoupper($symbol))) ?? '');
    }

    private function normalizeSignal(string $signal): string
    {
        return match ($signal) {
            'LONG' => 'BUY',
            'SHORT' => 'SELL',
            'CLOSE_LONG', 'CLOSE_SHORT', 'EXIT_LONG', 'EXIT_SHORT', 'FLAT_LONG', 'FLAT_SHORT', 'EXIT', 'FLAT' => 'CLOSE',
            'WATCH', 'HOLD', 'NEUTRAL' => 'WAIT',
            default => $signal,
        };
    }

    private function mapRejectReason(string $reason): string
    {
        return match ($reason) {
            'symbol_not_allowed' => 'unsupported_symbol',
            'timeframe_must_be_15m' => 'invalid_timeframe',
            'bar_not_confirmed' => 'missing_bar_confirmed',
            default => $reason,
        };
    }

    private function safePayload(array $payload): array
    {
        foreach (['token', 'secret', 'api_key', 'api_secret'] as $key) {
            if (array_key_exists($key, $payload)) {
                $payload[$key] = '[masked]';
            }
        }

        return $payload;
    }

    private function parseBarTime(array $payload): ?Carbon
    {
        foreach (['bar_time', 'candle_close', 'time', 'timestamp'] as $key) {
            if (! empty($payload[$key])) {
                try {
                    return Carbon::parse((string) $payload[$key]);
                } catch (\Throwable) {
                    return null;
                }
            }
        }

        return null;
    }

    private function barConfirmed(array $payload): ?bool
    {
        if (array_key_exists('bar_confirmed', $payload)) {
            return filter_var($payload['bar_confirmed'], FILTER_VALIDATE_BOOLEAN);
        }
        if (array_key_exists('confirmed', $payload)) {
            return filter_var($payload['confirmed'], FILTER_VALIDATE_BOOLEAN);
        }

        return null;
    }

    private function positiveFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $float = (float) $value;

        return $float > 0 ? $float : null;
    }

    private function candleDirection(array $candle): string
    {
        $open = (float) ($candle['open'] ?? 0);
        $close = (float) ($candle['close'] ?? 0);
        if ($open <= 0 || $close <= 0 || abs($close - $open) < 0.00000001) {
            return 'NEUTRAL';
        }

        return $close > $open ? 'UP' : 'DOWN';
    }

    private function extractErrorCode(string $message): ?string
    {
        return preg_match('/(-\d{3,6})/', $message, $matches) ? $matches[1] : null;
    }
}
