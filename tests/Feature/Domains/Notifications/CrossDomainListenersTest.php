<?php

namespace Tests\Feature\Domains\Notifications;

use App\Domains\Automation\Enums\ActionType;
use App\Domains\Automation\Events\ActionExecuted;
use App\Domains\Automation\Models\ActionExecution;
use App\Domains\Incidents\Events\IncidentCreated;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentPriority;
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

    private function incidentWithSeverity(int $teamId, string $severityCode): Incident
    {
        $priority = IncidentPriority::query()->where('code', $severityCode)->first()
            ?? IncidentPriority::factory()->create(['code' => $severityCode]);

        return Incident::factory()->create([
            'team_id' => $teamId,
            'incident_priority_id' => $priority->id,
        ]);
    }
}
