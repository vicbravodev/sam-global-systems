<?php

namespace Tests\Feature\Domains\Notifications\Drivers;

use App\Contracts\Notifications\NotificationDriver;
use App\Domains\Notifications\Actions\DispatchNotification;
use App\Domains\Notifications\Channels\SlackNotificationDriver;
use App\Domains\Notifications\Channels\WebhookNotificationDriver;
use App\Domains\Notifications\Data\RenderedNotification;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\DeliveryStatus;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\RecipientType;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\Concerns\FakesHostResolution;
use Tests\TestCase;

/**
 * Los drivers de Slack y webhook saliente hacían POST a la URL del canal sin
 * pasar por OutboundUrlGuard: SSRF hacia metadata de la nube, loopback, IPs
 * privadas o los servicios internos de compose (pgsql, valkey, rustfs...).
 */
class NotificationDriverSsrfTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, FakesHostResolution, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeDns([
            'hooks.example.com' => ['93.184.216.34'],
            'rebind.example.com' => ['10.0.0.7'],
        ]);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function unsafeUrls(): array
    {
        return [
            'metadata de la nube' => ['https://169.254.169.254/latest/meta-data/', 'blocked_ip'],
            'loopback literal' => ['https://127.0.0.1:8080/', 'blocked_ip'],
            'localhost' => ['https://localhost/admin', 'reserved_host'],
            'ip privada' => ['https://10.1.2.3/hook', 'blocked_ip'],
            'host interno de compose' => ['http://pgsql:5432/', 'reserved_host'],
            'host *.internal' => ['https://rustfs.internal/bucket', 'reserved_host'],
            'dns que resuelve a red privada' => ['https://rebind.example.com/hook', 'blocked_ip'],
            'ipv6 loopback' => ['https://[::1]/', 'blocked_ip'],
            'ip en notación hex' => ['https://0x7f.0.0.1/', 'numeric_host'],
        ];
    }

    #[DataProvider('unsafeUrls')]
    public function test_slack_delivery_to_an_unsafe_url_fails_permanently_without_a_request(string $url, string $code): void
    {
        Http::fake();

        $channel = NotificationChannel::factory()->create([
            'channel_type' => ChannelType::Slack,
            'provider' => 'slack',
            'config_json' => ['slack_webhook_url' => $url],
        ]);

        $result = app(SlackNotificationDriver::class)->send($this->rendered(ChannelType::Slack), $channel);

        $this->assertFalse($result->success);
        $this->assertTrue($result->permanent);
        $this->assertSame('unsafe_url', $result->providerErrorCode);
        $this->assertSame($code, $result->response['unsafe_url_code']);
        Http::assertNothingSent();

        $this->assertBlockedLogged($channel, 'slack', $code, $url);
    }

    #[DataProvider('unsafeUrls')]
    public function test_webhook_delivery_to_an_unsafe_url_fails_permanently_without_a_request(string $url, string $code): void
    {
        Http::fake();

        $channel = NotificationChannel::factory()->create([
            'channel_type' => ChannelType::Webhook,
            'provider' => 'webhook',
            'config_json' => ['endpoint_url' => $url, 'secret' => 'topsecret'],
        ]);

        $result = app(WebhookNotificationDriver::class)->send($this->rendered(ChannelType::Webhook), $channel);

        $this->assertFalse($result->success);
        $this->assertTrue($result->permanent);
        $this->assertSame('unsafe_url', $result->providerErrorCode);
        $this->assertSame($code, $result->response['unsafe_url_code']);
        Http::assertNothingSent();

        $this->assertBlockedLogged($channel, 'webhook', $code, $url);
    }

    public function test_public_urls_are_pinned_to_the_validated_ip_and_do_not_follow_redirects(): void
    {
        Http::fake(['https://hooks.example.com/*' => Http::response('ok', 200)]);

        foreach ([
            [ChannelType::Slack, SlackNotificationDriver::class, ['slack_webhook_url' => 'https://hooks.example.com/slack']],
            [ChannelType::Webhook, WebhookNotificationDriver::class, ['endpoint_url' => 'https://hooks.example.com/hook', 'secret' => 's']],
        ] as [$type, $driverClass, $config]) {
            $channel = NotificationChannel::factory()->create([
                'channel_type' => $type,
                'provider' => $type->value,
                'config_json' => $config,
            ]);

            /** @var NotificationDriver $driver */
            $driver = app($driverClass);
            $result = $driver->send($this->rendered($type), $channel);

            $this->assertTrue($result->success, $type->value);
        }

        Http::assertSentCount(2);
        $this->assertSystemNotLogged('notifications.outbound_url.blocked');
    }

    public function test_a_redirect_response_is_a_permanent_failure_and_is_not_followed(): void
    {
        Http::fake(['https://hooks.example.com/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/'])]);

        $channel = NotificationChannel::factory()->create([
            'channel_type' => ChannelType::Webhook,
            'provider' => 'webhook',
            'config_json' => ['endpoint_url' => 'https://hooks.example.com/hook', 'secret' => 's'],
        ]);

        $result = app(WebhookNotificationDriver::class)->send($this->rendered(ChannelType::Webhook), $channel);

        $this->assertFalse($result->success);
        $this->assertTrue($result->permanent);
        $this->assertSame('redirect_not_followed', $result->providerErrorCode);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '169.254.169.254'));
    }

    public function test_blocked_delivery_is_recorded_as_a_permanent_failure_without_leaking_across_tenants(): void
    {
        Http::fake();
        Queue::fake();

        $user = User::factory()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        $otherTeam = Team::factory()->create();
        $otherNotification = Notification::factory()->create(['team_id' => $otherTeam->id]);

        NotificationChannel::factory()->create([
            'channel_type' => ChannelType::Webhook,
            'provider' => 'webhook',
            'config_json' => ['endpoint_url' => 'https://169.254.169.254/latest/meta-data/', 'secret' => 's'],
        ]);

        $notification = Notification::factory()->create([
            'team_id' => $team->id,
            'priority' => NotificationPriority::Normal,
            'payload_json' => [
                'force_channels' => ['webhook'],
                'recipients' => [[
                    'recipient_type' => RecipientType::ExternalContact->value,
                    'address' => 'https://hooks.example.com/recipient',
                ]],
            ],
        ]);

        $this->assertNoTenantLeak($team, fn () => app(DispatchNotification::class)->execute($notification));

        $delivery = NotificationDelivery::query()->where('notification_id', $notification->id)->sole();
        $this->assertSame(DeliveryStatus::Failed, $delivery->status);
        $this->assertTrue((bool) $delivery->permanent_failure);
        $this->assertSame('unsafe_url', $delivery->provider_error_code);
        $this->assertSame($team->id, $delivery->team_id);
        $this->assertSame(0, NotificationDelivery::query()->withoutGlobalScopes()->where('notification_id', $otherNotification->id)->count());
        Http::assertNothingSent();

        $this->assertSystemLogged('notifications.outbound_url.blocked');
        $this->assertSystemLogged('notifications.delivery.failed', fn (array $c) => $c['reason'] === 'permanent_failure'
            && $c['result']['provider_error_code'] === 'unsafe_url');
        $this->assertNoSensitiveDataLogged();
    }

    private function assertBlockedLogged(NotificationChannel $channel, string $driver, string $code, string $url): void
    {
        $this->assertSystemLogged('notifications.outbound_url.blocked', fn (array $c) => $c['reason'] === 'unsafe_url'
            && $c['input']['channel_id'] === $channel->id
            && $c['input']['channel_type'] === $channel->channel_type->value
            && $c['input']['driver'] === $driver
            && $c['calc']['unsafe_url_code'] === $code
            && $c['result']['request_sent'] === false
            && $c['result']['permanent'] === true);

        $host = (string) parse_url($url, PHP_URL_HOST);
        $logged = (string) json_encode($this->systemLogEntries());
        $this->assertStringNotContainsString(trim($host, '[]'), $logged);
        $this->assertNoSensitiveDataLogged();
    }

    private function rendered(ChannelType $type): RenderedNotification
    {
        return new RenderedNotification(
            channelType: $type,
            address: '#ops',
            subject: 'Hello',
            body: 'world',
            variables: [],
            recipientName: 'Ops',
        );
    }
}
