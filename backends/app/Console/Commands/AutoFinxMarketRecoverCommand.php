<?php

namespace App\Console\Commands;

use App\Domain\MarketData\Enums\StreamType;
use App\Domain\MarketData\MarketRecoveryManager;
use Illuminate\Console\Command;

class AutoFinxMarketRecoverCommand extends Command
{
    protected $signature = 'autofinx:market-recover {instrument} {--provider=binance} {--stream=QUOTE}';

    protected $description = 'Request controlled recovery for one market data stream.';

    public function handle(MarketRecoveryManager $recovery): int
    {
        $stream = StreamType::tryFrom(strtoupper((string) $this->option('stream'))) ?? StreamType::QUOTE;
        $state = $recovery->requestRecovery(
            strtolower((string) $this->option('provider')),
            strtoupper((string) $this->argument('instrument')),
            $stream,
            'operator_requested_recovery',
            ['command' => 'autofinx:market-recover'],
        );

        $this->info(sprintf(
            'Recovery requested: %s/%s/%s state=%s reason=%s',
            $state['provider'] ?? $this->option('provider'),
            $state['instrument'] ?? $this->argument('instrument'),
            $state['stream_type'] ?? $stream->value,
            $state['state'] ?? 'UNKNOWN',
            $state['reason'] ?? 'n/a',
        ));

        return self::SUCCESS;
    }
}
