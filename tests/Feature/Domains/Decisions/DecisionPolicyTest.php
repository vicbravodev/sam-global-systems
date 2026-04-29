<?php

namespace Tests\Feature\Domains\Decisions;

use App\Domains\Access\Enums\RoleScope;
use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Decisions\Enums\DecisionOutcomeCode;
use App\Domains\Decisions\Enums\DecisionPriority;
use App\Domains\Decisions\Models\Decision;
use App\Domains\Decisions\Models\DecisionOutcome;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Database\Seeders\DecisionOutcomeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DecisionPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);
        $this->seed(DecisionOutcomeSeeder::class);
    }

    public function test_member_without_permission_cannot_list_decisions(): void
    {
        [$user, $team] = $this->createUserWithRole('custom_no_access', []);

        $response = $this->actingAs($user)->getJson("/api/{$team->slug}/decisions");

        $response->assertForbidden();
    }

    public function test_member_with_view_can_list_decisions(): void
    {
        [$user, $team] = $this->createUserWithRole('custom_decisions_viewer', ['decisions.view']);

        $response = $this->actingAs($user)->getJson("/api/{$team->slug}/decisions");

        $response->assertOk();
    }

    public function test_member_with_view_cannot_override_decision(): void
    {
        [$user, $team] = $this->createUserWithRole('custom_view_only', ['decisions.view']);

        $decision = $this->makeDecision($team);

        $response = $this->actingAs($user)->postJson(
            "/api/{$team->slug}/decisions/{$decision->id}/override",
            [
                'new_outcome' => DecisionOutcomeCode::Incident->value,
                'reason' => 'Reviewed manually because the alert looked legit.',
            ],
        );

        $response->assertForbidden();
    }

    public function test_member_with_override_can_override_decision(): void
    {
        [$user, $team] = $this->createUserWithRole('custom_overrider', ['decisions.view', 'decisions.override']);

        $decision = $this->makeDecision($team);

        $response = $this->actingAs($user)->postJson(
            "/api/{$team->slug}/decisions/{$decision->id}/override",
            [
                'new_outcome' => DecisionOutcomeCode::Incident->value,
                'reason' => 'Reviewed manually because the alert looked legit.',
            ],
        );

        $response->assertCreated();
        $this->assertDatabaseHas('decision_overrides', [
            'decision_id' => $decision->id,
            'new_outcome' => DecisionOutcomeCode::Incident->value,
        ]);
    }

    public function test_cross_tenant_decision_returns_404_even_with_permission(): void
    {
        [$user, $team] = $this->createUserWithRole('custom_cross', ['decisions.view']);

        $foreignOwner = User::factory()->create();
        $foreignDecision = $this->makeDecision($foreignOwner->currentTeam);

        $response = $this->actingAs($user)->getJson("/api/{$team->slug}/decisions/{$foreignDecision->id}");

        $response->assertNotFound();
    }

    /**
     * @param  array<string>  $permissionCodes
     * @return array{0: User, 1: Team}
     */
    private function createUserWithRole(string $roleCode, array $permissionCodes): array
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $role = Role::factory()->create([
            'code' => $roleCode,
            'scope' => RoleScope::Tenant,
        ]);

        $permissionIds = [];
        foreach ($permissionCodes as $code) {
            $permission = Permission::firstOrCreate(
                ['code' => $code],
                [
                    'name' => ucfirst(str_replace('.', ' ', $code)),
                    'module' => explode('.', $code, 2)[0],
                ],
            );
            $permissionIds[] = $permission->id;
        }
        $role->permissions()->sync($permissionIds);

        $team->members()->updateExistingPivot($user->id, [
            'role' => TeamRole::Member->value,
            'role_id' => $role->id,
        ]);

        return [$user, $team];
    }

    private function makeDecision(Team $team): Decision
    {
        $event = NormalizedEvent::factory()->create(['team_id' => $team->id]);
        $eval = AIEventEvaluation::factory()->create([
            'normalized_event_id' => $event->id,
            'team_id' => $team->id,
        ]);
        $logOnly = DecisionOutcome::firstWhere('code', DecisionOutcomeCode::LogOnly->value);

        return Decision::withoutGlobalScopes()->create([
            'normalized_event_id' => $event->id,
            'team_id' => $team->id,
            'ai_evaluation_id' => $eval->id,
            'decision_code' => DecisionOutcomeCode::LogOnly->value,
            'decision_reason' => 'factory',
            'priority_level' => DecisionPriority::Normal,
            'requires_human_review' => false,
            'is_automated' => true,
            'outcome_id' => $logOnly->id,
            'decided_at' => now(),
        ]);
    }
}
