<?php

namespace App\Jobs;

use App\Domain\FutureTrade\FutureTradeBotService;
use App\Models\FutureTradeBotConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Executes a validated Pine webhook signal asynchronously so the HTTP
 * response returns to TradingView in < 100ms and never times out, while
 * the actual Binance order placement happens here on the queue worker.
 *
 * All safety guards run inside this job:
 *   - bot_disabled / daily loss hard block / cooldown
 *   - idempotency (cache-based dedup + signal-key lock)
 *   - Binance duplicate clientOrderId
 */
class ProcessPineWebhookSignalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(
        public readonly string $configId,
        public readonly array $payload,
        public readonly string $workspace,
        public readonly ?int $slotIndex = null,
    ) {
        // Use the same execution queue as other live-trade jobs so worker
        // priority is consistent and we don't need a separate supervisor entry.
        $this->onQueue('execution');
    }

    public function handle(FutureTradeBotService $bot): void
    {
        $config = FutureTradeBotConfig::with('state')->find($this->configId);
        if (! $config) {
            Log::warning('PINE_WEBHOOK_JOB_CONFIG_MISSING', [
                'configId' => $this->configId,
                'workspace' => $this->workspace,
                'symbol' => $this->payload['symbol'] ?? null,
                'signal' => $this->payload['signal'] ?? null,
            ]);

            return;
        }

        try {
            $result = $bot->ingestPineWebhook($config, $this->payload);

            Log::info('PINE_WEBHOOK_JOB_DONE', [
                'workspace' => $this->workspace,
                'configId' => $this->configId,
                'slotIndex' => $this->slotIndex,
                'accepted' => $result['accepted'] ?? false,
                'executed' => $result['executed'] ?? false,
                'execution' => $result['execution'] ?? null,
                'reason' => $result['reason'] ?? null,
                'symbol' => $result['symbol'] ?? ($this->payload['symbol'] ?? null),
                'signal' => $result['signal'] ?? ($this->payload['signal'] ?? null),
                'latency' => $result['latency'] ?? null,
            ]);
        } catch (Throwable $e) {
            Log::error('PINE_WEBHOOK_JOB_FAILED', [
                'workspace' => $this->workspace,
                'configId' => $this->configId,
                'symbol' => $this->payload['symbol'] ?? null,
                'signal' => $this->payload['signal'] ?? null,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('PINE_WEBHOOK_JOB_QUEUE_FAILED', [
            'workspace' => $this->workspace,
            'configId' => $this->configId,
            'symbol' => $this->payload['symbol'] ?? null,
            'signal' => $this->payload['signal'] ?? null,
            'message' => $e->getMessage(),
        ]);
    }
}
