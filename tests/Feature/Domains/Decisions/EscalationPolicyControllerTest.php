<?php

namespace Tests\Feature\Domains\Decisions;

use App\Domains\Access\Actions\AssignRoleToMember;
use App\Domains\Decisions\Models\EscalationPolicy;
use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * API `decisions/escalation-policies` (index/store/update): camino feliz,
 * validación, permisos por rol y el IDOR real — un usuario del team B, con
 * SU slug, intentando editar la política del team A por id.
 */
class EscalationPolicyControllerTest extends TestCase
{
    use AssertsTenantIsolation, RefreshDatabase;

    private User $owner;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);

        $this->owner = User::factory()->create();
        $this->team = $this->owner->currentTeam;
    }

    public function test_index_lists_only_the_current_team_policies_ordered_by_name(): void
    {
        EscalationPolicy::factory()->create(['team_id' => $this->team->id, 'name' => 'Zeta']);
        EscalationPolicy::factory()->create(['team_id' => $this->team->id, 'name' => 'Alfa']);
        $foreign = EscalationPolicy::factory()->create(['team_id' => Team::factory()->create()->id, 'name' => 'Ajena']);

        $response = $this->actingAs($this->owner)
            ->getJson("/api/{$this->team->slug}/decisions/escalation-policies");

        $response->assertOk();
        $this->assertSame(['Alfa', 'Zeta'], array_column($response->json('data'), 'name'));
        $this->assertNotContains($foreign->id, array_column($response->json('data'), 'id'));
    }

    public function test_store_creates_a_policy_for_the_current_team(): void
    {
        $response = $this->actingAs($this->owner)->postJson(
            "/api/{$this->team->slug}/decisions/escalation-policies",
            [
                'code' => 'esc-panico',
                'name' => 'Pánico',
                'escalation_steps_json' => [['after_seconds' => 60, 'notify' => 'supervisor']],
                'max_wait_seconds' => 300,
                'requires_acknowledgement' => true,
            ],
        );

        $response->assertCreated()->assertJsonPath('data.code', 'esc-panico');
        $this->assertDatabaseHas('escalation_policies', [
            'team_id' => $this->team->id,
            'code' => 'esc-panico',
            'max_wait_seconds' => 300,
            'requires_acknowledgement' => true,
            'is_active' => true,
        ]);
    }

    public function test_store_rejects_invalid_payload_without_writing(): void
    {
        $this->actingAs($this->owner)->postJson(
            "/api/{$this->team->slug}/decisions/escalation-policies",
            ['code' => '', 'escalation_steps_json' => [], 'max_wait_seconds' => -5],
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['code', 'name', 'escalation_steps_json', 'max_wait_seconds']);

        $this->assertSame(0, EscalationPolicy::query()->withoutGlobalScopes()->count());
    }

    public function test_update_changes_an_own_policy(): void
    {
        $policy = EscalationPolicy::factory()->create(['team_id' => $this->team->id, 'name' => 'Vieja']);

        $this->actingAs($this->owner)->putJson(
            "/api/{$this->team->slug}/decisions/escalation-policies/{$policy->id}",
            ['name' => 'Nueva', 'is_active' => false],
        )->assertOk()->assertJsonPath('data.name', 'Nueva');

        $fresh = $policy->fresh();
        $this->assertSame('Nueva', $fresh->name);
        $this->assertFalse((bool) $fresh->is_active);
    }

    public function test_update_rejects_invalid_payload(): void
    {
        $policy = EscalationPolicy::factory()->create(['team_id' => $this->team->id, 'name' => 'Intacta']);

        $this->actingAs($this->owner)->putJson(
            "/api/{$this->team->slug}/decisions/escalation-policies/{$policy->id}",
            ['escalation_steps_json' => [], 'max_wait_seconds' => 'mucho'],
        )->assertUnprocessable()->assertJsonValidationErrors(['escalation_steps_json', 'max_wait_seconds']);

        $this->assertSame('Intacta', $policy->fresh()->name);
    }

    public function test_role_without_escalation_permission_cannot_create_or_update(): void
    {
        $viewer = $this->memberWithRole('viewer');
        $policy = EscalationPolicy::factory()->create(['team_id' => $this->team->id, 'name' => 'Intacta']);

        $this->actingAs($viewer)
            ->getJson("/api/{$this->team->slug}/decisions/escalation-policies")
            ->assertOk();

        $this->actingAs($viewer)->postJson(
            "/api/{$this->team->slug}/decisions/escalation-policies",
            ['code' => 'esc-x', 'name' => 'X', 'escalation_steps_json' => [['after_seconds' => 60]]],
        )->assertForbidden();

        $this->actingAs($viewer)->putJson(
            "/api/{$this->team->slug}/decisions/escalation-policies/{$policy->id}",
            ['name' => 'Hijack'],
        )->assertForbidden();

        $this->assertSame('Intacta', $policy->fresh()->name);
        $this->assertDatabaseMissing('escalation_policies', ['code' => 'esc-x']);
    }

    public function test_role_without_decisions_view_cannot_list(): void
    {
        $billing = $this->memberWithRole('billing_manager');

        $this->actingAs($billing)
            ->getJson("/api/{$this->team->slug}/decisions/escalation-policies")
            ->assertForbidden();
    }

    public function test_user_of_another_team_cannot_update_a_foreign_policy_through_its_own_slug(): void
    {
        $intruder = User::factory()->create();
        $intruderTeam = $intruder->currentTeam;
        $policy = EscalationPolicy::factory()->create([
            'team_id' => $this->team->id,
            'name' => 'Del team A',
            'is_active' => true,
        ]);

        $url = "/api/{$intruderTeam->slug}/decisions/escalation-policies/{$policy->id}";

        // Camino real, sin contexto de tenant previo.
        $plain = $this->actingAs($intruder)->putJson($url, ['name' => 'Hijack', 'is_active' => false]);
        $this->assertContains($plain->status(), [403, 404]);

        $response = $this->assertNoTenantLeak(
            $intruderTeam,
            fn () => $this->actingAs($intruder)->putJson($url, ['name' => 'Hijack', 'is_active' => false]),
        );

        $this->assertContains($response->status(), [403, 404]);
        $fresh = $policy->fresh();
        $this->assertSame('Del team A', $fresh->name);
        $this->assertTrue((bool) $fresh->is_active);
        $this->assertSame($this->team->id, $fresh->team_id);
    }

    private function memberWithRole(string $roleCode): User
    {
        $user = User::factory()->create();
        $this->team->members()->attach($user, ['role' => TeamRole::Member->value]);
        $user->forceFill(['current_team_id' => $this->team->id])->save();

        $membership = Membership::query()
            ->where('user_id', $user->id)
            ->where('team_id', $this->team->id)
            ->firstOrFail();

        app(AssignRoleToMember::class)->execute($membership, $roleCode);

        return $user->refresh();
    }
}
