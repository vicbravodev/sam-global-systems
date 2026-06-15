<?php

namespace Tests\Feature;

use App\Domains\Decisions\Enums\DecisionOutcomeCode;
use App\Domains\Decisions\Models\Decision;
use App\Domains\Decisions\Models\DecisionOverride;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentPriority;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Tenancy\Models\TenantUsageCounter;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $response = $this->get(route('dashboard', ['current_team' => $team->slug]));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $response = $this
            ->actingAs($user)
            ->get(route('dashboard', ['current_team' => $team->slug]));

        $response->assertOk();
    }

    public function test_dashboard_renders_with_an_empty_usage_panel(): void
    {
        // B3: el panel "Uso del plan" se renderiza siempre (con empty-state)
        // aunque no haya contadores, para que la columna llene el alto.
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $response = $this
            ->actingAs($user)
            ->get(route('dashboard', ['current_team' => $team->slug]));

        $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('dashboard')
            ->has('usage', 0));
    }

    public function test_dashboard_renders_all_real_data_props(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        Incident::factory()->open()->create([
            'team_id' => $team->id,
            'opened_at' => now()->subHour(),
        ]);
        NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'occurred_at' => now()->subMinutes(5),
        ]);
        TenantIntegration::factory()->active()->create(['team_id' => $team->id]);
        TenantUsageCounter::factory()->create([
            'team_id' => $team->id,
            'consumed_value' => 10,
            'included_value' => 100,
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('dashboard', ['current_team' => $team->slug]));

        $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('dashboard')
            ->has('kpis', fn (AssertableInertia $kpis) => $kpis
                ->has('openIncidents', fn (AssertableInertia $kpi) => $kpi
                    ->where('value', 1)
                    ->has('series', 7)
                    ->etc()
                )
                ->has('criticalOpen')
                ->has('slaCompliance')
                ->has('aiPrecision')
            )
            ->has('incidents', 1, fn (AssertableInertia $incident) => $incident
                ->has('incidentId')
                ->has('title')
                ->has('severity')
                ->has('slaSeconds')
                ->has('slaTotal')
                ->etc()
            )
            ->has('stream', 1, fn (AssertableInertia $event) => $event
                ->has('id')
                ->has('ts')
                ->has('provider')
                ->has('type')
                ->has('decision')
                ->etc()
            )
            ->has('integrations', 1, fn (AssertableInertia $integration) => $integration
                ->where('health', 'ok')
                ->where('events24h', 0)
                ->has('name')
                ->etc()
            )
            ->has('usage', 1, fn (AssertableInertia $counter) => $counter
                ->where('consumed', 10)
                ->where('included', 100)
                ->where('percentUsed', 10)
                ->etc()
            )
        );
    }

    public function test_kpis_count_open_and_critical_incidents(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $critical = IncidentPriority::factory()->critical()->create();

        Incident::factory()->open()->create([
            'team_id' => $team->id,
            'opened_at' => now()->subHours(2),
        ]);
        Incident::factory()->open()->create([
            'team_id' => $team->id,
            'incident_priority_id' => $critical->id,
            'opened_at' => now()->subHour(),
        ]);
        Incident::factory()->resolved()->create([
            'team_id' => $team->id,
            'opened_at' => now()->subHours(3),
            'resolved_at' => now()->subHours(2),
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('dashboard', ['current_team' => $team->slug]));

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->where('kpis.openIncidents.value', 2)
            ->where('kpis.criticalOpen.value', 1)
        );
    }

    public function test_sla_compliance_is_percentage_of_incidents_resolved_within_sla(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        // Factory default priority carries sla_seconds = 3600.
        Incident::factory()->resolved()->create([
            'team_id' => $team->id,
            'opened_at' => now()->subHours(5),
            'resolved_at' => now()->subHours(5)->addMinutes(30),
        ]);
        Incident::factory()->resolved()->create([
            'team_id' => $team->id,
            'opened_at' => now()->subHours(8),
            'resolved_at' => now()->subHours(8)->addHours(3),
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('dashboard', ['current_team' => $team->slug]));

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->where('kpis.slaCompliance.value', 50)
        );
    }

    public function test_sla_compliance_is_null_without_resolved_incidents(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $response = $this
            ->actingAs($user)
            ->get(route('dashboard', ['current_team' => $team->slug]));

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->where('kpis.slaCompliance.value', null)
        );
    }

    public function test_ai_precision_derives_from_decisions_and_overrides(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $decisions = Decision::factory()->count(4)->create([
            'team_id' => $team->id,
            'decided_at' => now()->subDay(),
        ]);

        DecisionOverride::factory()->create([
            'decision_id' => $decisions->first()->id,
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('dashboard', ['current_team' => $team->slug]));

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->where('kpis.aiPrecision.value', 75)
        );
    }

    public function test_open_incidents_panel_limits_to_five_and_excludes_terminal(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        Incident::factory()->open()->count(6)->create([
            'team_id' => $team->id,
            'opened_at' => now()->subHour(),
        ]);
        Incident::factory()->closed()->create([
            'team_id' => $team->id,
            'opened_at' => now()->subHours(2),
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('dashboard', ['current_team' => $team->slug]));

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->has('incidents', 5)
        );
    }

    public function test_open_incidents_panel_always_includes_open_criticals(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $critical = IncidentPriority::factory()->critical()->create();

        // An old critical that a plain "5 most recent" ordering would drop.
        $criticalIncident = Incident::factory()->open()->create([
            'team_id' => $team->id,
            'incident_priority_id' => $critical->id,
            'opened_at' => now()->subDays(3),
        ]);

        Incident::factory()->open()->count(5)->create([
            'team_id' => $team->id,
            'opened_at' => now()->subHour(),
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('dashboard', ['current_team' => $team->slug]));

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->has('incidents', 5)
            ->where('incidents.0.incidentId', $criticalIncident->id)
            ->where('incidents.0.severity', 'critical')
        );
    }

    public function test_stream_limits_to_eight_latest_and_maps_decision_codes(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $oldest = NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'occurred_at' => now()->subHours(10),
        ]);

        $withIncident = NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'occurred_at' => now()->subMinutes(1),
        ]);
        $withIgnore = NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'occurred_at' => now()->subMinutes(2),
        ]);
        NormalizedEvent::factory()->count(6)->create([
            'team_id' => $team->id,
            'occurred_at' => now()->subMinutes(3),
        ]);

        Decision::factory()->create([
            'team_id' => $team->id,
            'normalized_event_id' => $withIncident->id,
            'decision_code' => DecisionOutcomeCode::Incident->value,
        ]);
        Decision::factory()->create([
            'team_id' => $team->id,
            'normalized_event_id' => $withIgnore->id,
            'decision_code' => DecisionOutcomeCode::Ignore->value,
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('dashboard', ['current_team' => $team->slug]));

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->has('stream', 8)
            ->where('stream.0.id', $withIncident->id)
            ->where('stream.0.decision', 'incident')
            ->where('stream.1.id', $withIgnore->id)
            ->where('stream.1.decision', 'discard')
            // Events without a decision fall back to the neutral chip.
            ->where('stream.2.decision', 'info')
        );

        $response->assertInertia(function (AssertableInertia $page) use ($oldest) {
            $stream = $page->toArray()['props']['stream'];

            $this->assertNotContains(
                $oldest->id,
                array_column($stream, 'id'),
                'The oldest event must fall outside the 8-event stream window',
            );
        });
    }

    public function test_integrations_count_only_events_of_last_24_hours_per_provider(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $provider = IntegrationProvider::factory()->create();
        TenantIntegration::factory()->active()->create([
            'team_id' => $team->id,
            'provider_id' => $provider->id,
        ]);

        NormalizedEvent::factory()->count(2)->create([
            'team_id' => $team->id,
            'provider_id' => $provider->id,
            'occurred_at' => now()->subHours(2),
        ]);
        NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'provider_id' => $provider->id,
            'occurred_at' => now()->subDays(2),
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('dashboard', ['current_team' => $team->slug]));

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->has('integrations', 1)
            ->where('integrations.0.events24h', 2)
            ->where('integrations.0.health', 'ok')
        );
    }

    public function test_usage_only_includes_counters_of_current_period(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $meter = UsageMeter::factory()->create();

        TenantUsageCounter::factory()->create([
            'team_id' => $team->id,
            'usage_meter_id' => $meter->id,
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'consumed_value' => 120,
            'included_value' => 100,
            'overage_value' => 20,
        ]);
        TenantUsageCounter::factory()->create([
            'team_id' => $team->id,
            'period_start' => now()->subMonths(2)->startOfMonth()->toDateString(),
            'period_end' => now()->subMonths(2)->endOfMonth()->toDateString(),
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('dashboard', ['current_team' => $team->slug]));

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->has('usage', 1)
            ->where('usage.0.consumed', 120)
            ->where('usage.0.overage', 20)
            ->where('usage.0.percentUsed', 120)
        );
    }

    public function test_dashboard_is_tenant_isolated(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $otherTeam = Team::factory()->create();
        Incident::factory()->open()->create([
            'team_id' => $otherTeam->id,
            'opened_at' => now()->subHour(),
        ]);
        NormalizedEvent::factory()->create([
            'team_id' => $otherTeam->id,
            'occurred_at' => now()->subMinutes(5),
        ]);
        TenantIntegration::factory()->active()->create(['team_id' => $otherTeam->id]);
        TenantUsageCounter::factory()->create(['team_id' => $otherTeam->id]);

        $response = $this
            ->actingAs($user)
            ->get(route('dashboard', ['current_team' => $team->slug]));

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->where('kpis.openIncidents.value', 0)
            ->has('incidents', 0)
            ->has('stream', 0)
            ->has('integrations', 0)
            ->has('usage', 0)
        );
    }
}
