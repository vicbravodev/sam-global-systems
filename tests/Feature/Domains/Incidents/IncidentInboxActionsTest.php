<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Incidents\Enums\IncidentStatusCode;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Events\IncidentStatusChanged;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentPriority;
use App\Domains\Incidents\Models\IncidentStatus;
use App\Domains\Incidents\Models\IncidentType;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Covers the session-authenticated web routes the Incident Inbox UI calls
 * (assign / comment / resolve / close / reopen / reclassify / escalate) plus
 * the inbox index filters, end to end: request → action → side effects.
 */
class IncidentInboxActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessSeeder::class);
        $this->seed(IncidentsSeeder::class);
    }

    private function openIncidentFor(User $user): Incident
    {
        $open = IncidentStatus::query()->where('code', IncidentStatusCode::Open->value)->first();

        return Incident::factory()->create([
            'team_id' => $user->currentTeam->id,
            'incident_status_id' => $open->id,
        ]);
    }

    public function test_assign_web_route_assigns_incident(): void
    {
        $user = User::factory()->create();
        $incident = $this->openIncidentFor($user);

        $response = $this->actingAs($user)->postJson(
            route('incidents.assign', [
                'current_team' => $user->currentTeam->slug,
                'incident' => $incident->id,
            ]),
            ['assigned_to_type' => 'user', 'assigned_to_id' => $user->id],
        );

        $response->assertStatus(201);
        $this->assertDatabaseHas('incident_assignments', [
            'incident_id' => $incident->id,
            'assigned_to_id' => $user->id,
            'unassigned_at' => null,
        ]);
    }

    public function test_comment_web_route_stores_comment_with_visibility(): void
    {
        $user = User::factory()->create();
        $incident = $this->openIncidentFor($user);

        $response = $this->actingAs($user)->postJson(
            route('incidents.comments.store', [
                'current_team' => $user->currentTeam->slug,
                'incident' => $incident->id,
            ]),
            ['comment' => 'Revisando el caso', 'visibility' => 'tenant_visible'],
        );

        $response->assertStatus(201);
        $this->assertDatabaseHas('incident_comments', [
            'incident_id' => $incident->id,
            'user_id' => $user->id,
            'comment' => 'Revisando el caso',
            'visibility' => 'tenant_visible',
        ]);
    }

    public function test_resolve_web_route_records_resolution(): void
    {
        $user = User::factory()->create();
        $incident = $this->openIncidentFor($user);

        $response = $this->actingAs($user)->postJson(
            route('incidents.resolve', [
                'current_team' => $user->currentTeam->slug,
                'incident' => $incident->id,
            ]),
            ['resolution_code' => 'handled_successfully', 'summary' => 'Resuelto en sitio'],
        );

        $response->assertStatus(201);
        $this->assertDatabaseHas('incident_resolutions', [
            'incident_id' => $incident->id,
            'resolution_code' => 'handled_successfully',
        ]);
    }

    public function test_discard_via_resolve_marks_false_positive(): void
    {
        $user = User::factory()->create();
        $incident = $this->openIncidentFor($user);

        $response = $this->actingAs($user)->postJson(
            route('incidents.resolve', [
                'current_team' => $user->currentTeam->slug,
                'incident' => $incident->id,
            ]),
            ['resolution_code' => 'false_positive', 'summary' => 'Descartado por el operador.'],
        );

        $response->assertStatus(201);
        $falsePositive = IncidentStatus::query()
            ->where('code', IncidentStatusCode::FalsePositive->value)
            ->value('id');
        $this->assertSame($falsePositive, $incident->fresh()->incident_status_id);
    }

    public function test_close_then_reopen_web_routes(): void
    {
        $user = User::factory()->create();
        $incident = $this->openIncidentFor($user);

        $this->actingAs($user)->postJson(
            route('incidents.close', [
                'current_team' => $user->currentTeam->slug,
                'incident' => $incident->id,
            ]),
        )->assertStatus(200);

        $closed = IncidentStatus::query()->where('code', IncidentStatusCode::Closed->value)->value('id');
        $this->assertSame($closed, $incident->fresh()->incident_status_id);

        $this->actingAs($user)->postJson(
            route('incidents.reopen', [
                'current_team' => $user->currentTeam->slug,
                'incident' => $incident->id,
            ]),
        )->assertStatus(200);

        $open = IncidentStatus::query()->where('code', IncidentStatusCode::Open->value)->value('id');
        $this->assertSame($open, $incident->fresh()->incident_status_id);
    }

    public function test_reclassify_web_route_changes_type(): void
    {
        $user = User::factory()->create();
        $incident = $this->openIncidentFor($user);
        $newType = IncidentType::query()->where('id', '!=', $incident->incident_type_id)->firstOrFail();

        $response = $this->actingAs($user)->postJson(
            route('incidents.reclassify', [
                'current_team' => $user->currentTeam->slug,
                'incident' => $incident->id,
            ]),
            ['incident_type_id' => $newType->id],
        );

        $response->assertStatus(200);
        $this->assertSame($newType->id, $incident->fresh()->incident_type_id);
    }

    public function test_escalate_web_route_transitions_status_and_dispatches_event(): void
    {
        Event::fake([IncidentStatusChanged::class]);

        $user = User::factory()->create();
        $incident = $this->openIncidentFor($user);

        $response = $this->actingAs($user)->postJson(
            route('incidents.escalate', [
                'current_team' => $user->currentTeam->slug,
                'incident' => $incident->id,
            ]),
            ['reason' => 'Sin respuesta del conductor'],
        );

        $response->assertStatus(200);

        $escalated = IncidentStatus::query()->where('code', IncidentStatusCode::Escalated->value)->value('id');
        $this->assertSame($escalated, $incident->fresh()->incident_status_id);

        $this->assertDatabaseHas('incident_timelines', [
            'incident_id' => $incident->id,
            'entry_type' => TimelineEntryType::Escalated->value,
        ]);

        Event::assertDispatched(
            IncidentStatusChanged::class,
            fn (IncidentStatusChanged $event) => $event->incident->id === $incident->id
                && $event->newStatus === IncidentStatusCode::Escalated->value,
        );
    }

    public function test_escalate_already_escalated_returns_422(): void
    {
        $user = User::factory()->create();
        $escalated = IncidentStatus::query()->where('code', IncidentStatusCode::Escalated->value)->first();
        $incident = Incident::factory()->create([
            'team_id' => $user->currentTeam->id,
            'incident_status_id' => $escalated->id,
        ]);

        $response = $this->actingAs($user)->postJson(
            route('incidents.escalate', [
                'current_team' => $user->currentTeam->slug,
                'incident' => $incident->id,
            ]),
        );

        $response->assertStatus(422);
    }

    public function test_escalate_terminal_incident_is_forbidden_by_policy(): void
    {
        $user = User::factory()->create();
        $closed = IncidentStatus::query()->where('code', IncidentStatusCode::Closed->value)->first();
        $incident = Incident::factory()->create([
            'team_id' => $user->currentTeam->id,
            'incident_status_id' => $closed->id,
        ]);

        $response = $this->actingAs($user)->postJson(
            route('incidents.escalate', [
                'current_team' => $user->currentTeam->slug,
                'incident' => $incident->id,
            ]),
        );

        $response->assertStatus(403);
    }

    public function test_actions_are_tenant_isolated(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $foreign = Incident::factory()->create(['team_id' => $other->currentTeam->id]);

        $response = $this->actingAs($user)->postJson(
            route('incidents.assign', [
                'current_team' => $user->currentTeam->slug,
                'incident' => $foreign->id,
            ]),
            ['assigned_to_type' => 'user', 'assigned_to_id' => $user->id],
        );

        $response->assertStatus(404);
    }

    public function test_inbox_index_filters_by_severity_and_exposes_options(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $critical = IncidentStatus::query()->where('code', IncidentStatusCode::Open->value)->value('id');
        $criticalPriority = IncidentPriority::query()->where('code', 'critical')->firstOrFail();
        $lowPriority = IncidentPriority::query()->where('code', 'low')->firstOrFail();

        Incident::factory()->create([
            'team_id' => $team->id,
            'incident_status_id' => $critical,
            'incident_priority_id' => $criticalPriority->id,
        ]);
        Incident::factory()->count(2)->create([
            'team_id' => $team->id,
            'incident_status_id' => $critical,
            'incident_priority_id' => $lowPriority->id,
        ]);

        $response = $this->actingAs($user)->get(
            route('incidents.index', ['current_team' => $team->slug, 'severity' => 'critical']),
        );

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('incidents/index')
                ->has('incidents', 1)
                ->where('filters.severity', 'critical')
                ->has('filterOptions.severities')
                ->has('filterOptions.statuses')
                ->has('members')
                ->has('reclassifyOptions.types'),
        );
    }
}
