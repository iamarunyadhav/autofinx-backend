<?php

namespace App\Domain\FutureTrade;

use App\Models\FutureTradeBotConfig;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Signal stability + idempotency.
 *
 * Responsibilities:
 *  - generate a deterministic signal_key from (symbol, side, timeframe, candle_open, fingerprint)
 *  - generate a deterministic Binance newClientOrderId from the signal_key
 *  - keep a small rolling buffer of recent raw signals to compute CONFIRMED status:
 *      RAW       — a side just appeared on one scan
 *      CANDIDATE — same side for >=2 consecutive scans within the candle
 *      CONFIRMED — same side persisted to candle close OR >=N consecutive scans
 *      EXECUTABLE— CONFIRMED + closed-candle-aligned + no hard blockers
 *      UNSTABLE  — direction flipped within the same candle
 */
class FutureTradeSignalStabilityService
{
    private const RECENT_BUFFER_TTL = 600;  // seconds
    public const DEFAULT_CONFIRM_SCANS = 2;
    public const SAME_CANDLE_BLOCK_KEY = 'autofinx_ft:same_candle:%s:%s:%s:%s';

    public function generateSignalKey(string $symbol, string $side, string $timeframe, ?string $candleOpenIso, array $fingerprint = []): string
    {
        $candle = $candleOpenIso ?: $this->currentCandleOpenIso($timeframe);
        $payload = [
            'sym' => strtoupper($symbol),
            'side' => strtoupper($side),
            'tf' => strtolower($timeframe),
            'candle' => $candle,
            'fp' => $fingerprint,
        ];
        return substr(hash('sha256', json_encode($payload)), 0, 48);
    }

    /**
     * Deterministic newClientOrderId so a retry never produces a second exchange order.
     * Binance limits clientOrderId to 36 chars (algo clientAlgoId: ^[.A-Z:/a-z0-9_-]{1,36}$) and
     * requires it to be unique among OPEN orders, so every order kind gets its own prefix:
     *
     *   entry     ftx-entry-<key24>      (UNCHANGED format: idempotency / dedupe / matching rely on it)
     *   exit      ftx-exit-<key24>
     *   sl        ftx-sl-<key24>         initial exchange-side stop loss
     *   tp        ftx-tp-<key24>         exchange-side take profit
     *   sl-t<N>   ftx-tsl-<N>-<key>      N-th trailing SL replacement (N >= 1)
     *
     * Trailing uniqueness invariant (see FutureTradeBotService::maybeUpdateExchangeTrailingSL):
     * N = persisted sl_update_count + 1 and is persisted only after the new stop is placed; the
     * previous stop (initial `ftx-sl-` or `ftx-tsl-<N-1>-`) is cancelled BEFORE the new one is
     * placed and an uncancellable previous stop aborts the replacement. So an id is never placed
     * while an order with the same id is still open, and a different N or kind is always a
     * different id even if a cancel were delayed.
     *
     * Legacy (before 2026-10-10) every kind but `exit` produced `ftx-entry-<key24>`; see
     * FutureTradeUserStreamService::protectiveFillKind() for how those old ids are still recognised.
     */
    public function deterministicClientOrderId(string $signalKey, string $kind = 'entry'): string
    {
        $kind = strtolower($kind);
        $short = substr($signalKey, 0, 24);

        if ($kind === 'entry' || $kind === 'exit') {
            return substr(sprintf('ftx-%s-%s', $kind, $short), 0, 36);
        }

        // Non-entry/exit kinds must satisfy the algo charset even if the signal key is not hex.
        $safe = preg_replace('/[^A-Za-z0-9_\-.:\/]/', '_', $short) ?? '';
        if ($safe === '') {
            throw new \InvalidArgumentException('Cannot derive a client order id from an empty signal key.');
        }

        if ($kind === 'sl' || $kind === 'tp') {
            return sprintf('ftx-%s-%s', $kind, $safe);
        }

        if (preg_match('/^sl-t(\d+)$/', $kind, $m) === 1 && (int) $m[1] >= 1) {
            $prefix = sprintf('ftx-tsl-%d-', (int) $m[1]);
            $room = 36 - strlen($prefix);
            if ($room < 12) {
                throw new \InvalidArgumentException('Trailing counter too large for a 36-char client order id.');
            }

            return $prefix.substr($safe, 0, $room);
        }

        throw new \InvalidArgumentException("Unknown Future Trade client order id kind '{$kind}'.");
    }

    /**
     * Which protective order a client order id belongs to by its prefix: 'SL' (initial or
     * trailing), 'TP', or null (entry/exit/foreign/legacy shared id: not decidable by prefix).
     */
    public static function protectiveKindFromClientOrderId(string $clientOrderId): ?string
    {
        if (str_starts_with($clientOrderId, 'ftx-sl-') || str_starts_with($clientOrderId, 'ftx-tsl-')) {
            return 'SL';
        }
        if (str_starts_with($clientOrderId, 'ftx-tp-')) {
            return 'TP';
        }

        return null;
    }

