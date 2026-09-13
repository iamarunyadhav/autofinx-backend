<?php

namespace App\Jobs;

use App\Domain\Pepperstone\PepperstoneOhlcBackfillService;
use App\Domain\Venue\PepperstoneBrokerAdapter;
use App\Domain\Venue\TradingVenueClientFactory;
use App\Models\ExchangeConnection;
use App\Models\Instrument;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Multi-timeframe market_ohlc backfill for one Pepperstone instrument.
 * Same `pepperstone` queue as RunPepperstoneBotEvaluationJob (no dedicated
 * scheduler entry yet -- RunPepperstoneBotEvaluationJob itself has none
 * either, only manual/controller-triggered dispatch, so this follows the
 * same convention for consistency until a real scheduling decision is made).
 */
class PepperstoneOhlcBackfillJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 60;

    public function __construct(
        public readonly string $instrumentId,
        public readonly ?string $connectionId = null,
    ) {
        $this->onQueue('pepperstone');
    }

    public function handle(TradingVenueClientFactory $clients, PepperstoneOhlcBackfillService $backfill): void
    {
        $instrument = Instrument::find($this->instrumentId);
        if (! $instrument) {
            Log::warning('[PepperstoneOhlcBackfillJob] Instrument not found', ['id' => $this->instrumentId]);
            return;
        }

        $connection = $this->connectionId ? ExchangeConnection::find($this->connectionId) : null;

        try {
            $venue = $clients->forProvider('pepperstone', $connection);
        } catch (Throwable $e) {
            Log::warning('[PepperstoneOhlcBackfillJob] Could not resolve venue client', ['error' => $e->getMessage()]);
            return;
        }

        if (! $venue instanceof PepperstoneBrokerAdapter) {
            Log::warning('[PepperstoneOhlcBackfillJob] Resolved venue does not support historical candles', [
                'class' => get_class($venue),
            ]);
            return;
        }

        try {
            $results = $backfill->backfillAllTimeframes($venue, $instrument);
            Log::info('[PepperstoneOhlcBackfillJob] Backfill complete', ['instrument' => $instrument->canonical_symbol, 'results' => $results]);
        } catch (Throwable $e) {
            Log::warning('[PepperstoneOhlcBackfillJob] Backfill failed', ['error' => $e->getMessage()]);
        }
    }
}
