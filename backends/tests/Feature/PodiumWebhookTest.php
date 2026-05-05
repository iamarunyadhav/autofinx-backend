<?php

use Illuminate\Support\Facades\Log;

it('acknowledges podium webhook requests with 200', function () {
    Log::spy();

    $payload = [
        'id' => 'podium_evt_123',
        'type' => 'message.created',
        'data' => [
            'locationId' => 'loc_123',
        ],
    ];

    $this->postJson('/api/v1/podium/webhook', $payload, [
        'X-Podium-Event-Id' => 'podium_evt_123',
    ])->assertOk()
      ->assertJson([
          'ok' => true,
      ]);

    Log::shouldHaveReceived('info')->once();
});
