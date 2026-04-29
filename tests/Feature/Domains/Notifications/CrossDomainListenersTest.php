<?php

namespace Tests\Feature\Domains\Notifications;

use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\NotificationTriggeredByType;
use App\Domains\Notifications\Listeners\NotifyOnActionExecutionCompleted;
use App\Domains\Notifications\Listeners\NotifyOnIncidentCreated;
use App\Domains\Notifications\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\Fakes\FakeActionExecutionCompletedEvent;
use Tests\Fakes\FakeIncidentCreatedEvent;
use Tests\TestCase;

/**
 * Validates that the string-based listeners registered by NotificationsServiceProvider
 * react to fake spec-11/spec-12 events without depending on those specs being merged.
 *
 * The listeners are registered against the fake-event FQCN string in setUp via the
 * Event facade so we don't have to fork the production binding.
 */
class CrossDomainListenersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::listen(
            FakeIncidentCreatedEvent::class,
            NotifyOnIncidentCreated::class,
        );

        Event::listen(
            FakeActionExecutionCompletedEvent::class,
            NotifyOnActionExecutionCompleted::class,
        );
    }

    public function test_incident_created_listener_creates_notification(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        FakeIncidentCreatedEvent::dispatch($team->id, 42, 'speeding', 'high');

        $notification = Notification::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('event_key', 'incident_created:42')
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame(NotificationPriority::High, $notification->priority);
        $this->assertSame(NotificationSourceType::Incident, $notification->source_type);
        $this->assertSame('42', $notification->source_reference_id);
    }

    public function test_action_execution_listener_only_acts_on_send_actions(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        FakeActionExecutionCompletedEvent::dispatch($team->id, 88, 'rollback_change', []);

        $this->assertSame(0, Notification::withoutGlobalScopes()->where('team_id', $team->id)->count());

        FakeActionExecutionCompletedEvent::dispatch($team->id, 89, 'send_email', [
            'subject' => 'Hello from automation',
            'body_preview' => 'A scheduled send',
        ]);

        $notification = Notification::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('event_key', 'action_execution:89')
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame(NotificationTriggeredByType::Automation, $notification->triggered_by_type);
        $this->assertSame('Hello from automation', $notification->subject);
    }

    public function test_listener_idempotent_when_dispatched_twice(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        FakeIncidentCreatedEvent::dispatch($team->id, 7, 'crash', 'critical');
        FakeIncidentCreatedEvent::dispatch($team->id, 7, 'crash', 'critical');

        $count = Notification::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('event_key', 'incident_created:7')
            ->count();

        $this->assertSame(1, $count);
    }
}
