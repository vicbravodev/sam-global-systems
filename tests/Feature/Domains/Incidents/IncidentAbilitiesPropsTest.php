<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Access\Models\Role;
use App\Domains\Incidents\Models\Incident;
use App\Models\Membership;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * La bandeja y el detalle de incidentes envían `can` con las acciones que el
 * rol permite, para que la UI no ofrezca botones que el servidor rechaza con
 * 403 (auditoría UI P1-5). El 403 del servidor se mantiene.
 */
class IncidentAbilitiesPropsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);
        $this->seed(IncidentsSeeder::class);
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create();

        Membership::query()
            ->where('team_id', $user->currentTeam->id)
            ->where('user_id', $user->id)
            ->firstOrFail()
            ->update([
                'role' => 'member',
                'role_id' => Role::query()->where('code', $roleCode)->firstOrFail()->id,
            ]);

        return $user;
    }

    public function test_viewer_gets_read_only_abilities_on_inbox(): void
    {
        $viewer = $this->userWithRole('viewer');

        $this->actingAs($viewer)
            ->get(route('incidents.index', ['current_team' => $viewer->currentTeam->slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('incidents/index')
                ->where('can.manage', false)
                ->where('can.resolve', false)
                ->where('can.close', false)
                ->where('can.reevaluate', false));
    }

    public function test_monitorista_can_manage_resolve_and_reevaluate(): void
    {
        $monitor = $this->userWithRole('monitorista');

        $this->actingAs($monitor)
            ->get(route('incidents.index', ['current_team' => $monitor->currentTeam->slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('incidents/index')
                ->where('can.manage', true)
                ->where('can.resolve', true)
                ->where('can.close', false)
                ->where('can.requestMedia', true)
                ->where('can.reevaluate', true));
    }

    public function test_detail_page_carries_abilities_for_the_viewer(): void
    {
        $viewer = $this->userWithRole('viewer');
        $incident = Incident::factory()->open()->create(['team_id' => $viewer->currentTeam->id]);

        $this->actingAs($viewer)
            ->get(route('incidents.show', [
                'current_team' => $viewer->currentTeam->slug,
                'incident' => $incident->id,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('incidents/show')
                ->where('can.manage', false)
                ->where('can.resolve', false));
    }

    public function test_viewer_is_still_rejected_by_the_server(): void
    {
        $viewer = $this->userWithRole('viewer');
        $incident = Incident::factory()->open()->create(['team_id' => $viewer->currentTeam->id]);

        $this->actingAs($viewer)
            ->postJson(route('incidents.claim', [
                'current_team' => $viewer->currentTeam->slug,
                'incident' => $incident->id,
            ]))
            ->assertForbidden();
    }

    public function test_team_owner_gets_every_ability(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->get(route('incidents.index', ['current_team' => $owner->currentTeam->slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.manage', true)
                ->where('can.resolve', true)
                ->where('can.close', true));
    }
}
