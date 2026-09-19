<?php

namespace App\Jobs;

use App\Domain\Trading\ForecastOutcomeEvaluator;
use App\Models\QuickTrade;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Low-priority research work; never runs on the execution request path. */
class EvaluateQuickTradeForecastOutcomeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Deliberately NOT redeclaring a $queue property here (pre-existing bug
     * found and fixed independently of this task's own scope): PHP rejects
     * ANY class-level redeclaration of a trait property with a different
     * default value -- not just a type mismatch -- as an incompatible
     * composition (fatal "definition differs" error, verified with a
     * minimal repro: a class overriding Illuminate\Bus\Queueable's own
     * untyped `public $queue;` with `public $queue = 'analytics';` still
     * fails the same way). This class never actually needed the property:
     * every real dispatch site already calls `->onQueue('analytics')`
     * explicitly (see QuickTradeService::settle()), which is what actually
     * sets the queue at runtime; the property was fully redundant.
     */
    public int $tries = 2;
    public int $timeout = 20;

    public function __construct(public readonly string $quickTradeId) {}

    public function handle(ForecastOutcomeEvaluator $evaluator): void
    {
        $trade = QuickTrade::find($this->quickTradeId);
        if (! $trade || $trade->status === 'open') return;

        try {
            $payload = (array) $trade->signal_payload;
            $snapshot = (array) ($payload['research'] ?? []);
            $samples = \App\Models\QuickTradeResearchSample::where('quick_trade_id', $trade->id)
                ->orderBy('quote_time_ms')->get()
                ->map(static fn ($row) => ['time_ms' => (int) $row->quote_time_ms, 'price' => (float) $row->price])
                ->all();
            $primary = $evaluator->evaluate((array) ($snapshot['primary'] ?? []), $samples);
            $payload['research']['outcome_evaluation'] = [
                'evaluated_at' => now()->toIso8601String(),
                'primary' => $primary,
                'trade_status' => $trade->status,
                'net_pnl' => $trade->profit_loss !== null ? (float) $trade->profit_loss : null,
            ];
            $trade->update(['signal_payload' => $payload]);
        } catch (Throwable $e) {
            Log::warning('[QuickTradeResearch] async outcome evaluation failed', [
                'quick_trade_id' => $this->quickTradeId, 'error' => $e->getMessage(),
            ]);
        }
    }
}
