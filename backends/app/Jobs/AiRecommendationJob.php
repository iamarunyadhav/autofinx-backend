<?php

namespace App\Jobs;

use App\Domain\Intelligence\MasterBrainService;
use App\Models\Bot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AiRecommendationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $botId,
        public readonly bool $autoApply = false,
    ) {
        $this->onQueue('strategies');
    }

    public function handle(MasterBrainService $brain): void
    {
        $bot = Bot::find($this->botId);
        if (! $bot) {
            return;
        }

        if ($brain->mode() === 'OFF') {
            return;
        }

        $brain->recommend($bot, $this->autoApply);
    }
}
