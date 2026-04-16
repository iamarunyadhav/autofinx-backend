<?php

namespace App\Domain\Advanced;

use App\Models\Venue;
use App\Models\VenueConnection;
use Illuminate\Support\Str;

class VenueManager
{
    public function seedDefaults(): void
    {
        $defaults = [
            ['name' => 'demo', 'type' => 'exchange', 'enabled' => true, 'meta_json' => ['latency_ms' => 5]],
            ['name' => 'binance', 'type' => 'exchange', 'enabled' => false],
            ['name' => 'coinbase', 'type' => 'exchange', 'enabled' => false],
            ['name' => 'bybit', 'type' => 'exchange', 'enabled' => false],
            ['name' => 'kucoin', 'type' => 'exchange', 'enabled' => false],
            ['name' => 'broker_stub', 'type' => 'broker', 'enabled' => false],
        ];
        foreach ($defaults as $def) {
            Venue::firstOrCreate(
                ['name' => $def['name']],
                $def + ['id' => (string) Str::uuid()]
            );
        }
    }

    public function testConnection(VenueConnection $connection): array
    {
        $ok = !empty($connection->api_key_encrypted);
        $connection->status_json = ['ok' => $ok, 'checked_at' => now()->toIso8601String()];
        $connection->last_tested_at = now();
        $connection->save();
        return $connection->status_json;
    }
}
