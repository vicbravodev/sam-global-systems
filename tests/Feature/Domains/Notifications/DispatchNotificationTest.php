<?php

namespace Tests\Feature\Domains\Notifications;

use App\Contracts\Notifications\ChannelDriverRegistry;
use App\Contracts\Notifications\NotificationDriver;
use App\Domains\Incidents\Actions\CreateIncidentFromEvent;
use App\Domains\Incidents\Events\IncidentCreated;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentEventLink;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Notifications\Actions\DispatchNotification;
use App\Domains\Notifications\Data\DeliveryResult;
use App\Domains\Notifications\Data\RenderedNotification;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\DeliveryStatus;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationStatus;
use App\Domains\Notifications\Enums\RecipientType;
use App\Domains\Notifications\Events\NotificationCreated;
use App\Domains\Notifications\Events\NotificationDelivered;
use App\Domains\Notifications\Events\NotificationPushedBroadcast;
use App\Domains\Notifications\Mail\GenericNotificationMail;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Models\NotificationPreference;
use App\Domains\Notifications\Models\NotificationRecipient;
use App\Domains\Tenancy\Events\UsageRecorded;
use App\Models\User;
use Database\Seeders\IncidentsSeeder;
use Database\Seeders\NotificationMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class DispatchNotificationTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(NotificationMeterSeeder::class);
    }

    public function test_email_delivery_creates_records_and_emits_usage(): void
    {
        Mail::fake();
        Event::fake([
            NotificationCreated::class,
            NotificationDelivered::class,
            UsageRecorded::class,
        ]);

        $user = User::factory()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        NotificationChannel::factory()->email()->create([
            'is_active' => true,
        ]);

        $notification = Notification::factory()->create([
            'team_id' => $team->id,
            'notification_type' => 'manual.test',
            'priority' => NotificationPriority::Normal,
            'status' => NotificationStatus::Queued,
            'subject' => 'Hello',
            'body_preview' => 'Hello body',
            'payload_json' => [
                'recipients' => [
                    [
                        'recipient_type' => RecipientType::ExternalContact->value,
                        'address' => 'ops@example.com',
                        'name' => 'Ops',
                    ],
                ],
            ],
        ]);

        app(DispatchNotification::class)->execute($notification);

        $this->assertSame(NotificationStatus::Sent, $notification->refresh()->status);
        $this->assertSame(1, NotificationRecipient::withoutGlobalScopes()->where('notification_id', $notification->id)->count());

        $delivery = NotificationDelivery::withoutGlobalScopes()->where('notification_id', $notification->id)->first();
        $this->assertNotNull($delivery);
        $this->assertSame(DeliveryStatus::Delivered, $delivery->status);

        Mail::assertSent(GenericNotificationMail::class);
        Event::assertDispatched(NotificationCreated::class);
        Event::assertDispatched(NotificationDelivered::class);
        Event::assertDispatched(UsageRecorded::class, fn (UsageRecorded $ev) => $ev->meterCode === 'outbound_notifications');

        $recipient = NotificationRecipient::withoutGlobalScopes()->where('notification_id', $notification->id)->sole();

        $this->assertSystemLogged('notifications.recipients.resolved', fn (array $c) => $c['outcome'] === 'ok'
            && $c['input']['notification_id'] === $notification->id
            && $c['calc']['source'] === 'explicit'
            && $c['calc']['candidates_count'] === 1
            && $c['calc']['dropped_count_by_reason'] === []
            && $c['result']['recipient_ids'] === [$recipient->id]
            && $c['result']['recipients_count'] === 1
            && $c['result']['recipients_reused'] === false);
        $this->assertSystemLogged('notifications.channels.selected', fn (array $c) => $c['outcome'] === 'ok'
            && $c['input']['recipient_id'] === $recipient->id
            && $c['calc']['branch'] === 'allowed_types'
            && $c['calc']['selected_channel_ids'] === [$delivery->channel_id]);
        $this->assertSame('debug', $this->systemLogEntries('notifications.channels.selected')[0]['level']);
        $this->assertSystemLogged('notifications.delivery.sent', fn (array $c) => $c['outcome'] === 'ok'
            && $c['input']['delivery_id'] === $delivery->id
            && $c['input']['notification_id'] === $notification->id
            && $c['input']['recipient_id'] === $recipient->id
            && $c['input']['channel_type'] === 'email'
            && $c['input']['stage'] === 'first'
            && $c['input']['attempt_number'] === 1
            && $c['result']['delivery_status'] === 'delivered'
            && $c['result']['awaiting_provider_confirmation'] === false
            && $c['result']['charge_recorded'] === false
            && $c['result']['usage_meter_code'] === 'outbound_notifications'
            && array_key_exists('duration_ms', $c));
        $this->assertSystemLogged('notifications.dispatch.completed', fn (array $c) => $c['input']['notification_id'] === $notification->id
            && $c['calc']['recipients_count'] === 1
            && $c['calc']['deliveries_attempted_count'] === 1
            && $c['calc']['deliveries_skipped_count_by_reason'] === []
            && $c['calc']['sent_count'] === 1
            && $c['calc']['failed_count'] === 0
            && $c['result']['notification_status'] === 'sent');

        $logged = json_encode($this->systemLogEntries());
        $this->assertStringNotContainsString('ops@example.com', $logged);
        $this->assertStringNotContainsString('Hello', $logged);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_team_fanout_logs_its_narrative_without_recipient_data_or_content(): void
    {
        Mail::fake();

        $user = User::factory()->create(['name' => 'Rigoberta Menchú', 'email' => 'rigoberta@example.com']);
        $team = $user->currentTeam;
        $this->actingAs($user);

        NotificationChannel::factory()->email()->create(['is_active' => true]);

        $notification = Notification::factory()->create([
            'team_id' => $team->id,
            'notification_type' => 'manual.test',
            'priority' => NotificationPriority::Normal,
            'status' => NotificationStatus::Queued,
            'subject' => 'Asunto confidencial',
            'body_preview' => 'Cuerpo confidencial del aviso',
            'payload_json' => [],
        ]);

        app(DispatchNotification::class)->execute($notification);

        $this->assertSystemLogged('notifications.recipients.resolved', fn (array $c) => $c['calc']['source'] === 'team_members'
            && $c['calc']['candidates_count'] === 1
            && $c['result']['recipients_count'] === 1);
        $this->assertSystemLogged('notifications.delivery.sent', fn (array $c) => $c['input']['channel_type'] === 'email'
            && $c['input']['stage'] === 'first');

        $logged = json_encode($this->systemLogEntries());
        $this->assertStringNotContainsString('rigoberta@example.com', $logged);
        $this->assertStringNotContainsString('Rigoberta', $logged);
        $this->assertStringNotContainsString('Asunto confidencial', $logged);
        $this->assertStringNotContainsString('Cuerpo confidencial', $logged);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_notification_without_usable_recipients_logs_why_and_is_cancelled(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        $notification = Notification::factory()->create([
            'team_id' => $team->id,
            'notification_type' => 'manual.test',
            'priority' => NotificationPriority::Normal,
            'status' => NotificationStatus::Queued,
            'payload_json' => [
                'recipients' => [
                    ['recipient_type' => RecipientType::ExternalContact->value, 'name' => 'Sin dirección'],
                ],
            ],
        ]);

        app(DispatchNotification::class)->execute($notification);

        $this->assertSame(NotificationStatus::Cancelled, $notification->refresh()->status);
        $this->assertSystemLogged('notifications.recipients.resolved', fn (array $c) => $c['outcome'] === 'skipped'
            && $c['reason'] === 'no_recipients'
            && $c['input']['notification_id'] === $notification->id
            && $c['calc']['source'] === 'explicit'
            && $c['calc']['candidates_count'] === 1
            && $c['calc']['dropped_count_by_reason'] === ['no_address_count' => 1]);
        $this->assertSystemNotLogged('notifications.dispatch.completed');
        $this->assertStringNotContainsString('Sin dirección', json_encode($this->systemLogEntries()));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_delivery_that_cannot_be_recorded_is_logged_instead_of_swallowed(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        $channel = NotificationChannel::factory()->email()->create(['is_active' => true]);

        $notification = Notification::factory()->create([
            'team_id' => $team->id,
            'notification_type' => 'manual.test',
            'priority' => NotificationPriority::Normal,
            'status' => NotificationStatus::Queued,
            'payload_json' => [
                'recipients' => [
                    ['recipient_type' => RecipientType::ExternalContact->value, 'address' => 'ops@example.com'],
                ],
            ],
        ]);

        NotificationDelivery::creating(fn () => throw new RuntimeException('insert rechazado'));

        app(DispatchNotification::class)->execute($notification);

        $this->assertSame(0, NotificationDelivery::withoutGlobalScopes()->where('notification_id', $notification->id)->count());
        Mail::assertNothingSent();

        $recipient = NotificationRecipient::withoutGlobalScopes()->where('notification_id', $notification->id)->sole();
        $this->assertSystemLogged('notifications.delivery.create_failed', fn (array $c) => $c['outcome'] === 'degraded'
            && $c['reason'] === 'record_failed'
            && $c['input']['notification_id'] === $notification->id
            && $c['input']['recipient_id'] === $recipient->id
            && $c['input']['channel_id'] === $channel->id
            && isset($c['error']));
        $this->assertSystemLogged('notifications.dispatch.completed', fn (array $c) => $c['calc']['deliveries_skipped_count_by_reason'] === ['record_failed_count' => 1]
            && $c['calc']['deliveries_attempted_count'] === 0);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_skipped_delivery_that_cannot_be_recorded_is_logged_and_the_loop_continues(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        $channel = NotificationChannel::factory()->email()->create(['is_active' => true]);

        $notification = Notification::factory()->create([
            'team_id' => $team->id,
            'notification_type' => 'manual.test',
            'priority' => NotificationPriority::Normal,
            'status' => NotificationStatus::Queued,
            'payload_json' => [
                'recipients' => [
                    ['recipient_type' => RecipientType::ExternalContact->value, 'address' => 'no-es-un-correo'],
                ],
            ],
        ]);

        NotificationDelivery::creating(fn () => throw new RuntimeException('insert rechazado'));

        app(DispatchNotification::class)->execute($notification);

        $this->assertSame(0, NotificationDelivery::withoutGlobalScopes()->where('notification_id', $notification->id)->count());
        Mail::assertNothingSent();

        $recipient = NotificationRecipient::withoutGlobalScopes()->where('notification_id', $notification->id)->sole();
        $this->assertSystemLogged('notifications.delivery.skip_record_failed', fn (array $c) => $c['outcome'] === 'degraded'
            && $c['reason'] === 'record_failed'
            && $c['input']['notification_id'] === $notification->id
            && $c['input']['recipient_id'] === $recipient->id
            && $c['input']['channel_id'] === $channel->id
            && $c['input']['skip_reason'] === 'email address is not a valid email'
            && isset($c['error']));
        $this->assertSystemLogged('notifications.dispatch.completed');
        $this->assertStringNotContainsString('no-es-un-correo', json_encode($this->systemLogEntries('notifications.delivery.skip_record_failed')));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_rolled_back_incident_never_logs_the_requested_notification(): void
    {
        Bus::fake();
        $this->seed(IncidentsSeeder::class);
        $teamId = User::factory()->create()->currentTeam->id;

        // Falla algo DENTRO de la transacción de la apertura (el vínculo del
        // evento raíz): un listener de IncidentCreated ya no puede revertirla
        // porque corre tras el commit (IncidentCreatedReactionsTest).
        Event::listen('eloquent.created: '.IncidentEventLink::class, fn () => throw new RuntimeException('boom'));

        $event = NormalizedEvent::factory()->create(['team_id' => $teamId]);

        try {
            app(CreateIncidentFromEvent::class)->execute($event, ['incident_type_code' => 'panic_emergency', 'priority_code' => 'critical']);
            $this->fail('La creación debía lanzar.');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame(0, Incident::withoutGlobalScopes()->count());
        $this->assertSame(0, Notification::withoutGlobalScopes()->count());
        $this->assertSystemNotLogged('notifications.notification.requested');
        $this->assertSystemLogged('incidents.type.resolved');
    }

    public function test_does_not_create_duplicate_delivery_for_same_recipient_channel(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        $channel = NotificationChannel::factory()->email()->create([
            'is_active' => true,
        ]);

        $notification = Notification::factory()->create([
            'team_id' => $team->id,
            'notification_type' => 'manual.test',
            'priority' => NotificationPriority::Normal,
            'status' => NotificationStatus::Queued,
            'payload_json' => [
                'recipients' => [
                    ['recipient_type' => RecipientType::ExternalContact->value, 'address' => 'ops@example.com'],
                ],
            ],
        ]);

        $recipient = NotificationRecipient::factory()->create([
            'notification_id' => $notification->id,
            'team_id' => $team->id,
            'address' => 'ops@example.com',
        ]);

        NotificationDelivery::factory()->create([
            'notification_id' => $notification->id,
            'recipient_id' => $recipient->id,
            'channel_id' => $channel->id,
            'team_id' => $team->id,
            'status' => DeliveryStatus::Delivered,
        ]);

        // Re-dispatch — should not duplicate the (notification, recipient, channel) row.
        app(DispatchNotification::class)->execute($notification);

        $count = NotificationDelivery::withoutGlobalScopes()
            ->where('notification_id', $notification->id)
            ->where('recipient_id', $recipient->id)
            ->where('channel_id', $channel->id)
            ->count();

        $this->assertSame(1, $count);
    }

    public function test_re_dispatching_same_notification_is_idempotent_and_does_not_resend(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        NotificationChannel::factory()->email()->create([
            'is_active' => true,
        ]);

        $notification = Notification::factory()->create([
            'team_id' => $team->id,
            'notification_type' => 'manual.test',
            'priority' => NotificationPriority::Normal,
            'status' => NotificationStatus::Queued,
            'payload_json' => [
                'recipients' => [
                    ['recipient_type' => RecipientType::ExternalContact->value, 'address' => 'ops@example.com'],
                ],
            ],
        ]);

        $dispatch = app(DispatchNotification::class);

        // Reproduces SendNotificationJob being retried ($tries = 3): the same
        // notification is fanned out twice. The retry must not duplicate
        // recipients/deliveries nor re-send the real message.
        $dispatch->execute($notification);
        $dispatch->execute($notification);

        $this->assertSame(
            1,
            NotificationRecipient::withoutGlobalScopes()->where('notification_id', $notification->id)->count(),
        );
        $this->assertSame(
            1,
            NotificationDelivery::withoutGlobalScopes()->where('notification_id', $notification->id)->count(),
        );

        Mail::assertSent(GenericNotificationMail::class, 1);

        $delivery = NotificationDelivery::withoutGlobalScopes()->where('notification_id', $notification->id)->sole();
        $dedup = $this->systemLogEntries('notifications.dedup.skipped');
        $this->assertCount(1, $dedup);
        $this->assertSame('debug', $dedup[0]['level']);
        $this->assertSame('delivery_exists', $dedup[0]['context']['reason']);
        $this->assertSame($delivery->recipient_id, $dedup[0]['context']['input']['recipient_id']);
        $this->assertSame($delivery->channel_id, $dedup[0]['context']['input']['channel_id']);

        $resolved = $this->systemLogEntries('notifications.recipients.resolved');
        $this->assertCount(2, $resolved);
        $this->assertFalse($resolved[0]['context']['result']['recipients_reused']);
        $this->assertTrue($resolved[1]['context']['result']['recipients_reused']);

        $this->assertCount(1, $this->systemLogEntries('notifications.delivery.sent'));
        $this->assertSystemLogged('notifications.dispatch.completed', fn (array $c) => $c['calc']['deliveries_skipped_count_by_reason'] === ['delivery_exists_count' => 1]
            && $c['calc']['deliveries_attempted_count'] === 0);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_retry_after_mid_loop_failure_does_not_resend_already_delivered(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        NotificationChannel::factory()->email()->create([
            'is_active' => true,
        ]);

        // Driver that records every send and crashes on the second one,
        // reproducing a failure mid fan-out (the real failure mode is post-send
        // bookkeeping — RecordDeliveryAttempt / RecordUsageEvent — throwing or
        // hitting the job timeout after the provider call already went out).
        $driver = new class implements NotificationDriver
        {
            /** @var array<int, string> */
            public array $sends = [];

            public int $calls = 0;

            public function send(RenderedNotification $notification, NotificationChannel $channel): DeliveryResult
            {
                $this->calls++;
                $this->sends[] = $notification->address;

                if ($this->calls === 2) {
                    throw new RuntimeException('simulated mid-loop crash');
                }

                return DeliveryResult::success(providerMessageId: 'fake-'.$this->calls);
            }
        };

        $this->app->instance(ChannelDriverRegistry::class, new class($driver) implements ChannelDriverRegistry
        {
            public function __construct(private NotificationDriver $driver) {}

            public function driverFor(ChannelType $channelType): NotificationDriver
            {
                return $this->driver;
            }
        });

        $notification = Notification::factory()->create([
            'team_id' => $team->id,
            'notification_type' => 'manual.test',
            'priority' => NotificationPriority::Normal,
            'status' => NotificationStatus::Queued,
            'payload_json' => [
                'recipients' => [
                    ['recipient_type' => RecipientType::ExternalContact->value, 'address' => 'first@example.com'],
                    ['recipient_type' => RecipientType::ExternalContact->value, 'address' => 'second@example.com'],
                ],
            ],
        ]);

        $dispatch = app(DispatchNotification::class);

        // First pass crashes after delivering to first@example.com.
        try {
            $dispatch->execute($notification);
            $this->fail('Expected the first pass to throw mid-loop.');
        } catch (RuntimeException $e) {
            $this->assertSame('simulated mid-loop crash', $e->getMessage());
        }

        // Retry must reuse the existing recipients/deliveries and never re-send
        // the message that already went out to first@example.com.
        $dispatch->execute($notification);

        $sendCounts = array_count_values($driver->sends);

        $this->assertSame(
            1,
            $sendCounts['first@example.com'] ?? 0,
            'Already-delivered recipient must not be re-sent on retry.',
        );
        $this->assertSame(
            2,
            NotificationRecipient::withoutGlobalScopes()->where('notification_id', $notification->id)->count(),
            'Retry must reuse recipients, not duplicate them.',
        );
        $this->assertSame(
            2,
            NotificationDelivery::withoutGlobalScopes()->where('notification_id', $notification->id)->count(),
            'Retry must not create duplicate deliveries.',
        );
    }

    public function test_critical_notification_uses_multiple_channels(): void
    {
        Mail::fake();
        Event::fake([NotificationDelivered::class]);

        $user = User::factory()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        NotificationChannel::factory()->email()->create(['is_active' => true]);
        NotificationChannel::factory()->sms()->create(['is_active' => true, 'channel_type' => ChannelType::Sms]);

        $notification = Notification::factory()->critical()->create([
            'team_id' => $team->id,
            'notification_type' => 'incident.critical',
            'status' => NotificationStatus::Queued,
            'payload_json' => [
                'recipients' => [
                    ['recipient_type' => RecipientType::ExternalContact->value, 'address' => 'ops@example.com'],
                ],
            ],
        ]);

        app(DispatchNotification::class)->execute($notification);

        $deliveries = NotificationDelivery::withoutGlobalScopes()->where('notification_id', $notification->id)->get();
        $this->assertGreaterThanOrEqual(2, $deliveries->count());
    }

    public function test_web_channel_broadcasts_notification_pushed(): void
    {
        Event::fake([NotificationPushedBroadcast::class, NotificationDelivered::class]);

        $user = User::factory()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        NotificationChannel::factory()->web()->create(['is_active' => true]);

        $notification = Notification::factory()->create([
            'team_id' => $team->id,
            'notification_type' => 'manual.web',
            'priority' => NotificationPriority::Normal,
            'status' => NotificationStatus::Queued,
            'payload_json' => [
                'recipients' => [
                    [
                        'recipient_type' => RecipientType::User->value,
                        'address' => $user->email,
                        'recipient_reference_id' => (string) $user->id,
                        'channel_preference' => ChannelType::Web->value,
                    ],
                ],
            ],
        ]);

        app(DispatchNotification::class)->execute($notification);

        Event::assertDispatched(NotificationPushedBroadcast::class, function (NotificationPushedBroadcast $event) use ($user, $notification) {
            return $event->userId === $user->id
                && $event->notificationId === $notification->id
                && $event->notificationType === 'manual.web'
                && $event->broadcastWith()['team_id'] === (int) $notification->team_id;
        });
    }

    public function test_muted_low_priority_notification_yields_no_deliveries(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        NotificationChannel::factory()->email()->create(['is_active' => true]);

        NotificationPreference::factory()->muted()->create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'notification_type' => 'manual.muted',
        ]);

        $notification = Notification::factory()->create([
            'team_id' => $team->id,
            'notification_type' => 'manual.muted',
            'priority' => NotificationPriority::Low,
            'status' => NotificationStatus::Queued,
            'payload_json' => [
                'recipients' => [
                    [
                        'recipient_type' => RecipientType::User->value,
                        'address' => $user->email,
                        'recipient_reference_id' => (string) $user->id,
                    ],
                ],
            ],
        ]);

        app(DispatchNotification::class)->execute($notification);

        $count = NotificationDelivery::withoutGlobalScopes()->where('notification_id', $notification->id)->count();

        $this->assertSame(0, $count);
        $this->assertSame(NotificationStatus::Cancelled, $notification->refresh()->status);

        // Antes, una selección vacía no dejaba rastro ni en la DB.
        $recipient = NotificationRecipient::withoutGlobalScopes()->where('notification_id', $notification->id)->sole();
        $this->assertSystemLogged('notifications.channels.selected', fn (array $c) => $c['outcome'] === 'skipped'
            && $c['reason'] === 'muted'
            && $c['input']['notification_id'] === $notification->id
            && $c['input']['recipient_id'] === $recipient->id
            && $c['input']['recipient_type'] === 'user'
            && $c['calc']['branch'] === 'muted'
            && $c['calc']['muted'] === true
            && $c['calc']['priority'] === 'low'
            && $c['calc']['selected_channel_ids'] === []);
        $this->assertSystemLogged('notifications.dispatch.completed', fn (array $c) => $c['calc']['deliveries_attempted_count'] === 0
            && $c['result']['notification_status'] === 'cancelled');
        $this->assertStringNotContainsString($user->email, json_encode($this->systemLogEntries()));
        $this->assertNoSensitiveDataLogged();
    }
}
