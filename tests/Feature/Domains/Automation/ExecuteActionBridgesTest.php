<?php

namespace Tests\Feature\Domains\Automation;

use App\Domains\Automation\Actions\ExecuteAction;
use App\Domains\Automation\Enums\ActionExecutionStatus;
use App\Domains\Automation\Enums\ActionType;
use App\Domains\Automation\Events\ActionFailed;
use App\Domains\Automation\Models\ActionExecution;
use App\Domains\Incidents\Enums\IncidentStatusCode;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentAssignment;
use App\Domains\Incidents\Models\IncidentTimeline;
use App\Domains\Notifications\Channels\TwilioMessenger;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AutomationMeterSeeder;
use Database\Seeders\IncidentsSeeder;
use Database\Seeders\NotificationMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

/**
 * Roadmap B7: ExecuteAction bridges Send* to the Notifications pipeline and
 * the incident operations to the Incidents domain actions — no more stubs.
 */
class ExecuteActionBridgesTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(NotificationMeterSeeder::class);

        $this->team = User::factory()->create()->currentTeam;
    }

    private function makeExecution(ActionType $type, array $overrides = []): ActionExecution
    {
        return ActionExecution::factory()->create(array_merge([
            'team_id' => $this->team->id,
            'action_type' => $type,
            'status' => ActionExecutionStatus::Queued,
        ], $overrides));
    }

    public function test_send_email_routes_through_notifications_pipeline(): void
    {
        Mail::fake();

        NotificationChannel::factory()->email()->create(['team_id' => $this->team->id]);

        $execution = $this->makeExecution(ActionType::SendEmail, [
            'target_type' => 'email',
            'target_reference' => 'ops@example.test',
            'payload_json' => ['subject' => 'Panic incident', 'body' => 'Check unit 42.'],
        ]);

        $result = app(ExecuteAction::class)->execute($execution);

        $this->assertSame(ActionExecutionStatus::Completed, $result->status);

        $notification = Notification::withoutGlobalScopes()
            ->whereKey($result->response_json['notification_id'])
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame('automation.send_email', $notification->notification_type);
        $this->assertSame('automation_action:'.$execution->id, $notification->event_key);
        $this->assertSame((string) $execution->id, $notification->source_reference_id);
    }

    public function test_send_sms_delivers_to_target_phone_via_twilio_channel(): void
    {
        $messenger = Mockery::mock(TwilioMessenger::class);
        $messenger->shouldReceive('createMessage')
            ->once()
            ->withArgs(fn (array $config, string $to, array $params): bool => $to === '+5215512345678')
            ->andReturn((object) ['sid' => 'SM123', 'status' => 'queued']);
        $this->app->instance(TwilioMessenger::class, $messenger);

        NotificationChannel::factory()->sms()->create([
            'team_id' => $this->team->id,
            'config_json' => [
                'twilio_account_sid' => 'AC123',
                'twilio_auth_token' => 'tok-456',
                'from' => '+14155238886',
            ],
        ]);

        $execution = $this->makeExecution(ActionType::SendSms, [
            'target_type' => 'phone',
            'target_reference' => '+5215512345678',
            'payload_json' => ['body' => 'Unidad 42 en alerta.'],
        ]);

        $result = app(ExecuteAction::class)->execute($execution);

        $this->assertSame(ActionExecutionStatus::Completed, $result->status);
        $this->assertSame('sms', $result->response_json['channel']);
    }

    public function test_send_action_fails_when_no_recipient_resolves(): void
    {
        Event::fake([ActionFailed::class]);

        $execution = $this->makeExecution(ActionType::SendEmail, [
            'target_type' => 'role',
            'target_reference' => 'nonexistent_role',
        ]);

        $result = app(ExecuteAction::class)->execute($execution);

        $this->assertSame(ActionExecutionStatus::Failed, $result->status);
        $this->assertStringContainsString('Could not resolve recipients', (string) $result->error_message);

        Event::assertDispatched(ActionFailed::class);
    }

    public function test_assign_incident_action_creates_assignment(): void
    {
        $incident = Incident::factory()->open()->create(['team_id' => $this->team->id]);
        $assignee = User::factory()->create();

        $execution = $this->makeExecution(ActionType::AssignIncident, [
            'incident_id' => $incident->id,
            'target_type' => 'user',
            'target_reference' => (string) $assignee->id,
        ]);

        $result = app(ExecuteAction::class)->execute($execution);

        $this->assertSame(ActionExecutionStatus::Completed, $result->status);

        $assignment = IncidentAssignment::query()
            ->where('incident_id', $incident->id)
            ->whereNull('unassigned_at')
            ->first();

        $this->assertNotNull($assignment);
        $this->assertSame($assignee->id, $assignment->assigned_to_id);
    }

    public function test_escalate_action_transitions_incident_status(): void
    {
        $this->seed(IncidentsSeeder::class);

        $incident = Incident::factory()->open()->create(['team_id' => $this->team->id]);

        $execution = $this->makeExecution(ActionType::Escalate, [
            'incident_id' => $incident->id,
            'payload_json' => ['reason' => 'SLA at risk'],
        ]);

        $result = app(ExecuteAction::class)->execute($execution);

        $this->assertSame(ActionExecutionStatus::Completed, $result->status);
        $this->assertSame(IncidentStatusCode::Escalated->value, $incident->fresh()->status?->code);
    }

    public function test_request_human_review_action_moves_incident_to_in_review(): void
    {
        $this->seed(IncidentsSeeder::class);

        $incident = Incident::factory()->open()->create(['team_id' => $this->team->id]);

        $execution = $this->makeExecution(ActionType::RequestHumanReview, [
            'incident_id' => $incident->id,
        ]);

        $result = app(ExecuteAction::class)->execute($execution);

        $this->assertSame(ActionExecutionStatus::Completed, $result->status);
        $this->assertSame(IncidentStatusCode::InReview->value, $incident->fresh()->status?->code);
        $this->assertSame(1, IncidentTimeline::query()
            ->where('incident_id', $incident->id)
            ->where('title', 'Revisión humana solicitada')
            ->count());
    }

    public function test_incident_actions_fail_without_linked_incident(): void
    {
        Event::fake([ActionFailed::class]);

        $execution = $this->makeExecution(ActionType::Escalate, [
            'incident_id' => null,
            'source_reference_id' => null,
        ]);

        $result = app(ExecuteAction::class)->execute($execution);

        $this->assertSame(ActionExecutionStatus::Failed, $result->status);
        $this->assertStringContainsString('requires a linked incident', (string) $result->error_message);
    }

    public function test_create_ticket_and_update_asset_state_remain_deferred(): void
    {
        foreach ([ActionType::CreateTicket, ActionType::UpdateAssetState] as $type) {
            $execution = $this->makeExecution($type);

            $result = app(ExecuteAction::class)->execute($execution);

            $this->assertSame(ActionExecutionStatus::Completed, $result->status);
            $this->assertTrue((bool) ($result->response_json['stub'] ?? false));
            $this->assertSame('deferred_v2', $result->response_json['reason']);
        }
    }

    public function test_completed_action_records_metered_usage(): void
    {
        $this->seed(AutomationMeterSeeder::class);
        Mail::fake();

        NotificationChannel::factory()->email()->create(['team_id' => $this->team->id]);

        $execution = $this->makeExecution(ActionType::SendEmail, [
            'target_type' => 'email',
            'target_reference' => 'ops@example.test',
        ]);

        app(ExecuteAction::class)->execute($execution);

        $this->assertSame(1, UsageEvent::withoutGlobalScopes()
            ->where('team_id', $this->team->id)
            ->where('event_key', 'automation_action:'.$execution->id)
            ->count());
    }
}
