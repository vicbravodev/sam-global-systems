<?php

namespace Tests\Feature\Domains\Notifications\Drivers;

use App\Domains\Notifications\Channels\PushNotificationDriver;
use App\Domains\Notifications\Channels\WebPushMessenger;
use App\Domains\Notifications\Data\DeliveryResult;
use App\Domains\Notifications\Data\RenderedNotification;
use App\Domains\Notifications\Data\WebPushOutcome;
use App\Domains\Notifications\Data\WebPushTarget;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Models\PushSubscription;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

class PushNotificationDriverTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, RefreshDatabase;

    private User $user;

    private Team $team;

    private NotificationChannel $channel;

    /** @var array{targets: list<WebPushTarget>, payload: string, ttl: int, urgency: string}|null */
    private ?array $sent = null;

    /** @var \Closure(list<WebPushTarget>): list<WebPushOutcome> */
    private \Closure $respond;

    private bool $configured = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = $this->user->currentTeam;
        $this->channel = NotificationChannel::factory()->push()->create(['config_json' => null]);
        $this->respond = fn (array $targets): array => array_map(
            fn (WebPushTarget $t) => new WebPushOutcome($t->subscriptionId, true, false, 201, null),
            $targets,
        );

        $test = $this;
        $this->app->instance(WebPushMessenger::class, new class($test) extends WebPushMessenger
        {
            public function __construct(private readonly PushNotificationDriverTest $test) {}

            public function isConfigured(): bool
            {
                return $this->test->configured();
            }

            public function send(array $targets, string $payload, int $ttl, string $urgency): array
            {
                return $this->test->record($targets, $payload, $ttl, $urgency);
            }
        });
    }

    public function configured(): bool
    {
        return $this->configured;
    }

    /**
     * @param  list<WebPushTarget>  $targets
     * @return list<WebPushOutcome>
     */
    public function record(array $targets, string $payload, int $ttl, string $urgency): array
    {
        $this->sent = compact('targets', 'payload', 'ttl', 'urgency');

        return ($this->respond)($targets);
    }

    private function deliveryFor(User $user, NotificationPriority $priority = NotificationPriority::Critical, array $payload = ['incident_id' => 42]): RenderedNotification
    {
        $notification = Notification::factory()->create([
            'team_id' => $this->team->id,
            'priority' => $priority,
            'payload_json' => $payload,
        ]);
        $delivery = NotificationDelivery::factory()->create([
            'team_id' => $this->team->id,
            'notification_id' => $notification->id,
            'channel_id' => $this->channel->id,
        ]);

        return (new RenderedNotification(
            channelType: ChannelType::Push,
            address: (string) $user->id,
            subject: 'Botón de pánico en la unidad 12',
            body: 'SAM: Botón de pánico en la unidad 12.',
            variables: $payload,
        ))->forDelivery($delivery->id, false);
    }

    private function send(RenderedNotification $rendered): DeliveryResult
    {
        return app(PushNotificationDriver::class)->send($rendered, $this->channel);
    }

    public function test_sends_to_every_subscription_of_the_user_in_the_team(): void
    {
        PushSubscription::factory()->count(2)->forMember($this->user, $this->team)->create();

        $result = $this->send($this->deliveryFor($this->user));

        $this->assertTrue($result->success);
        $this->assertCount(2, $this->sent['targets']);
        $this->assertSame('high', $this->sent['urgency']);
        $this->assertSame(3600, $this->sent['ttl']);

        $payload = json_decode($this->sent['payload'], true);
        $this->assertSame('Botón de pánico en la unidad 12', $payload['title']);
        $this->assertSame('SAM: Botón de pánico en la unidad 12.', $payload['body']);
        $this->assertSame('incident-42', $payload['tag']);
        $this->assertTrue($payload['critical']);
        $this->assertTrue($payload['renotify']);
        $this->assertStringEndsWith('/'.$this->team->slug.'/incidents/42', $payload['url']);

        $this->assertNotNull(PushSubscription::withoutGlobalScopes()->first()->last_used_at);
        $this->assertSystemLogged('notifications.push.sent', fn (array $c) => $c['result']['successes'] === 2);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_non_critical_uses_normal_urgency_and_longer_ttl(): void
    {
        PushSubscription::factory()->forMember($this->user, $this->team)->create();

        $this->send($this->deliveryFor($this->user, NotificationPriority::High));

        $this->assertSame('normal', $this->sent['urgency']);
        $this->assertSame(86400, $this->sent['ttl']);
        $this->assertFalse(json_decode($this->sent['payload'], true)['critical']);
    }

    public function test_without_incident_it_links_to_the_notification(): void
    {
        PushSubscription::factory()->forMember($this->user, $this->team)->create();

        $this->send($this->deliveryFor($this->user, NotificationPriority::High, []));

        $payload = json_decode($this->sent['payload'], true);
        $this->assertStringStartsWith('notification-', $payload['tag']);
        $this->assertStringContainsString('/notifications/', $payload['url']);
    }

    public function test_prunes_expired_subscriptions_and_succeeds_with_the_rest(): void
    {
        $good = PushSubscription::factory()->forMember($this->user, $this->team)->create();
        $gone = PushSubscription::factory()->forMember($this->user, $this->team)->create();
        $this->respond = fn (array $targets): array => array_map(
            fn (WebPushTarget $t) => $t->subscriptionId === $gone->id
                ? new WebPushOutcome($t->subscriptionId, false, true, 410, 'Gone')
                : new WebPushOutcome($t->subscriptionId, true, false, 201, null),
            $targets,
        );

        $result = $this->send($this->deliveryFor($this->user));

        $this->assertTrue($result->success);
        $this->assertNotNull(PushSubscription::withoutGlobalScopes()->find($good->id));
        $this->assertNull(PushSubscription::withoutGlobalScopes()->find($gone->id));
        $this->assertSystemLogged('notifications.push.subscription_pruned', fn (array $c) => $c['result']['status_code'] === 410);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_fails_when_every_subscription_fails(): void
    {
        PushSubscription::factory()->forMember($this->user, $this->team)->create();
        $this->respond = fn (array $targets): array => array_map(
            fn (WebPushTarget $t) => new WebPushOutcome($t->subscriptionId, false, false, 500, 'Server error'),
            $targets,
        );

        $result = $this->send($this->deliveryFor($this->user));

        $this->assertFalse($result->success);
        $this->assertSystemLogged('notifications.push.failed', fn (array $c) => $c['reason'] === 'all_failed');
    }

    public function test_fails_without_subscriptions(): void
    {
        $result = $this->send($this->deliveryFor($this->user));

        $this->assertFalse($result->success);
        $this->assertNull($this->sent);
        $this->assertSystemLogged('notifications.push.failed', fn (array $c) => $c['reason'] === 'no_subscriptions');
    }

    public function test_does_not_send_to_the_users_subscription_in_another_team(): void
    {
        $otherTeam = Team::factory()->create();
        $otherTeam->members()->attach($this->user, ['role' => 'member']);
        PushSubscription::factory()->forMember($this->user, $otherTeam)->create();

        $result = $this->assertNoTenantLeak($this->team, fn () => $this->send($this->deliveryFor($this->user)));

        $this->assertFalse($result->success);
        $this->assertNull($this->sent);
    }

    public function test_fails_when_user_is_no_longer_a_member(): void
    {
        $outsider = User::factory()->create();
        PushSubscription::factory()->forMember($outsider, $this->team)->create();

        $result = $this->send($this->deliveryFor($outsider));

        $this->assertFalse($result->success);
        $this->assertNull($this->sent);
        $this->assertSystemLogged('notifications.push.failed', fn (array $c) => $c['reason'] === 'not_member');
    }

    public function test_fails_for_a_non_numeric_address(): void
    {
        $rendered = new RenderedNotification(ChannelType::Push, 'ana@example.com', 'x', 'y');

        $result = $this->send($rendered);

        $this->assertFalse($result->success);
        $this->assertTrue($result->permanent);
    }

    public function test_fails_degraded_when_vapid_is_not_configured(): void
    {
        $this->configured = false;
        PushSubscription::factory()->forMember($this->user, $this->team)->create();

        $result = $this->send($this->deliveryFor($this->user));

        $this->assertFalse($result->success);
        $this->assertTrue($result->permanent);
        $this->assertNull($this->sent);
        $this->assertSystemLogged('notifications.push.failed', fn (array $c) => $c['reason'] === 'not_configured');
    }
}
