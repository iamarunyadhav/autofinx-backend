<?php

namespace Database\Factories;

use App\Models\ExchangeConnection;
use App\Models\Workspace;
use App\Support\ExchangeSecretVault;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ExchangeConnection>
 */
class ExchangeConnectionFactory extends Factory
{
    protected $model = ExchangeConnection::class;

    public function definition(): array
    {
        $vault = app(ExchangeSecretVault::class);

        return [
            'id' => (string) Str::uuid(),
            'workspace_id' => Workspace::factory(),
            'name' => 'Binance Testnet',
            'provider' => 'binance',
            'exchange' => 'binance',
            'api_key_encrypted' => $vault->encrypt('test-api-key'),
            'api_secret_encrypted' => $vault->encrypt('test-api-secret'),
            'is_enabled' => true,
            'is_active' => true,
            'is_testnet' => true,
            'permissions' => ['trade'],
        ];
    }
}
