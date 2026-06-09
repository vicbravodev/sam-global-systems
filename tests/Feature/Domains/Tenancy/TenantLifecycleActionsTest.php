<?php

namespace Tests\Feature\Domains\Tenancy;

use App\Domains\Tenancy\Actions\DeleteTenant;
use App\Domains\Tenancy\Actions\SetGlobalRole;
use App\Domains\Tenancy\Actions\UpdateTenant;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class TenantLifecycleActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_tenant_changes_name_and_branding(): void
    {
        $team = Team::factory()->create(['is_personal' => false, 'name' => 'Old']);

        app(UpdateTenant::class)->execute($team, [
            'name' => 'Fresh',
            'display_name' => 'Fresh Co',
            'primary_color' => '#111111',
        ]);

        $this->assertSame('Fresh', $team->fresh()->name);
        $this->assertDatabaseHas('tenant_brandings', [
            'team_id' => $team->id,
            'display_name' => 'Fresh Co',
            'primary_color' => '#111111',
        ]);
    }

    public function test_delete_tenant_soft_deletes_non_personal(): void
    {
        $team = Team::factory()->create(['is_personal' => false]);

        app(DeleteTenant::class)->execute($team);

        $this->assertSoftDeleted('teams', ['id' => $team->id]);
    }

    public function test_delete_tenant_rejects_personal_team(): void
    {
        $team = Team::factory()->create(['is_personal' => true]);

        $this->expectException(RuntimeException::class);
        app(DeleteTenant::class)->execute($team);
    }

    public function test_set_global_role_grants_and_revokes(): void
    {
        $user = User::factory()->create();

        app(SetGlobalRole::class)->execute($user, true);
        $this->assertTrue($user->fresh()->isSuperAdmin());

        app(SetGlobalRole::class)->execute($user, false);
        $this->assertFalse($user->fresh()->isSuperAdmin());
    }
}
