<?php

it('answers api preflight requests with configured cors headers', function () {
    config(['cors.allowed_origins' => ['https://autofinx.arunyadhav.dev']]);

    $response = $this->withHeaders([
        'Origin' => 'https://autofinx.arunyadhav.dev',
        'Access-Control-Request-Method' => 'POST',
        'Access-Control-Request-Headers' => 'content-type, authorization, x-requested-with',
    ])->optionsJson('/api/v1/auth/login');

    $response->assertNoContent();
    $response->assertHeader('Access-Control-Allow-Origin', 'https://autofinx.arunyadhav.dev');
    $response->assertHeader('Access-Control-Allow-Credentials', 'true');
    $response->assertHeader('Access-Control-Allow-Headers', 'content-type, authorization, x-requested-with');
});

