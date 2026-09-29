<?php

namespace Tests\Feature\Domains\Automation;

use App\Domains\Automation\Enums\WorkflowTriggerType;
use App\Domains\Automation\Jobs\RunAutomationWorkflowJob;
use App\Domains\Automation\Models\AutomationWorkflow;
use App\Domains\Incidents\Actions\AppendTimelineEntry;
use App\Domains\Incidents\Enums\IncidentStatusCode;
use App\Domains\Incidents\Enums\TimelineActorType;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Events\IncidentStatusChanged;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentAssignment;
use App\Domains\Notifications\Models\Notification;
use App\Models\User;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * P0-b / P1-6: el workflow `incident_escalated` sólo corre en la escalación
 * real, y el aviso de cambio de estado no duplica la escalación por SLA.
 */
class EscalationTriggerOnlyOnEscalatedTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IncidentsSeeder::class);
    }

    public function test_non_escalation_status_changes_never_consume_the_escalation_workflow(): void
    {
        Bus::fake([RunAutomationWorkflowJob::class]);

        $team = User::factory()->create()->currentTeam;
        AutomationWorkflow::factory()->trigger(WorkflowTriggerType::IncidentEscalated)->create(['team_id' => $team->id]);
        $incident = Incident::factory()->create(['team_id' => $team->id]);

        IncidentStatusChanged::dispatch($incident, 'open', IncidentStatusCode::InReview->value);
        IncidentStatusChanged::dispatch($incident, 'in_review', IncidentStatusCode::FalsePositive->value);

        Bus::assertNotDispatched(RunAutomationWorkflowJob::class);

        $internal = $this->systemLogEntries('notifications.status_change.skipped');
        $this->assertSame('debug', $internal[0]['level']);
        $this->assertSystemLogged('notifications.status_change.skipped', fn (array $c) => $c['reason'] === 'internal_status'
            && $c['input']['incident_id'] === $incident->id
            && $c['input']['new_status'] === 'in_review');
    }

    public function test_real_escalation_still_runs_the_escalation_workflow(): void
    {
        Bus::fake([RunAutomationWorkflowJob::class]);

        $team = User::factory()->create()->currentTeam;
        $workflow = AutomationWorkflow::factory()->trigger(WorkflowTriggerType::IncidentEscalated)->create(['team_id' => $team->id]);
        $incident = Incident::factory()->create(['team_id' => $team->id]);

        IncidentStatusChanged::dispatch($incident, 'open', IncidentStatusCode::Escalated->value);

        Bus::assertDispatched(
            RunAutomationWorkflowJob::class,
            fn (RunAutomationWorkflowJob $job) => $job->automationWorkflowId === $workflow->id,
        );
    }

    public function test_sla_escalation_is_not_notified_twice(): void
    {
        Bus::fake();

        $owner = User::factory()->create();
        $team = $owner->currentTeam;
        $assignee = User::factory()->create();
        $team->members()->attach($assignee, ['role' => 'member']);

        $incident = Incident::factory()->create(['team_id' => $team->id]);
        IncidentAssignment::factory()->create([
            'incident_id' => $incident->id,
            'assigned_to_type' => 'user',
            'assigned_to_id' => $assignee->id,
            'unassigned_at' => null,
        ]);

        app(AppendTimelineEntry::class)->execute(
            incident: $incident,
            entryType: TimelineEntryType::SlaBreached,
            actorType: TimelineActorType::System,
            title: 'SLA incumplido',
        );

        IncidentStatusChanged::dispatch($incident, 'open', IncidentStatusCode::Escalated->value);

        $this->assertFalse(Notification::withoutGlobalScopes()
            ->where('event_key', "incident_status:{$incident->id}:escalated")
            ->exists());

        $this->assertSystemLogged('notifications.status_change.skipped', fn (array $c) => $c['reason'] === 'escalated_by_sla'
            && $c['input']['incident_id'] === $incident->id
            && $c['input']['new_status'] === 'escalated');
        $this->assertSystemNotLogged('notifications.notification.requested');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_status_change_without_recipients_is_logged_with_its_counts(): void
    {
        Bus::fake();

        $owner = User::factory()->create();
        $team = $owner->currentTeam;
        $foreign = User::factory()->create();

        // Sólo el actor (excluido) y un usuario de otro tenant (descartado).
        $incident = Incident::factory()->create(['team_id' => $team->id, 'claimed_by_user_id' => $foreign->id]);
        IncidentAssignment::factory()->create([
            'incident_id' => $incident->id,
            'assigned_to_type' => 'user',
            'assigned_to_id' => $owner->id,
            'unassigned_at' => null,
        ]);

        IncidentStatusChanged::dispatch($incident, 'open', IncidentStatusCode::Escalated->value, $owner->id);

        $this->assertSystemLogged('notifications.status_change.skipped', fn (array $c) => $c['reason'] === 'no_recipients'
            && $c['input']['incident_id'] === $incident->id
            && $c['calc']['candidates_count'] === 2
            && $c['calc']['actor_excluded'] === true
            && $c['calc']['non_member_dropped_count'] === 1
            && $c['calc']['without_email_dropped_count'] === 0);
        $this->assertStringNotContainsString('"'.$foreign->id.'"', json_encode($this->systemLogEntries('notifications.status_change.skipped')));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_manual_escalation_notifies_the_assignee_only_within_the_tenant(): void
    {
        Bus::fake();

        $owner = User::factory()->create();
        $team = $owner->currentTeam;
        $assignee = User::factory()->create();
        $team->members()->attach($assignee, ['role' => 'member']);

        // Otro tenant con un usuario "asignado" por id: nunca debe colarse.
        $foreign = User::factory()->create();

        $incident = Incident::factory()->create(['team_id' => $team->id, 'claimed_by_user_id' => $foreign->id]);
        IncidentAssignment::factory()->create([
            'incident_id' => $incident->id,
            'assigned_to_type' => 'user',
            'assigned_to_id' => $assignee->id,
            'unassigned_at' => null,
        ]);

        $this->assertNoTenantLeak($team, fn () => IncidentStatusChanged::dispatch(
            $incident,
            'open',
            IncidentStatusCode::Escalated->value,
            $owner->id,
        ));

        $notification = Notification::withoutGlobalScopes()
            ->where('event_key', "incident_status:{$incident->id}:escalated")
            ->sole();

        $this->assertSame($team->id, $notification->team_id);
        $this->assertSame(
            [(string) $assignee->id],
            collect($notification->payload_json['recipients'])->pluck('recipient_reference_id')->all(),
        );
    }
}
