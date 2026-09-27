<?php

namespace Tests\Feature\Teams;

use App\Domains\Assets\Models\Asset;
use App\Domains\Tenancy\Actions\DeleteTenant;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Un current_team_id que apunta a un team borrado o del que ya no eres
 * miembro no puede dejar el scope de tenant abierto (fail-open) ni seguir
 * dándote acceso a los datos de ese team.
 */
class StaleCurrentTeamTest extends TestCase
{
    use RefreshDatabase;

    private function userWithoutPersonalTeam(): User
    {
        $user = User::factory()->create();
        $personal = $user->personalTeam();
        $personal->memberships()->delete();
        $personal->forceDelete();
        $user->forceFill(['current_team_id' => null])->save();

        return $user->fresh();
    }

    private function tenantWith(User ...$members): Team
    {
        $team = Team::factory()->create();

        foreach ($members as $i => $member) {
            $team->members()->attach($member, ['role' => $i === 0 ? TeamRole::Owner->value : TeamRole::Member->value]);
            $member->switchTeam($team);
        }

        return $team;
    }

    public function test_deleting_a_tenant_moves_members_to_their_personal_team_or_null(): void
    {
        $withPersonal = User::factory()->create();
        $withoutPersonal = $this->userWithoutPersonalTeam();
        $team = $this->tenantWith($withPersonal, $withoutPersonal);

        app(DeleteTenant::class)->execute($team);

        $this->assertSame($withPersonal->personalTeam()->id, $withPersonal->fresh()->current_team_id);
        $this->assertNull($withoutPersonal->fresh()->current_team_id);
    }

    public function test_admin_tenant_deletion_resets_current_team_of_members(): void
    {
        $admin = User::factory()->create(['global_role' => 'super_admin']);
        $member = User::factory()->create();
        $team = $this->tenantWith($member);

        $this->actingAs($admin)->delete(route('admin.tenants.destroy', $team))->assertRedirect();

        $this->assertNotSame($team->id, $member->fresh()->current_team_id);
    }

    public function test_removing_a_member_without_personal_team_does_not_crash_and_clears_current_team(): void
    {
        $owner = User::factory()->create();
        $member = $this->userWithoutPersonalTeam();
        $team = $this->tenantWith($owner, $member);

        $this->actingAs($owner)
            ->delete(route('teams.members.destroy', [$team, $member]))
            ->assertRedirect(route('teams.edit', $team));

        $this->assertFalse($member->fresh()->belongsToTeam($team));
        $this->assertNull($member->fresh()->current_team_id);
    }

    public function test_admin_member_removal_clears_current_team_without_personal_team(): void
    {
        $admin = User::factory()->create(['global_role' => 'super_admin']);
        $owner = User::factory()->create();
        $member = $this->userWithoutPersonalTeam();
        $team = $this->tenantWith($owner, $member);

        $this->actingAs($admin)
            ->delete(route('admin.tenants.members.destroy', [$team, $member]))
            ->assertRedirect();

        $this->assertNull($member->fresh()->current_team_id);
    }

    public function test_current_team_is_null_when_membership_was_removed_out_of_band(): void
    {
        $member = User::factory()->create();
        $team = $this->tenantWith($member);
        Asset::factory()->create(['team_id' => $team->id]);

        // La membresía desaparece pero current_team_id sigue apuntando al team.
        $team->memberships()->where('user_id', $member->id)->delete();

        $this->actingAs($member->fresh());

        $this->assertNull(currentTeam());
        $this->assertNull(currentTeamId());
        $this->assertSame(0, Asset::query()->count());
    }

    public function test_authenticated_user_without_team_gets_no_tenant_rows(): void
    {
        $user = $this->userWithoutPersonalTeam();
        Asset::factory()->count(2)->create(['team_id' => Team::factory()->create()->id]);

        $this->actingAs($user);

        $this->assertSame(0, Asset::query()->count(), 'Sin team válido el scope debe cerrar, no abrir.');
    }

    public function test_super_admin_keeps_impersonated_team_and_unscoped_platform_view(): void
    {
        $operator = User::factory()->create(['global_role' => 'super_admin']);
        $team = Team::factory()->create();
        Asset::factory()->create(['team_id' => $team->id]);
        Asset::factory()->create(['team_id' => Team::factory()->create()->id]);

        $operator->forceSwitchTeam($team);
        $this->actingAs($operator);

        $this->assertSame($team->id, currentTeamId());
        $this->assertSame(1, Asset::query()->count());

        // Explicit platform-wide work stays possible.
        $this->assertSame(2, TenantContext::withoutTenant(fn () => Asset::query()->count()));
    }
}
