<?php

namespace Tests\Feature\MarketIntelligence;

use App\Models\User;
use App\Models\V5ResearchScannerCandidate;
use App\Models\V5ResearchScannerRun;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class V5ResearchApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_research_api_requires_authentication(): void
    {
        $this->getJson('/api/v5/research/overview')->assertUnauthorized();
    }

    public function test_authenticated_api_is_read_only_and_disables_execution(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/v5/research/overview')
            ->assertOk()->assertJsonPath('execution_authorized', false);
        $this->actingAs($user)->getJson('/api/v5/research/predictions')
            ->assertOk()->assertJsonPath('execution_authorized', false);
    }

    public function test_workspace_selection_uses_server_price_and_rejects_non_members(): void
    {
        [$user, $workspace] = $this->member();
        $outsider = User::factory()->create();
        $this->researchRun(['BTCUSDT' => 101.25]);

        $response = $this->actingAs($user)->postJson(
            "/api/workspaces/{$workspace->id}/v5/research/selections",
            ['symbol' => 'BTCUSDT', 'side' => 'LONG', 'selection_price' => 1]
        );

        $response->assertCreated()
            ->assertJsonPath('execution_authorized', false)
            ->assertJsonPath('selection.selection_price', 101.25)
            ->assertJsonPath('selection.workspace_id', $workspace->id);
        $this->actingAs($outsider)
            ->getJson("/api/workspaces/{$workspace->id}/v5/research/selections")
            ->assertForbidden();
    }

    public function test_workspace_selection_cap_is_enforced(): void
    {
        [$user, $workspace] = $this->member();
        $this->researchRun([
            'BTCUSDT' => 100.0,
            'ETHUSDT' => 200.0,
            'SOLUSDT' => 300.0,
            'XRPUSDT' => 400.0,
        ]);

        foreach (['BTCUSDT', 'ETHUSDT', 'SOLUSDT'] as $symbol) {
            $this->actingAs($user)->postJson(
                "/api/workspaces/{$workspace->id}/v5/research/selections",
                ['symbol' => $symbol, 'side' => 'LONG']
            )->assertCreated();
        }

        $this->actingAs($user)->postJson(
            "/api/workspaces/{$workspace->id}/v5/research/selections",
            ['symbol' => 'XRPUSDT', 'side' => 'SHORT']
        )->assertUnprocessable();
        $this->assertDatabaseCount('v5_research_selections', 3);
    }

    public function test_knowledge_items_are_individually_reviewed_and_only_approved_items_export(): void
    {
        [$user, $workspace] = $this->member();
        $base = "/api/workspaces/{$workspace->id}/v5/research/knowledge";

        $created = $this->actingAs($user)->postJson($base, [
            'title' => 'Closed-candle trend confirmation',
            'category' => 'TREND',
            'source_type' => 'VIDEO_TRANSCRIPT',
            'content' => 'Assess a trend only after the candle has closed.',
        ])->assertCreated()->assertJsonPath('item.status', 'PROPOSED')
            ->assertJsonPath('item.payload.training_eligible', false);

        $this->actingAs($user)->getJson($base.'/download?format=txt')->assertUnprocessable();

        $id = $created->json('item.id');
        $this->actingAs($user)->postJson($base."/{$id}/review", [
            'action' => 'APPROVED',
            'reason' => 'Reviewed against the supplied educational transcript.',
        ])->assertOk()->assertJsonPath('item.status', 'APPROVED')
            ->assertJsonPath('item.payload.training_eligible', false);

        $this->actingAs($user)->get($base.'/download?format=txt')
            ->assertOk()
            ->assertHeader('content-type', 'text/plain; charset=UTF-8')
            ->assertSeeText('Closed-candle trend confirmation')
            ->assertSeeText('Training eligible: NO');
    }

    public function test_non_owner_cannot_review_knowledge(): void
    {
        [$owner, $workspace] = $this->member();
        $member = User::factory()->create();
        WorkspaceMember::create(['workspace_id' => $workspace->id, 'user_id' => $member->id, 'role' => 'member']);
        $base = "/api/workspaces/{$workspace->id}/v5/research/knowledge";
        $item = $this->actingAs($owner)->postJson($base, [
            'title' => 'Risk rule', 'category' => 'RISK', 'content' => 'Use an explicit invalidation level.',
        ])->assertCreated()->json('item.id');

        $this->actingAs($member)->postJson($base."/{$item}/review", [
            'action' => 'APPROVED', 'reason' => 'Attempted review by a non-owner.',
        ])->assertForbidden();
    }

    /** @return array{User, Workspace} */
    private function member(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        WorkspaceMember::create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => 'owner']);

        return [$user, $workspace];
    }

    /** @param array<string, float> $prices */
    private function researchRun(array $prices): void
    {
        $run = V5ResearchScannerRun::create([
            'run_key' => hash('sha256', json_encode($prices)),
            'as_of' => now(),
            'source_mode' => 'fixture',
            'status' => 'completed',
            'requested_limit' => 3,
            'eligible_count' => count($prices),
            'source_status' => ['universe' => 'available'],
        ]);

        foreach ($prices as $index => $price) {
            V5ResearchScannerCandidate::create([
                'scanner_run_id' => $run->id,
                'symbol' => $index,
                'rank' => array_search($index, array_keys($prices), true) + 1,
                'ranking_score' => 1,
                'direction' => 'UP',
                'evidence' => ['assessment' => ['horizons' => ['15m' => ['reference_close' => $price]]]],
                'execution_authorized' => false,
            ]);
        }
    }
}
