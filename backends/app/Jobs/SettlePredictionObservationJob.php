<?php
namespace App\Jobs;
use App\Domain\Prediction\PredictionObservationSettlementService;
use App\Models\PredictionObservation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class SettlePredictionObservationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public function __construct(public readonly int $observationId, public readonly float $closedExpiryPrice) { $this->onQueue('analysis'); }
    public function handle(PredictionObservationSettlementService $settlement): void
    {
        $observation = PredictionObservation::query()->find($this->observationId);
        if ($observation !== null) $settlement->settle($observation, $this->closedExpiryPrice);
    }
}
