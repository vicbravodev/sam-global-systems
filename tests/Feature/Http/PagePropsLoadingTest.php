<?php

namespace Tests\Feature\Http;

use App\Domains\Assets\Enums\AssetMonitoringState;
use App\Domains\Assets\Models\Asset;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Tenancy\Models\TenantUsageCounter;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_inbox_deferred_catalogs_never_include_another_tenant(): void
    {
        $this->seed(AccessSeeder::class);
        $this->seed(IncidentsSeeder::class);

        $user = User::factory()->create(['name' => 'Operadora Propia']);
        $team = $user->currentTeam;
        $foreignUser = User::factory()->create(['name' => 'Operador Ajeno']);
        $foreignTeam = $foreignUser->currentTeam;

        $ownProvider = IntegrationProvider::factory()->create(['name' => 'Proveedor Propio']);
        $foreignProvider = IntegrationProvider::factory()->create(['name' => 'Proveedor Ajeno']);

        Incident::factory()->create([
            'team_id' => $team->id,
            'related_event_id' => NormalizedEvent::factory()->create([
                'team_id' => $team->id,
                'provider_id' => $ownProvider->id,
            ])->id,
        ]);
        Incident::factory()->create([
            'team_id' => $foreignTeam->id,
            'related_event_id' => NormalizedEvent::factory()->create([
                'team_id' => $foreignTeam->id,
                'provider_id' => $foreignProvider->id,
            ])->id,
        ]);

        $url = route('incidents.index', ['current_team' => $team->slug]);
        $initial = $this->actingAs($user)->get($url)->assertOk();

        $props = $this->assertNoTenantLeak($team, fn () => $this
            ->partialReload($initial, $url, 'incidents/index', ['filterOptions', 'members', 'reclassifyOptions'])
            ->assertOk()
            ->json('props'));

        $this->assertSame(['Proveedor Propio'], $props['filterOptions']['providers']);

        $memberIds = array_column($props['members'], 'id');
        $this->assertContains($user->id, $memberIds);
        $this->assertNotContains($foreignUser->id, $memberIds);
        $this->assertNotContains('Operador Ajeno', array_column($props['members'], 'name'));
    }

    public function test_fleet_monitoring_quota_never_counts_another_tenants_units(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $foreignTeam = User::factory()->create()->currentTeam;

        Asset::factory()->create(['team_id' => $team->id, 'monitoring_state' => AssetMonitoringState::Monitored]);
        Asset::factory()->count(3)->create(['team_id' => $foreignTeam->id, 'monitoring_state' => AssetMonitoringState::Monitored]);

        $url = route('assets.index', ['current_team' => $team->slug]);
        $initial = $this->actingAs($user)->get($url)->assertOk();

        $props = $this->assertNoTenantLeak($team, fn () => $this
            ->partialReload($initial, $url, 'assets/index', ['summary', 'monitoring'])
            ->assertOk()
            ->json('props'));

        $this->assertSame(1, $props['monitoring']['monitored']);
        $this->assertSame(0, $props['monitoring']['pending']);
        $this->assertSame(1, $props['summary']['total']);
    }

    public function test_fleet_pulse_reload_does_not_run_the_paginated_asset_query(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        Asset::factory()->count(3)->create(['team_id' => $team->id]);

        $url = route('assets.index', ['current_team' => $team->slug]);
        $initial = $this->actingAs($user)->get($url);

        $queries = $this->queriesDuring(fn () => $this
            ->partialReload($initial, $url, 'assets/index', ['summary', 'monitoring'])
            ->assertOk()
            ->assertJsonMissingPath('props.assets')
            ->assertJsonMissingPath('props.pagination'));

        // The page query eager-loads attached devices and pages by 50: neither
        // may run when only the pulse is asked for.
        $this->assertNoQueryMatches($queries, '/^select \* from "asset_devices"/');
        $this->assertNoQueryMatches($queries, '/limit 50/');

        // A page change still builds the page once for both props.
        $pageQueries = $this->queriesDuring(fn () => $this
            ->partialReload($initial, $url, 'assets/index', ['assets', 'pagination'])
            ->assertOk()
            ->assertJsonCount(3, 'props.assets')
            ->assertJsonPath('props.pagination.total', 3));

        $this->assertCount(1, array_filter($pageQueries, fn (string $sql) => preg_match('/limit 50/', $sql) === 1));
    }

    public function test_map_reload_of_other_props_does_not_load_the_fleet(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        Asset::factory()->count(2)->create(['team_id' => $team->id]);

        $url = route('assets.map', ['current_team' => $team->slug]);
        $initial = $this->actingAs($user)->get($url)->assertOk();

        $queries = $this->queriesDuring(fn () => $this
            ->partialReload($initial, $url, 'assets/map', ['statusLabels'])
            ->assertOk()
            ->assertJsonMissingPath('props.assets'));

        $this->assertNoQueryMatches($queries, '/from "assets"/');

        // Both fleet props share one load of the fleet.
        $fleetQueries = $this->queriesDuring(fn () => $this
            ->partialReload($initial, $url, 'assets/map', ['assets', 'unpositionedCount'])
            ->assertOk()
            ->assertJsonPath('props.unpositionedCount', 2));

        $this->assertCount(1, array_filter($fleetQueries, fn (string $sql) => preg_match('/^select \* from "assets"/', $sql) === 1));
    }

    public function test_inbox_catalog_reload_does_not_run_the_incident_query(): void
    {
        $this->seed(AccessSeeder::class);
        $this->seed(IncidentsSeeder::class);

        $user = User::factory()->create();
        $team = $user->currentTeam;
        Incident::factory()->count(2)->create(['team_id' => $team->id]);

        $url = route('incidents.index', ['current_team' => $team->slug]);
        $initial = $this->actingAs($user)->get($url);

        $queries = $this->queriesDuring(fn () => $this
            ->partialReload($initial, $url, 'incidents/index', ['filterOptions', 'members', 'reclassifyOptions'])
            ->assertOk()
            ->assertJsonMissingPath('props.incidents'));

        $this->assertNoQueryMatches($queries, '/from "incidents" where .* limit 200/');
    }

    /**
     * @param  \Closure(): mixed  $callback
     * @return list<string>
     */
    private function queriesDuring(\Closure $callback): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $queries = array_map(fn (array $entry): string => (string) $entry['query'], DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    }

    /**
     * @param  list<string>  $queries
     */
    private function assertNoQueryMatches(array $queries, string $pattern): void
    {
        $matches = array_values(array_filter($queries, fn (string $sql) => preg_match($pattern, $sql) === 1));

        $this->assertSame([], $matches, "A partial reload ran a query it does not need ({$pattern}).");
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
        $response = $this->withHeaders([
            Header::INERTIA => 'true',
            Header::VERSION => (string) $initial->viewData('page')['version'],
            Header::PARTIAL_COMPONENT => $component,
            Header::PARTIAL_ONLY => implode(',', $only),
        ])->get($url);

        $this->flushHeaders();

        return $response;
    }
}
