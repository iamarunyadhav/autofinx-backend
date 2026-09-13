<?php

namespace Tests\Feature\Pepperstone;

use App\Models\Instrument;
use App\Models\InstrumentMapping;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Read-only instrument catalogue listing that backs the Pepperstone Metals
 * & Energy page's instrument picker. Filterable by provider (via
 * instrument_mappings) and market_type.
 */
class InstrumentControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_enabled_instruments(): void
    {
        $user = User::factory()->create();

        $gold = Instrument::create([
            'canonical_symbol' => 'XAUUSD',
            'market_type' => 'metal',
            'name' => 'Gold',
            'quote_currency' => 'USD',
            'enabled' => true,
        ]);

        InstrumentMapping::create([
            'instrument_id' => $gold->id,
            'provider' => 'pepperstone',
            'broker_symbol' => 'XAUUSD',
            'tick_size' => '0.01',
            'tick_value' => '1.00',
            'contract_size' => '100',
            'min_volume' => '0.01',
            'volume_step' => '0.01',
            'margin_requirement' => '0.01',
        ]);

        Instrument::create([
            'canonical_symbol' => 'DISABLEDXYZ',
            'market_type' => 'metal',
            'name' => 'Disabled Instrument',
            'quote_currency' => 'USD',
            'enabled' => false,
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/instruments?provider=pepperstone');

        $response->assertOk();
        $response->assertJsonCount(1);
        $response->assertJsonFragment(['canonical_symbol' => 'XAUUSD']);
    }

    public function test_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/instruments');
        $response->assertUnauthorized();
    }
}
