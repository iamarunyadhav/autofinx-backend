<?php

use App\Support\RiskGuardService;
use App\Models\Ledger;
use App\Models\Workspace;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('blocks when kill switch is active', function () {
    config(['risk.kill_switch' => true]);
    $service = app(RiskGuardService::class);
    $workspaceId = (string) Str::uuid();

    $this->expectException(HttpException::class);
    $service->enforceOrFail($workspaceId);
});

it('blocks when drawdown limit exceeded', function () {
    config([
        'risk.kill_switch' => false,
        'risk.max_drawdown_percent' => 10,
        'risk.max_daily_loss' => 0,
    ]);

    $workspaceId = (string) Str::uuid();
    Workspace::create([
        'id' => $workspaceId,
        'name' => 'WS',
        'timezone' => 'UTC',
    ]);
    $ledger = Ledger::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspaceId,
        'balance' => 80,
        'meta' => ['starting_balance' => 100],
    ]);

    $service = app(RiskGuardService::class);
    $this->expectException(HttpException::class);
    $service->enforceOrFail($workspaceId);
});
