<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Assets\Models\Asset;
use App\Domains\Automation\Actions\ExecuteAction;
use App\Domains\Automation\Enums\ActionExecutionStatus;
use App\Domains\Automation\Enums\ActionType;
use App\Domains\Automation\Models\ActionExecution;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Incidents\Actions\AssignIncident;
use App\Domains\Incidents\Enums\AssigneeType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentAssignment;
use App\Domains\Incidents\Models\IncidentType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationRecipient;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Database\Seeders\IncidentsSeeder;
use Database\Seeders\NotificationMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Los ids de usuario son globales de plataforma: asignar, notificar o pintar
 * el nombre de un usuario por id sin comprobar que es miembro del team deja a
 * un tenant enumerar y contactar usuarios de otros tenants.
 */
class CrossTenantUserReferenceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Team $team;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);
        $this->seed(IncidentsSeeder::class);

        $this->owner = User::factory()->create();
        $this->team = $this->owner->currentTeam;
        $this->outsider = User::factory()->create(['name' => 'Usuario De Otro Tenant']);
    }

    public function test_assigning_an_incident_to_a_non_member_is_rejected(): void
    {
        $incident = Incident::factory()->create(['team_id' => $this->team->id]);

        $this->actingAs($this->owner)->postJson(
            "/api/{$this->team->slug}/incidents/{$incident->id}/assign",
            ['assigned_to_type' => 'user', 'assigned_to_id' => $this->outsider->id],
        )->assertUnprocessable()->assertJsonValidationErrors(['assigned_to_id']);

        $this->actingAs($this->owner)->postJson(
            route('incidents.assign', ['current_team' => $this->team->slug, 'incident' => $incident->id]),
            ['assigned_to_type' => 'user', 'assigned_to_id' => $this->outsider->id],
        )->assertUnprocessable();

        $this->assertSame(0, IncidentAssignment::query()->where('incident_id', $incident->id)->count());
    }

    public function test_assigning_to_a_member_still_works(): void
    {
        $incident = Incident::factory()->create(['team_id' => $this->team->id]);
        $member = User::factory()->create();
        $this->team->members()->attach($member, ['role' => TeamRole::Member->value]);

        $this->actingAs($this->owner)->postJson(
            "/api/{$this->team->slug}/incidents/{$incident->id}/assign",
            ['assigned_to_type' => 'user', 'assigned_to_id' => $member->id],
        )->assertCreated();
    }

    public function test_assigning_to_another_team_queue_is_rejected(): void
    {
        $incident = Incident::factory()->create(['team_id' => $this->team->id]);

        $this->actingAs($this->owner)->postJson(
            "/api/{$this->team->slug}/incidents/{$incident->id}/assign",
            ['assigned_to_type' => 'queue', 'assigned_to_id' => $this->outsider->currentTeam->id],
        )->assertUnprocessable()->assertJsonValidationErrors(['assigned_to_id']);
    }

    public function test_the_assign_action_refuses_non_members(): void
    {
        $incident = Incident::factory()->create(['team_id' => $this->team->id]);

        $this->expectException(\InvalidArgumentException::class);

        app(AssignIncident::class)->execute($incident, AssigneeType::User, $this->outsider->id);
    }

    public function test_inbox_and_dashboard_do_not_resolve_names_of_non_members(): void
    {
        $incident = Incident::factory()->create(['team_id' => $this->team->id]);
        IncidentAssignment::factory()->create([
            'incident_id' => $incident->id,
            'assigned_to_type' => AssigneeType::User,
            'assigned_to_id' => $this->outsider->id,
            'assigned_at' => now(),
            'unassigned_at' => null,
        ]);

        $this->actingAs($this->owner)
            ->get(route('incidents.index', ['current_team' => $this->team->slug]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('incidents/index')
                ->where('incidents.0.assignee.name', 'Usuario'));

        $this->actingAs($this->owner)
            ->get(route('dashboard', ['current_team' => $this->team->slug]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('incidents.0.assignee.name', 'Usuario'));
    }

    public function test_automation_does_not_notify_a_user_of_another_tenant(): void
    {
        Mail::fake();
        $this->seed(NotificationMeterSeeder::class);

        NotificationChannel::factory()->email()->create();

        $execution = ActionExecution::factory()->create([
            'team_id' => $this->team->id,
            'action_type' => ActionType::SendEmail,
            'status' => ActionExecutionStatus::Queued,
            'target_type' => 'user',
            'target_reference' => (string) $this->outsider->id,
        ]);

        app(ExecuteAction::class)->execute($execution);

        $this->assertSame(0, NotificationRecipient::query()
            ->withoutGlobalScopes()
            ->where('address', $this->outsider->email)
            ->count());
        Mail::assertNothingSent();
    }

    public function test_automation_does_not_assign_a_user_of_another_tenant(): void
    {
        $incident = Incident::factory()->create(['team_id' => $this->team->id]);

        $execution = ActionExecution::factory()->create([
            'team_id' => $this->team->id,
            'incident_id' => $incident->id,
            'action_type' => ActionType::AssignIncident,
            'status' => ActionExecutionStatus::Queued,
            'target_type' => 'user',
            'target_reference' => (string) $this->outsider->id,
        ]);

        $result = app(ExecuteAction::class)->execute($execution);

        $this->assertSame(ActionExecutionStatus::Failed, $result->status);
        $this->assertSame(0, IncidentAssignment::query()->where('incident_id', $incident->id)->count());
    }

    public function test_workflow_steps_cannot_target_users_of_another_tenant(): void
    {
        $this->actingAs($this->owner)->postJson(
            route('automation.workflows.store', ['current_team' => $this->team->slug]),
            [
                'code' => 'notify-outsider',
                'name' => 'Avisar a un externo',
                'trigger_type' => 'incident_created',
                'status' => 'active',
                'steps_json' => [
                    ['action_type' => 'send_email', 'target_type' => 'user', 'target_reference' => (string) $this->outsider->id],
                ],
            ],
        )->assertUnprocessable()->assertJsonValidationErrors(['steps_json.0.target_reference']);
    }

    public function test_api_incident_creation_rejects_assets_and_drivers_of_another_tenant(): void
    {
        $foreignTeam = $this->outsider->currentTeam;
        $asset = Asset::factory()->create(['team_id' => $foreignTeam->id]);
        $driver = Driver::factory()->create(['team_id' => $foreignTeam->id]);

        $this->actingAs($this->owner)->postJson("/api/{$this->team->slug}/incidents", [
            'incident_type_id' => IncidentType::query()->value('id'),
            'asset_id' => $asset->id,
            'driver_id' => $driver->id,
            'title' => 'Incidente',
            'summary' => 'Resumen',
        ])->assertUnprocessable()->assertJsonValidationErrors(['asset_id', 'driver_id']);
    }

    public function test_evidence_cannot_reference_another_tenants_event(): void
    {
        $incident = Incident::factory()->create(['team_id' => $this->team->id]);
        $foreignEvent = NormalizedEvent::factory()->create(['team_id' => $this->outsider->currentTeam->id]);

        $this->actingAs($this->owner)->postJson(
            "/api/{$this->team->slug}/incidents/{$incident->id}/evidence",
            [
                'evidence_type' => 'event_snapshot',
                'source_type' => 'normalized_event',
                'source_reference_id' => $foreignEvent->id,
            ],
        )->assertUnprocessable()->assertJsonValidationErrors(['source_reference_id']);
    }
}