    /**
     * Record a raw scanner observation; returns the rolling state for the symbol.
     *
     * @param array{symbol:string,side:string,timeframe:string,candle_open?:?string,confidence?:float} $signal
     */
    public function observeRaw(FutureTradeBotConfig $config, array $signal): array
    {
        $symbol = strtoupper((string) ($signal['symbol'] ?? ''));
        $side = strtoupper((string) ($signal['side'] ?? $signal['signal'] ?? 'WAIT'));
        $timeframe = strtolower((string) ($signal['timeframe'] ?? '5m'));
        $candleOpen = (string) ($signal['candle_open'] ?? $this->currentCandleOpenIso($timeframe));
        if ($symbol === '' || $side === '') {
            return $this->emptyState();
        }

        $bucketKey = sprintf('autofinx_ft:raw_buffer:%s:%s:%s', $config->id, $symbol, $timeframe);
        $buffer = (array) Cache::get($bucketKey, []);

        // discard stale buffer entries (older than 10 min)
        $cutoff = now()->subMinutes(10)->getTimestamp();
        $buffer = array_values(array_filter($buffer, fn ($row) => (int) ($row['ts'] ?? 0) >= $cutoff));

        $buffer[] = [
            'ts' => now()->getTimestamp(),
            'side' => $side,
            'candle' => $candleOpen,
            'confidence' => (float) ($signal['confidence'] ?? 0),
        ];
        // cap buffer size
        if (count($buffer) > 50) {
            $buffer = array_slice($buffer, -50);
        }
        Cache::put($bucketKey, $buffer, self::RECENT_BUFFER_TTL);

        return $this->summarize($buffer, $side, $candleOpen);
    }

    /**
     * Compute the public-friendly stability summary.
     *
     * @return array{
     *   raw:string,
     *   confirmed:string,
     *   executable:string,
     *   reason:string,
     *   confirmedScans:int,
     *   sameCandleFlip:bool
     * }
     */
    public function summarize(array $buffer, string $rawSide, string $candleOpen): array
    {
        if ($buffer === []) {
            return $this->emptyState();
        }
        $latest = end($buffer);
        $rawSide = strtoupper($rawSide);
        $confirmedScans = 0;
        $sawDirections = [];
        $sameCandleFlip = false;

        for ($i = count($buffer) - 1; $i >= 0; $i--) {
            $row = $buffer[$i];
            $side = strtoupper((string) ($row['side'] ?? 'WAIT'));
            if ($side === $rawSide && $side !== 'WAIT') {
                $confirmedScans++;
            } else {
                break;
            }
        }

        foreach ($buffer as $row) {
            if (strtoupper((string) ($row['candle'] ?? '')) === strtoupper($candleOpen)) {
                $sawDirections[strtoupper((string) ($row['side'] ?? 'WAIT'))] = true;
            }
        }
        // a flip in same candle = both BUY and SELL seen
        if (isset($sawDirections['BUY']) && isset($sawDirections['SELL'])) {
            $sameCandleFlip = true;
        }

        $confirmed = ($confirmedScans >= self::DEFAULT_CONFIRM_SCANS && $rawSide !== 'WAIT') ? $rawSide : 'WAIT';
        $executable = $confirmed;
        $reason = 'stable';

        if ($sameCandleFlip) {
            $confirmed = 'WAIT';
            $executable = 'WAIT';
            $reason = 'same_candle_flip_unstable';
        } elseif ($confirmedScans < self::DEFAULT_CONFIRM_SCANS) {
            $executable = 'WAIT';
            $reason = 'awaiting_confirmation_scans';
        }

        return [
            'raw' => $rawSide ?: 'WAIT',
            'confirmed' => $confirmed,
            'executable' => $executable,
            'reason' => $reason,
            'confirmedScans' => $confirmedScans,
            'sameCandleFlip' => $sameCandleFlip,
        ];
    }

    /**
     * Mark a symbol/side as "executed in this candle" so a re-entry in the same
     * candle is hard blocked.
     */
    public function rememberCandleExecution(string $configId, string $symbol, string $side, string $candleOpen, int $ttlSeconds = 900): void
    {
        $key = sprintf(self::SAME_CANDLE_BLOCK_KEY, $configId, strtoupper($symbol), strtoupper($side), $candleOpen);
        Cache::put($key, true, $ttlSeconds);
    }

    public function sameCandleExecuted(string $configId, string $symbol, string $side, string $candleOpen): bool
    {
        $key = sprintf(self::SAME_CANDLE_BLOCK_KEY, $configId, strtoupper($symbol), strtoupper($side), $candleOpen);
        return (bool) Cache::get($key, false);
    }

    public function currentCandleOpenIso(string $timeframe): string
    {
        $minutes = $this->timeframeMinutes($timeframe);
        $now = Carbon::now('UTC');
        $secondsPast = ($now->minute % $minutes) * 60 + $now->second;
        return $now->copy()->subSeconds($secondsPast)->startOfMinute()->toIso8601String();
    }

    public function previousClosedCandleOpenIso(string $timeframe): string
    {
        $minutes = $this->timeframeMinutes($timeframe);
        return Carbon::parse($this->currentCandleOpenIso($timeframe))->subMinutes($minutes)->toIso8601String();
    }

    private function timeframeMinutes(string $timeframe): int
    {
        $timeframe = strtolower(trim($timeframe));
        return match ($timeframe) {
            '1m' => 1,
            '3m' => 3,
            '5m' => 5,
            '15m' => 15,
            '30m' => 30,
            '1h' => 60,
            '2h' => 120,
            '4h' => 240,
            '1d' => 1440,
            default => 5,
        };
    }

    private function emptyState(): array
    {
        return [
            'raw' => 'WAIT',
            'confirmed' => 'WAIT',
            'executable' => 'WAIT',
            'reason' => 'no_data',
            'confirmedScans' => 0,
            'sameCandleFlip' => false,
        ];
    }
}
