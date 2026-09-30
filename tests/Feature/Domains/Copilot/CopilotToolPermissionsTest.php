<?php

namespace Tests\Feature\Domains\Copilot;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Copilot\Data\CopilotTurnScope;
use App\Domains\Copilot\Support\CopilotToolbox;
use App\Domains\Copilot\Support\CopilotTurnCollector;
use App\Models\Team;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Contracts\Tool;
use Tests\TestCase;

class CopilotToolPermissionsTest extends TestCase
{
    use CopilotFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessSeeder::class);
    }

    /**
     * @param  list<string>  $permissions
     * @return list<string>
     */
    private function toolNames(Team $team, array $permissions, bool $isSuperAdmin = false): array
    {
        return array_map(
            fn (Tool $t) => $t->name(),
            app(CopilotToolbox::class)->for(CopilotTurnScope::fromTeam($team, $permissions, $isSuperAdmin), new CopilotTurnCollector),
        );
    }

    public function test_viewer_without_incidents_permission_never_sees_incident_tools(): void
    {
        // Every built-in role that reads assets also reads incidents, so the
        // scope is built from an explicit permission list.
        $team = Team::factory()->create();
        $names = $this->toolNames($team, ['assets.view']);

        $this->assertContains('fleet_overview', $names);
        $this->assertContains('asset_location', $names);
        $this->assertNotContains('open_incidents', $names);
        $this->assertNotContains('panic_kpis', $names);
        $this->assertNotContains('asset_activity', $names);
        $this->assertNotContains('asset_media', $names);
        $this->assertNotContains('driver_ranking', $names);
        $this->assertContains('rank_assets', $names);
        $this->assertContains('suggest_followups', $names);
        $this->assertContains('find_assets', $names);
        $this->assertNotContains('search_events', $names);
        $this->assertNotContains('asset_timeline', $names);
    }

    public function test_no_permissions_means_no_tools(): void
    {
        $this->assertSame(['suggest_followups'], $this->toolNames(Team::factory()->create(), []));
    }

    public function test_supervisor_role_gets_every_existing_tool(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');
        $names = $this->toolNames($team, app(AuthorizeAction::class)->resolvePermissions($user, $team));

        $this->assertCount(15, $names);
    }

    public function test_super_admin_gets_every_tool(): void
    {
        $names = $this->toolNames(Team::factory()->create(), [], true);

        $this->assertCount(15, $names);
        $this->assertSame(array_unique($names), $names);
        $this->assertContains('open_incidents', $names);
        $this->assertContains('driver_ranking', $names);
        $this->assertContains('asset_media', $names);
    }
}
