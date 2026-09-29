<?php

namespace Tests\Feature\Domains\Notifications;

use App\Contracts\Notifications\ChannelDriverRegistry;
use App\Contracts\Notifications\NotificationDriver;
use App\Contracts\TenantConfig\TenantNotificationPoliciesResolver;
use App\Domains\Notifications\Actions\DispatchNotification;
use App\Domains\Notifications\Data\DeliveryResult;
use App\Domains\Notifications\Data\RenderedNotification;
use App\Domains\Notifications\Data\TenantNotificationPolicy;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\DeliveryStatus;
use App\Domains\Notifications\Enums\NotificationStatus;
use App\Domains\Notifications\Enums\RecipientType;
use App\Domains\Notifications\Jobs\FallbackNotificationChannelJob;
use App\Domains\Notifications\Jobs\RetryNotificationDeliveryJob;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Models\NotificationRecipient;
use App\Domains\Notifications\Models\TenantChannelToggle;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\NotificationMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * UI audit CHECK: a tenant that switched WhatsApp off must never get a
 * WhatsApp delivery — not from the dispatcher, not as a fallback target, and
 * not from a retry scheduled before the toggle changed (the toggle is checked
 * at send time).
 */
class ChannelToggleEnforcementTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, RefreshDatabase;

    private Team $team;

    /**
     * @var list<RenderedNotification>
     */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(NotificationMeterSeeder::class);
        Mail::fake();

        $user = User::factory()->create();
        $this->actingAs($user);
        $this->team = $user->currentTeam;

        $this->app->instance(TenantNotificationPoliciesResolver::class, new class implements TenantNotificationPoliciesResolver
        {
            public function resolve(Team $team): TenantNotificationPolicy
            {
                return new TenantNotificationPolicy(
                    allowedChannels: ChannelType::cases(),
                    criticalChannels: ChannelType::cases(),
                    fallbackChannels: [ChannelType::Whatsapp, ChannelType::Email],
                );
            }
        });

        $test = $this;
        $this->app->instance(ChannelDriverRegistry::class, new class($test) implements ChannelDriverRegistry
        {
            public function __construct(private readonly ChannelToggleEnforcementTest $test) {}

            public function driverFor(ChannelType $channelType): NotificationDriver
            {
                return new class($this->test) implements NotificationDriver
                {
                    public function __construct(private readonly ChannelToggleEnforcementTest $test) {}

                    public function send(RenderedNotification $notification, NotificationChannel $channel): DeliveryResult
                    {
                        $this->test->recordSent($notification);

                        return DeliveryResult::success('ok');
                    }
                };
            }
        });
    }

    public function recordSent(RenderedNotification $notification): void
    {
        $this->sent[] = $notification;
    }

    public function test_dispatch_never_selects_a_channel_the_tenant_switched_off(): void
    {
        $whatsapp = $this->channel(ChannelType::Whatsapp);
        $sms = $this->channel(ChannelType::Sms);
        $this->switchOff($whatsapp);

        $notification = Notification::factory()->critical()->create([
            'team_id' => $this->team->id,
            'notification_type' => 'incident.critical',
            'status' => NotificationStatus::Queued,
            'payload_json' => [
                'recipients' => [
                    ['recipient_type' => RecipientType::ExternalContact->value, 'address' => '+5215555550188', 'phone' => '+5215555550188'],
                ],
            ],
        ]);

        $this->assertNoTenantLeak($this->team, fn () => app(DispatchNotification::class)->execute($notification));

        $this->assertSame(0, NotificationDelivery::query()->where('channel_id', $whatsapp->id)->count());
        $this->assertSame(1, NotificationDelivery::query()->where('channel_id', $sms->id)->count());

        $this->assertSystemLogged('notifications.channels.selected', fn (array $c) => $c['outcome'] === 'ok'
            && in_array('sms', $c['calc']['usable_channel_types'], true)
            && ! in_array('whatsapp', $c['calc']['usable_channel_types'], true)
            && $c['calc']['selected_channel_ids'] === [$sms->id]);
        $this->assertSystemLogged('notifications.delivery.sent', fn (array $c) => $c['input']['channel_id'] === $sms->id
            && $c['input']['provider'] === 'twilio');
        $this->assertStringNotContainsString('5215555550188', json_encode($this->systemLogEntries()));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_fallback_skips_a_switched_off_channel_and_links_the_failed_delivery(): void
    {
        $sms = $this->channel(ChannelType::Sms);
        $whatsapp = $this->channel(ChannelType::Whatsapp);
        $email = $this->channel(ChannelType::Email);
        $this->switchOff($whatsapp);

        $failed = $this->failedDelivery($sms, attemptNumber: 5);

        app()->call([new FallbackNotificationChannelJob($failed->id), 'handle']);

        $this->assertSame(0, NotificationDelivery::query()->where('channel_id', $whatsapp->id)->count());

        $fallback = NotificationDelivery::query()->where('channel_id', $email->id)->sole();
        $this->assertSame($failed->id, $fallback->fallback_from_delivery_id);
    }

    public function test_retry_does_not_resend_on_a_channel_switched_off_after_the_failure(): void
    {
        $whatsapp = $this->channel(ChannelType::Whatsapp);
        $email = $this->channel(ChannelType::Email);

        $failed = $this->failedDelivery($whatsapp, attemptNumber: 1, payload: [
            'address' => '+5215512345678',
            'subject' => null,
            'body' => 'Pánico unidad 7',
        ]);

        // The tenant turns WhatsApp off while the retry is waiting.
        $this->switchOff($whatsapp);

        app()->call([new RetryNotificationDeliveryJob($failed->id), 'handle']);

        $this->assertFalse(collect($this->sent)->contains(fn (RenderedNotification $n) => $n->channelType === ChannelType::Whatsapp));
        $this->assertSame(DeliveryStatus::Cancelled, $failed->refresh()->status);
        $this->assertSame(1, $failed->attempt_number);

        // The recipient still gets the next usable channel of the policy.
        $this->assertSame(1, NotificationDelivery::query()->where('channel_id', $email->id)->count());
    }

    private function channel(ChannelType $type): NotificationChannel
    {
        return NotificationChannel::factory()->create([
            'channel_type' => $type,
            'provider' => in_array($type, [ChannelType::Sms, ChannelType::Whatsapp, ChannelType::Voice], true) ? 'twilio' : $type->value,
            'is_active' => true,
        ]);
    }

    private function switchOff(NotificationChannel $channel): void
    {
        TenantChannelToggle::factory()->disabled()->create([
            'team_id' => $this->team->id,
            'notification_channel_id' => $channel->id,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function failedDelivery(NotificationChannel $channel, int $attemptNumber, ?array $payload = null): NotificationDelivery
    {
        $notification = Notification::factory()->create(['team_id' => $this->team->id]);
        $recipient = NotificationRecipient::factory()->create([
            'notification_id' => $notification->id,
            'team_id' => $this->team->id,
            'address' => 'ops@example.com',
            'email' => 'ops@example.com',
            'phone' => '+5215512345678',
        ]);

        return NotificationDelivery::factory()->failed()->create([
            'notification_id' => $notification->id,
            'recipient_id' => $recipient->id,
            'channel_id' => $channel->id,
            'team_id' => $this->team->id,
            'attempt_number' => $attemptNumber,
            'payload_json' => $payload,
        ]);
    }
}
