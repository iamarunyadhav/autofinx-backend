<?php

namespace Tests\Feature;

use App\Models\Ledger;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderFlowTest extends TestCase
{
    public function test_order_creates_trade_and_updates_ledger(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::create([
            'id' => (string) Str::uuid(),
            'name' => 'Test WS',
            'timezone' => 'Asia/Colombo',
        ]);

        WorkspaceMember::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $token = $user->createToken('auth')->plainTextToken;

        // ensure ledger exists
        Ledger::firstOrCreate([
            'workspace_id' => $workspace->id,
        ], [
            'id' => (string) Str::uuid(),
            'balance' => 100000,
        ]);

        $response = $this->withToken($token)->postJson('/api/v1/orders', [
            'workspace_id' => $workspace->id,
            'symbol' => 'BTCUSDT',
            'side' => 'buy',
            'order_type' => 'limit',
            'mode' => 'paper',
            'quantity' => 0.1,
            'price' => 30000,
        ])->assertCreated();

        $data = $response->json();
        $this->assertSame('paper', $data['mode']);
        $this->assertArrayHasKey('order', $data);
        $this->assertArrayHasKey('trade', $data);
        $this->assertArrayHasKey('balance', $data);

        // verify ledger updated
        $this->assertDatabaseHas('ledger_transactions', [
            'type' => 'order_paper',
            'ledger_id' => Ledger::where('workspace_id', $workspace->id)->value('id'),
        ]);
    }
}
