<?php

namespace Database\Seeders;

use App\Models\Instrument;
use App\Models\InstrumentMapping;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds the four Pepperstone Metals & Energy instruments (Gold, Silver,
 * Platinum, Crude Oil) plus one instrument_mappings row each for
 * provider='pepperstone'.
 *
 * Idempotent via firstOrCreate() -- safe to run multiple times / alongside
 * DatabaseSeeder's other rows, and safe under RefreshDatabase in tests that
 * create their own Instrument rows directly (e.g.
 * tests/Feature/Pepperstone/PepperstoneConfigValidationTest.php), since
 * this seeder is never auto-run by the test suite (no $seed = true / no
 * $this->seed() call in TestCase).
 *
 * All tick_size/tick_value/contract_size/min_volume/volume_step/
 * margin_requirement values below are PLACEHOLDERS pending a real
 * Pepperstone account (Phase 0) -- see the create_pepperstone_tables
 * migration's docblock for instrument_mappings. Do not treat these as real
 * broker contract specs.
 */
class PepperstoneInstrumentSeeder extends Seeder
{
    public function run(): void
    {
        $instruments = [
            [
                'canonical_symbol' => 'XAUUSD',
                'market_type' => Instrument::MARKET_METAL,
                'name' => 'Gold',
                'quote_currency' => 'USD',
                'broker_symbol' => 'XAUUSD',
                'tick_size' => '0.01',
                'tick_value' => '1.00',
                'contract_size' => '100',
                'min_volume' => '0.01',
                'volume_step' => '0.01',
                'margin_requirement' => '0.01',
            ],
            [
                'canonical_symbol' => 'XAGUSD',
                'market_type' => Instrument::MARKET_METAL,
                'name' => 'Silver',
                'quote_currency' => 'USD',
                'broker_symbol' => 'XAGUSD',
                'tick_size' => '0.001',
                'tick_value' => '5.00',
                'contract_size' => '5000',
                'min_volume' => '0.01',
                'volume_step' => '0.01',
                'margin_requirement' => '0.02',
            ],
            [
                'canonical_symbol' => 'XPTUSD',
                'market_type' => Instrument::MARKET_METAL,
                'name' => 'Platinum',
                'quote_currency' => 'USD',
                'broker_symbol' => 'XPTUSD',
                'tick_size' => '0.01',
                'tick_value' => '1.00',
                'contract_size' => '100',
                'min_volume' => '0.01',
                'volume_step' => '0.01',
                'margin_requirement' => '0.03',
            ],
            [
                'canonical_symbol' => 'WTICOUSD',
                'market_type' => Instrument::MARKET_ENERGY,
                'name' => 'Crude Oil',
                'quote_currency' => 'USD',
                'broker_symbol' => 'WTICOUSD',
                'tick_size' => '0.01',
                'tick_value' => '10.00',
                'contract_size' => '1000',
                'min_volume' => '0.01',
                'volume_step' => '0.01',
                'margin_requirement' => '0.05',
            ],
        ];

        foreach ($instruments as $row) {
            $instrument = Instrument::firstOrCreate(
                ['canonical_symbol' => $row['canonical_symbol']],
                [
                    'id' => (string) Str::uuid(),
                    'market_type' => $row['market_type'],
                    'name' => $row['name'],
                    'quote_currency' => $row['quote_currency'],
                    'enabled' => true,
                ]
            );

            InstrumentMapping::firstOrCreate(
                ['instrument_id' => $instrument->id, 'provider' => 'pepperstone'],
                [
                    'id' => (string) Str::uuid(),
                    'broker_symbol' => $row['broker_symbol'],
                    'tick_size' => $row['tick_size'],
                    'tick_value' => $row['tick_value'],
                    'contract_size' => $row['contract_size'],
                    'min_volume' => $row['min_volume'],
                    'volume_step' => $row['volume_step'],
                    'margin_requirement' => $row['margin_requirement'],
                ]
            );
        }
    }
}
