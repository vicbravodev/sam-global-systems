<?php

namespace Tests\Feature\Domains\Notifications;

use App\Domains\Automation\Enums\ActionType;
use App\Domains\Automation\Events\ActionExecuted;
use App\Domains\Automation\Models\ActionExecution;
use App\Domains\Incidents\Events\IncidentClosed;
use App\Domains\Incidents\Events\IncidentCreated;
use App\Domains\Incidents\Events\IncidentStatusChanged;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentPriority;
use App\Domains\Incidents\Support\IncidentStatusPresenter;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Models\Notification;
use App\Models\User;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Validates that the cross-domain listeners registered by NotificationsServiceProvider
 * react to typed Incidents and Automation events.
 */
class CrossDomainListenersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IncidentsSeeder::class);
    }

    public function test_incident_created_listener_creates_notification(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        $incident = $this->incidentWithSeverity($team->id, 'high');

        IncidentCreated::dispatch($incident);

        $notification = Notification::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('event_key', "incident_created:{$incident->id}")
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame(NotificationPriority::High, $notification->priority);
        $this->assertSame(NotificationSourceType::Incident, $notification->source_type);
        $this->assertSame((string) $incident->id, $notification->source_reference_id);
        $this->assertSame('Nuevo incidente creado', $notification->subject);
        $this->assertSame('Se ha reportado un nuevo incidente en tu equipo.', $notification->body_preview);
    }

    public function test_in_review_status_change_is_internal_and_never_notifies(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        $incident = $this->incidentWithSeverity($team->id, 'high', ['claimed_by_user_id' => $user->id]);

        IncidentStatusChanged::dispatch($incident, 'open', 'in_review');

        $this->assertFalse(Notification::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('event_key', "incident_status:{$incident->id}:in_review")
            ->exists());
    }

    public function test_status_change_notifies_only_the_claimer_in_spanish_never_the_actor_or_the_team(): void
    {
        Bus::fake();

        $actor = User::factory()->create();
        $team = $actor->currentTeam;
        $claimer = User::factory()->create();
        $bystander = User::factory()->create();
        $team->members()->attach($claimer, ['role' => 'member']);
        $team->members()->attach($bystander, ['role' => 'member']);
        $this->actingAs($actor);

        $incident = $this->incidentWithSeverity($team->id, 'high', ['claimed_by_user_id' => $claimer->id]);

        IncidentStatusChanged::dispatch($incident, 'open', 'resolved', $actor->id);

        $notification = Notification::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('event_key', "incident_status:{$incident->id}:resolved")
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame('Estado del incidente actualizado', $notification->subject);
        $this->assertSame(
            "El incidente {$incident->fresh()->reference()} pasó a ".IncidentStatusPresenter::label('resolved').'.',
            $notification->body_preview,
        );
        $recipients = collect($notification->payload_json['recipients'])->pluck('recipient_reference_id')->all();
        $this->assertSame([(string) $claimer->id], $recipients);
        $this->assertSame(['web', 'email'], $notification->payload_json['force_channels']);
    }

    public function test_status_change_by_the_only_owner_sends_nothing(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        $incident = $this->incidentWithSeverity($team->id, 'high', ['claimed_by_user_id' => $user->id]);

        IncidentStatusChanged::dispatch($incident, 'open', 'resolved', $user->id);

        $this->assertSame(0, Notification::withoutGlobalScopes()->where('team_id', $team->id)->count());
    }

    public function test_incident_closed_listener_creates_notification_in_spanish(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        $incident = $this->incidentWithSeverity($team->id, 'high', ['claimed_by_user_id' => $user->id]);

        IncidentClosed::dispatch($incident);

        $notification = Notification::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('event_key', "incident_status:{$incident->id}:closed")
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame('Estado del incidente actualizado', $notification->subject);
        $this->assertSame(
            "El incidente {$incident->fresh()->reference()} pasó a ".IncidentStatusPresenter::label('closed').'.',
            $notification->body_preview,
        );
    }

    /**
     * NotifyOnActionExecuted is no longer registered against ActionExecuted (see
     * NotificationsServiceProvider): a send action already notifies its recipients by
     * itself, so a second Notifications-domain listener reacting to the same event used
     * to fan out an extra notice to the whole team. This previously asserted the
     * opposite (that a `send_*` action produced a Notification row) — that was the bug.
     */
    public function test_action_executed_never_creates_a_notification(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        $rollback = ActionExecution::factory()->create([
            'team_id' => $team->id,
            'action_type' => ActionType::CallWebhook,
            'payload_json' => [],
        ]);
        ActionExecuted::dispatch($rollback);

        $send = ActionExecution::factory()->create([
            'team_id' => $team->id,
            'action_type' => ActionType::SendEmail,
            'payload_json' => [
                'subject' => 'Hello from automation',
                'body_preview' => 'A scheduled send',
            ],
        ]);
        ActionExecuted::dispatch($send);

        $this->assertSame(0, Notification::withoutGlobalScopes()->where('team_id', $team->id)->count());
    }

    public function test_listener_idempotent_when_dispatched_twice(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        $incident = $this->incidentWithSeverity($team->id, 'critical');

        IncidentCreated::dispatch($incident);
        IncidentCreated::dispatch($incident);

        $count = Notification::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('event_key', "incident_created:{$incident->id}")
            ->count();

        $this->assertSame(1, $count);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function incidentWithSeverity(int $teamId, string $severityCode, array $attributes = []): Incident
    {
        $priority = IncidentPriority::query()->where('code', $severityCode)->first()
            ?? IncidentPriority::factory()->create(['code' => $severityCode]);

        return Incident::factory()->create(array_merge([
            'team_id' => $teamId,
            'incident_priority_id' => $priority->id,
        ], $attributes));
    }
}
