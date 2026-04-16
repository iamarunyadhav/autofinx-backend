<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Domain\Advanced\VenueManager;

class VenuesSeedDefaultsCommand extends Command
{
    protected $signature = 'venues:seed-defaults';
    protected $description = 'Seed default venues (demo, binance, coinbase, bybit, kucoin, broker_stub)';

    public function handle(VenueManager $manager): int
    {
        $manager->seedDefaults();
        $this->info('Default venues seeded.');
        return self::SUCCESS;
    }
}
