<?php

namespace Tests\Feature\Domains\Access;

use App\Domains\Access\Actions\SyncRolePermissions;
use App\Domains\Access\Models\Role;
use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * Oráculo de existencia cross-tenant: en las rutas de ajustes que reciben un
 * registro por id y validan un payload con FormRequest, la pertenencia al
 * tenant debe comprobarse ANTES de validar. Un id de otro tenant responde
 * exactamente igual (404) que un id inexistente, con payload vacío o válido.
 */
class SettingsExistenceOracleTest extends TestCase
{
    use AssertsTenantIsolation, RefreshDatabase;

    private const int MISSING_ID = 999_999;

    private User $adminA;

    private User $adminB;

    private Team $teamA;

    private Team $teamB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);

        // Dueños de su equipo personal → tenant_admin vía fallback legado.
        $this->adminA = User::factory()->create();
        $this->adminB = User::factory()->create();
        $this->teamA = $this->adminA->currentTeam;
        $this->teamB = $this->adminB->currentTeam;
    }

    public function test_role_update_answers_404_for_foreign_and_missing_ids_alike(): void
    {
        $roleB = $this->customRoleOf($this->teamB);

        $payloads = [
            'vacío' => [],
            'válido' => ['name' => 'Hackeado', 'permissions' => ['incidents.view']],
        ];

        foreach ($payloads as $label => $payload) {
            $foreign = $this->assertNoTenantLeak($this->teamA, fn () => $this->updateRole($roleB->id, $payload)->status());
            $missing = $this->updateRole(self::MISSING_ID, $payload)->status();

            $this->assertSame(404, $foreign, "Rol ajeno con payload {$label}");
            $this->assertSame($missing, $foreign, "Rol ajeno vs inexistente con payload {$label}");
        }

        $this->assertNotSame('Hackeado', $roleB->fresh()?->name);
        $this->assertSame(['incidents.view'], $roleB->permissions()->pluck('code')->all());
    }

    public function test_role_update_still_works_for_own_role(): void
    {
        $roleA = $this->customRoleOf($this->teamA);

        $this->updateRole($roleA->id, ['name' => 'Turno día', 'permissions' => ['incidents.view', 'audit.view']])
            ->assertRedirect();

        $this->assertSame('Turno día', $roleA->fresh()?->name);
        $this->assertEqualsCanonicalizing(['incidents.view', 'audit.view'], $roleA->permissions()->pluck('code')->all());

        // Validación intacta sobre un rol propio.
        $this->updateRole($roleA->id, [])->assertSessionHasErrors('permissions');
    }

    public function test_member_role_update_answers_404_for_foreign_and_missing_ids_alike(): void
    {
        $colleagueB = User::factory()->create();
        $this->teamB->members()->attach($colleagueB, ['role' => TeamRole::Member->value]);
        $membershipB = $this->membershipOf($this->teamB, $colleagueB);

        $payloads = [
            'vacío' => [],
            'válido' => ['role_code' => 'viewer'],
        ];

        foreach ($payloads as $label => $payload) {
            $foreign = $this->assertNoTenantLeak($this->teamA, fn () => $this->updateMemberRole($membershipB->id, $payload)->status());
            $missing = $this->updateMemberRole(self::MISSING_ID, $payload)->status();

            $this->assertSame(404, $foreign, "Membresía ajena con payload {$label}");
            $this->assertSame($missing, $foreign, "Membresía ajena vs inexistente con payload {$label}");
        }

        $this->assertNull($membershipB->fresh()?->role_id);
    }

    public function test_member_role_update_still_works_for_own_membership(): void
    {
        $colleagueA = User::factory()->create();
        $this->teamA->members()->attach($colleagueA, ['role' => TeamRole::Member->value]);
        $membershipA = $this->membershipOf($this->teamA, $colleagueA);

        $this->updateMemberRole($membershipA->id, [])->assertSessionHasErrors('role_code');

        $this->updateMemberRole($membershipA->id, ['role_code' => 'viewer'])->assertRedirect();

        $this->assertSame(Role::where('code', 'viewer')->value('id'), $membershipA->fresh()?->role_id);
    }

    public function test_team_member_update_answers_404_for_non_members_and_missing_users_alike(): void
    {
        $payloads = [
            'vacío' => [],
            'válido' => ['role' => TeamRole::Admin->value],
        ];

        foreach ($payloads as $label => $payload) {
            $foreign = $this->assertNoTenantLeak($this->teamA, fn () => $this->updateTeamMember($this->adminB->id, $payload)->status());
            $missing = $this->updateTeamMember(self::MISSING_ID, $payload)->status();

            $this->assertSame(404, $foreign, "Usuario ajeno con payload {$label}");
            $this->assertSame($missing, $foreign, "Usuario ajeno vs inexistente con payload {$label}");
        }

        $this->assertSame(TeamRole::Owner, $this->adminB->teamRole($this->teamB));
        $this->assertFalse($this->adminB->belongsToTeam($this->teamA));
    }

    public function test_team_member_update_still_works_for_own_member(): void
    {
        $member = User::factory()->create();
        $this->teamA->members()->attach($member, ['role' => TeamRole::Member->value]);

        $this->updateTeamMember($member->id, [])->assertSessionHasErrors('role');

        $this->updateTeamMember($member->id, ['role' => TeamRole::Admin->value])
            ->assertRedirect(route('teams.edit', $this->teamA));

        $this->assertSame(TeamRole::Admin, $member->teamRole($this->teamA));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    private function updateRole(int $roleId, array $payload): TestResponse
    {
        return $this->actingAs($this->adminA)->put(route('access.roles.update', [
            'current_team' => $this->teamA->slug,
            'role' => $roleId,
        ]), $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    private function updateMemberRole(int $membershipId, array $payload): TestResponse
    {
        return $this->actingAs($this->adminA)->put(route('access.members.role.update', [
            'current_team' => $this->teamA->slug,
            'membership' => $membershipId,
        ]), $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    private function updateTeamMember(int $userId, array $payload): TestResponse
    {
        return $this->actingAs($this->adminA)->patch(route('teams.members.update', [$this->teamA, $userId]), $payload);
    }

    private function customRoleOf(Team $team, string $slug = 'turno-noche'): Role
    {
        $role = Role::factory()->create([
            'team_id' => $team->id,
            'code' => Role::customCodeFor($team->id, $slug),
            'is_system' => false,
        ]);

        app(SyncRolePermissions::class)->execute($role, ['incidents.view']);

        return $role;
    }

    private function membershipOf(Team $team, User $user): Membership
    {
        return Membership::query()->where('team_id', $team->id)->where('user_id', $user->id)->firstOrFail();
    }
}
