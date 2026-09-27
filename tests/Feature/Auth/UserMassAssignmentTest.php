<?php

namespace Tests\Feature\Auth;

use App\Domains\Tenancy\Actions\SetGlobalRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * global_role (super-admin) y current_team_id nunca deben llegar vía
 * asignación masiva: un fill() con input del request no puede escalar a
 * super-admin ni saltar a otro tenant.
 */
class UserMassAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_role_and_current_team_are_not_mass_assignable(): void
    {
        $user = User::factory()->create();
        $otherTeam = Team::factory()->create();
        $originalTeam = $user->current_team_id;

        $user->fill(['global_role' => 'super_admin', 'current_team_id' => $otherTeam->id])->save();

        $user->refresh();
        $this->assertNull($user->global_role);
        $this->assertSame($originalTeam, $user->current_team_id);
    }

    public function test_profile_update_cannot_escalate_to_super_admin(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'global_role' => 'super_admin',
            'current_team_id' => Team::factory()->create()->id,
        ]);

        $this->assertFalse($user->fresh()->isSuperAdmin());
    }

    public function test_set_global_role_and_switch_team_still_work(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $team->members()->attach($user, ['role' => 'member']);

        app(SetGlobalRole::class)->execute($user, true);
        $this->assertTrue($user->fresh()->isSuperAdmin());

        app(SetGlobalRole::class)->execute($user, false);
        $this->assertFalse($user->fresh()->isSuperAdmin());

        $this->assertTrue($user->switchTeam($team));
        $this->assertSame($team->id, $user->fresh()->current_team_id);
    }
}
