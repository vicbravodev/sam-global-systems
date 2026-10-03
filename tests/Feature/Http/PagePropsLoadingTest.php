<?php

namespace Tests\Feature\Http;

use App\Domains\Assets\Models\Asset;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Tenancy\Models\TenantUsageCounter;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Support\Header;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * How the heavy pages load their props: expensive aggregates are deferred
 * (`Inertia::defer`) out of the first response, and the partial reloads the
 * frontend sends (live broadcasts, "Refrescar", deferred follow-ups) still
 * resolve exactly the keys they name.
 */
class PagePropsLoadingTest extends TestCase
{
    use AssertsTenantIsolation;
    use RefreshDatabase;

    public function test_dashboard_defers_its_aggregates_out_of_the_first_response(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $response = $this->actingAs($user)
            ->get(route('dashboard', ['current_team' => $team->slug]))
            ->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->has('incidents')
            ->has('stream')
            ->missing('kpis')
            ->missing('integrations')
            ->missing('usage'));

        $this->assertSame(
            ['kpis' => ['kpis'], 'panels' => ['integrations', 'usage']],
            $response->viewData('page')['deferredProps'],
        );
    }

    public function test_dashboard_live_reload_resolves_only_the_named_deferred_keys(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        Incident::factory()->open()->create(['team_id' => $team->id, 'opened_at' => now()->subHour()]);

        $url = route('dashboard', ['current_team' => $team->slug]);
        $initial = $this->actingAs($user)->get($url);

        // The realtime hook reloads `['kpis', 'stream']` on a decision.
        $props = $this->partialReload($initial, $url, 'dashboard', ['kpis', 'stream'])
            ->assertOk()
            ->json('props');

        $this->assertSame(1, $props['kpis']['openIncidents']['value']);
        $this->assertArrayHasKey('stream', $props);
        $this->assertArrayNotHasKey('incidents', $props);
        $this->assertArrayNotHasKey('integrations', $props);
        $this->assertArrayNotHasKey('usage', $props);
    }

    public function test_dashboard_deferred_aggregates_never_include_another_tenant(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $other = User::factory()->create()->currentTeam;

        Incident::factory()->open()->create(['team_id' => $other->id, 'opened_at' => now()->subHour()]);
        TenantIntegration::factory()->active()->create(['team_id' => $other->id]);
        TenantUsageCounter::factory()->create(['team_id' => $other->id]);

        $url = route('dashboard', ['current_team' => $team->slug]);
        $initial = $this->actingAs($user)->get($url);

        $props = $this->assertNoTenantLeak($team, fn () => $this
            ->partialReload($initial, $url, 'dashboard', ['kpis', 'integrations', 'usage'])
            ->assertOk()
            ->json('props'));

        $this->assertSame(0, $props['kpis']['openIncidents']['value']);
        $this->assertSame([], $props['integrations']);
        $this->assertSame([], $props['usage']);
    }

    public function test_fleet_page_defers_the_pulse_and_its_live_reload_resolves_it(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        Asset::factory()->count(2)->create(['team_id' => $team->id]);

        $url = route('assets.index', ['current_team' => $team->slug]);
        $initial = $this->actingAs($user)->get($url)->assertOk();

        $initial->assertInertia(fn (Assert $page) => $page
            ->component('assets/index')
            ->has('assets', 2)
            ->missing('summary')
            ->missing('monitoring'));
        $this->assertSame(
            ['pulse' => ['summary', 'monitoring']],
            $initial->viewData('page')['deferredProps'],
        );

        // The fleet page refreshes the pulse every 30 s with exactly this.
        $props = $this->partialReload($initial, $url, 'assets/index', ['summary', 'monitoring'])
            ->assertOk()
            ->json('props');

        $this->assertSame(2, $props['summary']['total']);
        $this->assertArrayHasKey('monitored', $props['monitoring']);
        $this->assertArrayNotHasKey('assets', $props);
    }

    public function test_inbox_defers_its_catalogs(): void
    {
        $this->seed(AccessSeeder::class);
        $this->seed(IncidentsSeeder::class);

        $user = User::factory()->create();
        $team = $user->currentTeam;
        Incident::factory()->create(['team_id' => $team->id]);

        $url = route('incidents.index', ['current_team' => $team->slug]);
        $initial = $this->actingAs($user)->get($url)->assertOk();

        $initial->assertInertia(fn (Assert $page) => $page
            ->component('incidents/index')
            ->has('incidents', 1)
            ->has('can')
            ->missing('filterOptions')
            ->missing('members')
            ->missing('reclassifyOptions')
            ->loadDeferredProps('meta', fn (Assert $reload) => $reload
                ->has('filterOptions.severities')
                ->has('members', 1)
                ->has('reclassifyOptions.types')
                ->missing('incidents')));
    }

    /**
     * Same request the Inertia client sends for `router.reload({ only })`.
     *
     * @param  TestResponse<Response>  $initial
     * @param  list<string>  $only
     * @return TestResponse<Response>
     */
    private function partialReload(TestResponse $initial, string $url, string $component, array $only): TestResponse
    {
        return $this->withHeaders([
            Header::INERTIA => 'true',
            Header::VERSION => (string) $initial->viewData('page')['version'],
            Header::PARTIAL_COMPONENT => $component,
            Header::PARTIAL_ONLY => implode(',', $only),
        ])->get($url);
    }
}
