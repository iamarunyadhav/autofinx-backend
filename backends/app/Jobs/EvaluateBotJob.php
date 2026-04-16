<?php

namespace App\Jobs;

use App\Domain\Exchange\ExchangeClientFactory;
use App\Domain\Market\MarketPriceCacheService;
use App\Domain\Market\MetalsMarketDataService;
use App\Domain\Ops\TradingEnvironmentGuard;
use App\Domain\Strategies\BotSignalGenerator;
use App\Domain\Trading\BotLogService;
use App\Domain\Trading\TradingModuleSettingsService;
use App\Models\Bot;
use App\Models\TradeSignal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class EvaluateBotJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $botId)
    {
        $this->onQueue('strategies');
    }

    public function handle(
        MarketPriceCacheService $prices,
        ExchangeClientFactory $clients,
        MetalsMarketDataService $metals,
        BotSignalGenerator $signals,
        TradingEnvironmentGuard $modeGuard,
        BotLogService $logs,
        TradingModuleSettingsService $settings,
    ): void {
        $bot = Bot::with('connection', 'riskProfile')->find($this->botId);
        if (! $bot || $bot->status !== 'RUNNING') {
            return;
        }

        $traceId = (string) Str::uuid();
        if (! $settings->automationEnabled((string) $bot->workspace_id)) {
            $bot->status = 'PAUSED';
            $bot->last_error = 'Auto trading is disabled for this workspace.';
            $bot->save();
            $logs->log($bot->id, $bot->workspace_id, 'WARN', 'Bot paused because auto trading is disabled for this workspace.', [], $traceId);

            return;
        }

        try {
            $modeGuard->assertBotMode($bot);
        } catch (\Throwable $e) {
            $bot->status = 'PAUSED';
            $bot->save();
            $logs->log($bot->id, $bot->workspace_id, 'WARN', 'Bot paused by trading environment guard.', [
                'error' => $e->getMessage(),
            ], $traceId);
            return;
        }

        $assetClass = strtoupper((string) ($bot->asset_class ?? 'CRYPTO'));
        $assetCode = strtoupper((string) ($bot->asset_code ?: $bot->symbol));
        $provider = strtolower((string) ($bot->provider ?: ($assetClass === 'METALS' ? 'metal_broker' : $bot->exchange)));

        $cached = $prices->getLatestPrice($provider, $assetCode);
        $price = (float) ($cached['price'] ?? 0);

        if ($price <= 0 && $assetClass === 'METALS') {
            try {
                $quote = $metals->getPrice($assetCode, 'USD', $provider);
                $price = (float) ($quote['usd']['mid'] ?? 0);
            } catch (\Throwable $e) {
                $logs->log($bot->id, $bot->workspace_id, 'WARN', 'Metals price fetch failed during bot evaluation.', [
                    'asset_code' => $assetCode,
                    'provider' => $provider,
                    'error' => $e->getMessage(),
                ], $traceId);
            }
        } elseif ($price <= 0 && $bot->connection_id && $bot->connection && $bot->connection->is_active) {
            try {
                $client = $clients->makeFromConnection($bot->connection);
                $price = $client->getLatestPrice($assetCode);
                if ($price > 0) {
                    $prices->setLatestPrice($provider, $assetCode, $price);
                }
            } catch (\Throwable $e) {
                $logs->log($bot->id, $bot->workspace_id, 'WARN', 'Price fetch failed during bot evaluation.', [
                    'error' => $e->getMessage(),
                ], $traceId);
            }
        }

        if ($price <= 0) {
            $logs->log($bot->id, $bot->workspace_id, 'WARN', 'Skipping evaluation: latest price unavailable.', [], $traceId);
            return;
        }

        $decision = $signals->evaluate($bot, $price);
        $bot->last_evaluated_at = now();
        $bot->save();
        if (! $decision) {
            return;
        }

        $signal = TradeSignal::create([
            'id' => (string) Str::uuid(),
            'bot_id' => $bot->id,
            'workspace_id' => $bot->workspace_id,
            'user_id' => $bot->user_id,
            'asset_class' => $assetClass,
            'asset_code' => $assetCode,
            'quote_currency' => strtoupper((string) ($bot->quote_currency ?: 'USD')),
            'provider' => $provider,
            'symbol' => $assetCode,
            'side' => strtoupper($decision['side']),
            'decision_price' => $decision['decision_price'],
            'decision_ts' => now(),
            'max_slippage_pct' => $decision['max_slippage_pct'],
            'ttl_ms' => $decision['ttl_ms'],
            'fill_timeout_ms' => $decision['fill_timeout_ms'],
            'exec_policy' => $decision['exec_policy'],
            'status' => 'PENDING',
            'trace_id' => $traceId,
            'meta_json' => [
                'qty' => $decision['qty'],
                'source' => 'EvaluateBotJob',
                'metal_type' => $bot->metal_type,
            ],
        ]);

        $logs->log($bot->id, $bot->workspace_id, 'INFO', 'Signal generated.', [
            'signal_id' => $signal->id,
            'side' => $signal->side,
            'decision_price' => $signal->decision_price,
        ], $traceId);

        $aiMode = strtoupper((string) env('AI_MODE', 'ADVISORY'));
        if ($aiMode !== 'OFF') {
            AiRecommendationJob::dispatch($bot->id, $aiMode === 'AUTO_TUNE')
                ->delay(now()->addSeconds(1));
        }

        ExecuteSignalJob::dispatch($signal->id);
    }
}
