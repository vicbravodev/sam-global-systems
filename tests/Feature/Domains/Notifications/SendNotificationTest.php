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
use Tests\TestCase;

class SendNotificationTest extends TestCase
{
    use RefreshDatabase;

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
            payload: ['incident_type' => 'crash'],
            subject: 'New incident',
            bodyPreview: 'A new incident has been reported.',
        );

        $this->assertSame(NotificationStatus::Queued, $notification->status);
        $this->assertSame('incident_created:101', $notification->event_key);
        Bus::assertDispatched(SendNotificationJob::class, fn (SendNotificationJob $job) => $job->notificationId === $notification->id);
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
    }
}
