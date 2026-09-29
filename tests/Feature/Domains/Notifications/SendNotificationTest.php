<?php

namespace Tests\Feature\Domains\Notifications;

use App\Domains\Notifications\Actions\SendNotification;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\NotificationStatus;
use App\Domains\Notifications\Enums\NotificationTriggeredByType;
use App\Domains\Notifications\Jobs\SendNotificationJob;
use App\Domains\Notifications\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class SendNotificationTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

    public function test_creates_notification_and_dispatches_job(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        $notification = app(SendNotification::class)->execute(
            teamId: $team->id,
            notificationType: 'incident.created',
            sourceType: NotificationSourceType::Incident,
            sourceReferenceId: '101',
            priority: NotificationPriority::High,
            triggeredByType: NotificationTriggeredByType::System,
            triggeredById: null,
            eventKey: 'incident_created:101',
            payload: [
                'incident_type' => 'crash',
                'incident_title' => 'Choque en la unidad Titán',
                'force_channels' => ['web', 'email', 'carrier_pigeon'],
                'recipients' => [['address' => 'ops@example.com']],
            ],
            subject: 'New incident',
            bodyPreview: 'A new incident has been reported.',
        );

        $this->assertSame(NotificationStatus::Queued, $notification->status);
        $this->assertSame('incident_created:101', $notification->event_key);
        Bus::assertDispatched(SendNotificationJob::class, fn (SendNotificationJob $job) => $job->notificationId === $notification->id);

        $this->assertSystemLogged('notifications.notification.requested', fn (array $c) => $c['outcome'] === 'ok'
            && $c['input']['notification_type'] === 'incident.created'
            && $c['input']['source_type'] === 'incident'
            && $c['input']['source_reference_id'] === '101'
            && $c['input']['priority'] === 'high'
            && $c['input']['triggered_by_type'] === 'system'
            && $c['calc']['explicit_recipients_count'] === 1
            && $c['calc']['forced_channel_types'] === ['web', 'email']
            && $c['result']['notification_id'] === $notification->id
            && $c['result']['job_requested'] === true);

        $logged = json_encode($this->systemLogEntries());
        $this->assertStringNotContainsString('incident_created:101', $logged);
        $this->assertStringNotContainsString('New incident', $logged);
        $this->assertStringNotContainsString('A new incident has been reported.', $logged);
        $this->assertStringNotContainsString('Titán', $logged);
        $this->assertStringNotContainsString('ops@example.com', $logged);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_idempotent_on_team_id_and_event_key(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        $send = app(SendNotification::class);

        $first = $send->execute(
            teamId: $team->id,
            notificationType: 'incident.created',
            sourceType: NotificationSourceType::Incident,
            sourceReferenceId: '101',
            priority: NotificationPriority::Normal,
            triggeredByType: NotificationTriggeredByType::System,
            triggeredById: null,
            eventKey: 'incident_created:101',
        );

        $second = $send->execute(
            teamId: $team->id,
            notificationType: 'incident.created',
            sourceType: NotificationSourceType::Incident,
            sourceReferenceId: '101',
            priority: NotificationPriority::Normal,
            triggeredByType: NotificationTriggeredByType::System,
            triggeredById: null,
            eventKey: 'incident_created:101',
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Notification::withoutGlobalScopes()->where('team_id', $team->id)->count());

        $this->assertCount(1, $this->systemLogEntries('notifications.notification.requested'));
        $this->assertSystemLogged('notifications.dedup.skipped', fn (array $c) => $c['reason'] === 'event_key_exists'
            && $c['input']['notification_type'] === 'incident.created'
            && $c['input']['source_type'] === 'incident'
            && $c['result']['existing_notification_id'] === $first->id);
        $this->assertNoSensitiveDataLogged();
    }
}
