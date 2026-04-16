<?php

namespace App\Jobs;

use App\Domain\Exchange\BinanceSpotClient;
use App\Domain\RatioBot\RatioBotDecisionService;
use App\Domain\RatioBot\RatioBotExecutionService;
use App\Domain\RatioBot\RatioBotStateService;
use App\Models\ExchangeConnection;
use App\Models\RatioBotConfig;
use App\Models\RatioBotDecision;
use App\Models\RatioBotState;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * RunRatioBotEvaluationJob
 *
 * Scheduled (or manually triggered) evaluation of a ratio bot config.
 *
 * Safety:
 * - WithoutOverlapping per config ID prevents double evaluation
 * - tries = 1 — a ratio bot must not blindly retry; caller re-dispatches on schedule
 * - All ordering passes through RatioBotExecutionService → LiveExecutionGuard
 */
class RunRatioBotEvaluationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 60;

    public function __construct(
        public readonly string $ratioBotConfigId,
        public readonly bool $manualTrigger = false,
    ) {
        $this->onQueue('ratio_bot');
    }

    /**
     * WithoutOverlapping ensures only ONE evaluation runs per config at a time.
     * expireAfter(55): if the job gets stuck, release the lock after 55 seconds.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('ratio-bot-eval:' . $this->ratioBotConfigId))->expireAfter(55),
        ];
    }

    public function handle(
        RatioBotDecisionService  $decisionService,
        RatioBotExecutionService $executionService,
        RatioBotStateService     $stateService,
    ): void {
        $config = RatioBotConfig::with('state')->find($this->ratioBotConfigId);

        if (! $config) {
            Log::warning('[RatioBotJob] Config not found', ['id' => $this->ratioBotConfigId]);
            return;
        }

        if (! $config->enabled && ! $this->manualTrigger) {
            Log::debug('[RatioBotJob] Skipping disabled bot', ['id' => $config->id]);
            return;
        }

        // Exit cooldown if expired
        $stateService->exitCooldownIfExpired($config);
        $config->refresh();

        // Fetch current price
        $price = $this->fetchPrice($config);
        if ($price === null) {
            Log::warning('[RatioBotJob] Could not fetch price', ['symbol' => $config->symbol]);
            return;
        }

        Log::info('[RatioBotJob] Running evaluation', [
            'config_id'     => $config->id,
            'symbol'        => $config->symbol,
            'current_price' => $price,
            'manual'        => $this->manualTrigger,
        ]);

        // Run decision engine
        $result = $decisionService->evaluate($config, $price);
        $decision = $result['decision_record'];

        // Route to execution only for trade decisions
        if (in_array($result['decision'], [RatioBotDecision::DECISION_BUY, RatioBotDecision::DECISION_SELL], true)) {
            if ($config->isAutoMode() || $this->manualTrigger) {
                $executionService->execute($config, $decision);
            } else {
                // Non-auto mode: record decision as pending approval
                $decision->update([
                    'execution_status' => RatioBotDecision::EXEC_SKIPPED,
                    'reason'           => 'mode=' . $config->mode . ' requires manual approval',
                ]);
                Log::info('[RatioBotJob] Trade decision requires approval', [
                    'config_id' => $config->id,
                    'decision'  => $result['decision'],
                    'ratio'     => $result['ratio'],
                ]);
            }
        }
    }

    private function fetchPrice(RatioBotConfig $config): ?float
    {
        if ($config->isSandbox()) {
            // In sandbox, use the public Binance price endpoint (no auth needed)
            try {
                $baseUrl = config('services.binance.base_url', 'https://api.binance.com');
                $wsUrl   = config('services.binance.ws_url', 'wss://stream.binance.com:9443');

                // Use a dummy client just for the public price endpoint
                // (BinanceSpotClient will throw if no credentials — we catch below)
                // Instead, use Http directly for the public endpoint
                $response = \Illuminate\Support\Facades\Http::get($baseUrl . '/api/v3/ticker/price', [
                    'symbol' => strtoupper($config->symbol),
                ]);
                if ($response->successful()) {
                    return (float) $response->json('price');
                }
            } catch (Throwable $e) {
                Log::warning('[RatioBotJob] Public price fetch failed', ['error' => $e->getMessage()]);
            }
            return null;
        }

        // Live: use authenticated client
        $connectionId = $config->connection_id;
        if (! $connectionId) {
            return null;
        }

        $conn = ExchangeConnection::find($connectionId);
        if (! $conn || ! $conn->is_active) {
            return null;
        }

        try {
            $client = new BinanceSpotClient(
                apiKey:    decrypt($conn->api_key_encrypted ?? $conn->api_key),
                apiSecret: decrypt($conn->api_secret_encrypted ?? $conn->api_secret),
                baseUrl:   config('services.binance.base_url', 'https://api.binance.com'),
                wsUrl:     config('services.binance.ws_url', 'wss://stream.binance.com:9443'),
            );
            return $client->getLatestPrice($config->symbol);
        } catch (Throwable $e) {
            Log::warning('[RatioBotJob] Authenticated price fetch failed', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
