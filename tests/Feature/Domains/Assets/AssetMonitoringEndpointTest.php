<?php

namespace Tests\Feature\Domains\Assets;

use App\Domains\Assets\Enums\AssetMonitoringState;
use App\Domains\Assets\Models\Asset;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AssetMonitoringEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);
    }

    private function memberOf(Team $team, TeamRole $role = TeamRole::Owner): User
    {
        $user = User::factory()->create();
        $team->members()->attach($user, ['role' => $role->value]);
        $user->switchTeam($team);

        return $user;
    }

    public function test_owner_switches_a_unit_on_and_sees_the_new_state_on_the_fleet_page(): void
    {
        $team = Team::factory()->create();
        $user = $this->memberOf($team);
        $asset = Asset::factory()->pendingMonitoring()->create(['team_id' => $team->id]);

        $this->actingAs($user)
            ->put(route('assets.monitoring.update', ['current_team' => $team->slug, 'asset' => $asset->id]), [
                'state' => 'monitored',
            ])
            ->assertRedirect();

        $this->assertSame(AssetMonitoringState::Monitored, $asset->fresh()->monitoring_state);

        $this->actingAs($user)
            ->get(route('assets.index', ['current_team' => $team->slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('assets/index')
                ->where('assets.0.monitoringState', 'monitored')
                ->where('monitoring.monitored', 1)
                ->where('monitoring.pending', 0)
                ->has('filterOptions.monitoring', 3));
    }

    public function test_bulk_switches_only_the_units_of_the_current_team(): void
    {
        $team = Team::factory()->create();
        $other = Team::factory()->create();
        $user = $this->memberOf($team);

        $mine = Asset::factory()->pendingMonitoring()->count(2)->create(['team_id' => $team->id]);
        $foreign = Asset::factory()->pendingMonitoring()->create(['team_id' => $other->id]);

        $this->actingAs($user)
            ->put(route('assets.monitoring.bulk', ['current_team' => $team->slug]), [
                'state' => 'monitored',
                'asset_ids' => [...$mine->pluck('id')->all(), $foreign->id],
            ])
            ->assertRedirect();

        $this->assertSame(2, Asset::withoutGlobalScopes()->where('team_id', $team->id)->monitored()->count());
        $this->assertSame(AssetMonitoringState::Pending, $foreign->fresh()->monitoring_state, 'A foreign id must be ignored');
    }

    public function test_a_unit_of_another_tenant_is_not_found(): void
    {
        $team = Team::factory()->create();
        $other = Team::factory()->create();
        $user = $this->memberOf($team);
        $foreign = Asset::factory()->pendingMonitoring()->create(['team_id' => $other->id]);

        $this->actingAs($user)
            ->put(route('assets.monitoring.update', ['current_team' => $team->slug, 'asset' => $foreign->id]), [
                'state' => 'monitored',
            ])
            ->assertNotFound();

        $this->assertSame(AssetMonitoringState::Pending, $foreign->fresh()->monitoring_state);
    }

    public function test_a_plain_member_without_assets_manage_is_forbidden(): void
    {
        $team = Team::factory()->create();
        $user = $this->memberOf($team, TeamRole::Member);
        $asset = Asset::factory()->pendingMonitoring()->create(['team_id' => $team->id]);

        $this->actingAs($user)
            ->put(route('assets.monitoring.update', ['current_team' => $team->slug, 'asset' => $asset->id]), [
                'state' => 'monitored',
            ])
            ->assertForbidden();
    }

    public function test_the_fleet_page_filters_by_monitoring_state(): void
    {
        $team = Team::factory()->create();
        $user = $this->memberOf($team);
        Asset::factory()->create(['team_id' => $team->id, 'name' => 'Vigilada']);
        Asset::factory()->pendingMonitoring()->create(['team_id' => $team->id, 'name' => 'Pendiente']);

        $this->actingAs($user)
            ->get(route('assets.index', ['current_team' => $team->slug, 'monitoring' => 'pending']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('assets/index')
                ->has('assets', 1)
                ->where('assets.0.name', 'Pendiente')
                ->where('filters.monitoring', 'pending')
                ->where('monitoring.pending', 1)
                ->where('monitoring.monitored', 1));
    }
}
