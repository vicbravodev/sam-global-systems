<?php

namespace Tests\Feature\Http\Admin;

use App\Domains\Assets\Models\Asset;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\Team;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Toda entrada del operador a un cliente queda auditada (también por URL
 * directa) y los espacios personales de otros usuarios no son navegables.
 */
class AdminImpersonationAuditTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['global_role' => 'super_admin']);
    }

    /**
     * @return list<array{action: string, team_id: int|null, via: mixed}>
     */
    private function impersonations(): array
    {
        return TenantContext::withoutTenant(fn () => AuditLog::query()
            ->where('action', 'impersonation.started')
            ->get()
            ->map(fn (AuditLog $log): array => [
                'action' => $log->action,
                'team_id' => $log->team_id,
                'via' => $log->metadata_json['via'] ?? null,
            ])->values()->all());
    }

    public function test_opening_a_client_url_directly_is_audited_once(): void
    {
        $admin = $this->superAdmin();
        $team = Team::factory()->create(['is_personal' => false]);

        $this->actingAs($admin)->get(route('dashboard', ['current_team' => $team->slug]))->assertOk();
        $this->actingAs($admin)->get(route('dashboard', ['current_team' => $team->slug]))->assertOk();

        $entries = $this->impersonations();
        $this->assertCount(1, $entries, 'Sólo el cambio de team se audita, no cada request.');
        $this->assertSame($team->id, $entries[0]['team_id']);
        $this->assertSame('direct_url', $entries[0]['via']);
    }

    public function test_the_impersonate_button_is_audited_without_a_duplicate_from_the_redirect(): void
    {
        $admin = $this->superAdmin();
        $team = Team::factory()->create(['is_personal' => false]);

        $this->actingAs($admin)
            ->followingRedirects()
            ->post(route('admin.impersonate.store', $team))
            ->assertOk();

        $this->assertCount(1, $this->impersonations());
    }

    public function test_the_impersonate_button_refuses_a_personal_workspace(): void
    {
        $admin = $this->superAdmin();
        $personal = User::factory()->create()->personalTeam();
        $this->assertNotNull($personal);

        $this->actingAs($admin)
            ->post(route('admin.impersonate.store', $personal))
            ->assertRedirect(route('admin.tenants.index'))
            ->assertInertiaFlash('toast.type', 'error');

        $this->assertSame([], $this->impersonations());
        $this->assertNotSame($personal->id, $admin->fresh()?->current_team_id);
    }

    public function test_opening_another_users_personal_workspace_by_url_is_audited(): void
    {
        $admin = $this->superAdmin();
        $personal = User::factory()->create()->personalTeam();
        $this->assertNotNull($personal);

        $this->actingAs($admin)
            ->get(route('dashboard', ['current_team' => $personal->slug]))
            ->assertOk();

        $entries = $this->impersonations();
        $this->assertCount(1, $entries);
        $this->assertSame($personal->id, $entries[0]['team_id']);
    }

    public function test_the_operator_still_opens_their_own_personal_workspace(): void
    {
        $admin = $this->superAdmin();
        $personal = $admin->personalTeam();
        $this->assertNotNull($personal);

        $this->actingAs($admin)
            ->get(route('dashboard', ['current_team' => $personal->slug]))
            ->assertOk();

        $this->assertSame([], $this->impersonations());
    }

    public function test_the_directory_counts_never_mix_clients(): void
    {
        $a = Team::factory()->create(['is_personal' => false]);
        $b = Team::factory()->create(['is_personal' => false]);
        TenantIntegration::factory()->active()->count(2)->create(['team_id' => $a->id]);
        Asset::factory()->count(3)->create(['team_id' => $a->id]);

        $this->actingAs($this->superAdmin())
            ->get(route('admin.tenants.index'))
            ->assertInertia(function (Assert $page) use ($a, $b) {
                $rows = collect($page->toArray()['props']['tenants'])->keyBy('id');
                $this->assertSame(2, $rows[$a->id]['integrationsCount']);
                $this->assertSame(3, $rows[$a->id]['monitoredAssets']);
                $this->assertSame(0, $rows[$b->id]['integrationsCount']);
                $this->assertSame(0, $rows[$b->id]['monitoredAssets']);
            });
    }
}
