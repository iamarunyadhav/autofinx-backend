<?php

namespace App\Domain\Ops;

use App\Models\Runbook;
use Illuminate\Database\Eloquent\Collection;

class RunbookService
{
    public function list(?string $workspaceId = null, ?string $category = null): Collection
    {
        return Runbook::query()
            ->when($workspaceId, fn ($query, $workspaceId) => $query->where(function ($scope) use ($workspaceId): void {
                $scope->whereNull('workspace_id')->orWhere('workspace_id', $workspaceId);
            }), fn ($query) => $query->whereNull('workspace_id'))
            ->when($category, fn ($query, $category) => $query->where('category', $category))
            ->where('is_active', true)
            ->orderBy('severity')
            ->orderBy('title')
            ->get();
    }

    public function find(string $key, ?string $workspaceId = null): ?Runbook
    {
        return Runbook::query()
            ->when($workspaceId, fn ($query, $workspaceId) => $query->where(function ($scope) use ($workspaceId): void {
                $scope->whereNull('workspace_id')->orWhere('workspace_id', $workspaceId);
            }), fn ($query) => $query->whereNull('workspace_id'))
            ->where('key', $key)
            ->where('is_active', true)
            ->first();
    }
}
