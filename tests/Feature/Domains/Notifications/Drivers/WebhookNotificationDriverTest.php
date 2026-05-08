<?php

namespace Tests\Feature\Domains\Notifications\Drivers;

use App\Domains\Notifications\Channels\WebhookNotificationDriver;
use App\Domains\Notifications\Data\RenderedNotification;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebhookNotificationDriverTest extends TestCase
{
    use RefreshDatabase;

    private function rendered(): RenderedNotification
    {
        return new RenderedNotification(
            channelType: ChannelType::Webhook,
            address: 'https://example.com/hook',
            subject: 'Hello',
            body: 'world',
            variables: ['incident_type' => 'speeding'],
            recipientName: 'Ops',
        );
    }

    public function test_posts_payload_with_signed_headers_on_success(): void
    {
        Http::fake([
            'https://example.com/hook' => Http::response('', 202, ['X-Message-ID' => 'srv-9']),
        ]);

        $team = Team::factory()->create();
        $channel = NotificationChannel::factory()->create([
            'team_id' => $team->id,
            'channel_type' => ChannelType::Webhook,
            'provider' => 'webhook',
            'config_json' => [
                'endpoint_url' => 'https://example.com/hook',
                'secret' => 'topsecret',
            ],
        ]);

        $result = app(WebhookNotificationDriver::class)->send($this->rendered(), $channel);

        $this->assertTrue($result->success);
        $this->assertSame('srv-9', $result->providerMessageId);
        $this->assertSame(202, $result->response['status_code']);

        Http::assertSent(function (Request $request) {
            $sig = $request->header('X-SAM-Signature')[0] ?? '';
            $ts = $request->header('X-SAM-Timestamp')[0] ?? '';
            $key = $request->header('X-SAM-Event-Key')[0] ?? '';

            $this->assertStringStartsWith('sha256=', $sig);
            $this->assertNotSame('', $ts);
            $this->assertNotSame('', $key);

            $expected = 'sha256='.hash_hmac('sha256', $ts.'.'.$request->body(), 'topsecret');
            $this->assertSame($expected, $sig);

            $payload = json_decode($request->body(), true);

            return $payload['channel_type'] === 'webhook'
                && $payload['subject'] === 'Hello'
                && $payload['body'] === 'world'
                && $payload['variables']['incident_type'] === 'speeding'
                && $payload['recipient']['address'] === 'https://example.com/hook'
                && $payload['event_key'] === $key;
        });
    }

    public function test_returns_failure_on_4xx(): void
    {
        Http::fake([
            'https://example.com/hook' => Http::response('bad request', 400),
        ]);

        $team = Team::factory()->create();
        $channel = NotificationChannel::factory()->create([
            'team_id' => $team->id,
            'channel_type' => ChannelType::Webhook,
            'provider' => 'webhook',
            'config_json' => [
                'endpoint_url' => 'https://example.com/hook',
                'secret' => 's',
            ],
        ]);

        $result = app(WebhookNotificationDriver::class)->send($this->rendered(), $channel);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('400', $result->errorMessage);
        $this->assertSame(400, $result->response['status_code']);
    }

    public function test_returns_failure_on_5xx(): void
    {
        Http::fake([
            'https://example.com/hook' => Http::response('boom', 503),
        ]);

        $team = Team::factory()->create();
        $channel = NotificationChannel::factory()->create([
            'team_id' => $team->id,
            'channel_type' => ChannelType::Webhook,
            'provider' => 'webhook',
            'config_json' => [
                'endpoint_url' => 'https://example.com/hook',
                'secret' => 's',
            ],
        ]);

        $result = app(WebhookNotificationDriver::class)->send($this->rendered(), $channel);

        $this->assertFalse($result->success);
        $this->assertSame(503, $result->response['status_code']);
    }

    public function test_failure_when_endpoint_url_missing(): void
    {
        Http::fake();

        $team = Team::factory()->create();
        $channel = NotificationChannel::factory()->create([
            'team_id' => $team->id,
            'channel_type' => ChannelType::Webhook,
            'provider' => 'webhook',
            'config_json' => [
                'secret' => 's',
            ],
        ]);

        $result = app(WebhookNotificationDriver::class)->send($this->rendered(), $channel);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('endpoint_url missing', $result->errorMessage);
        Http::assertNothingSent();
    }

    public function test_failure_when_secret_missing(): void
    {
        Http::fake();

        $team = Team::factory()->create();
        $channel = NotificationChannel::factory()->create([
            'team_id' => $team->id,
            'channel_type' => ChannelType::Webhook,
            'provider' => 'webhook',
            'config_json' => [
                'endpoint_url' => 'https://example.com/hook',
            ],
        ]);

        $result = app(WebhookNotificationDriver::class)->send($this->rendered(), $channel);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('secret missing', $result->errorMessage);
        Http::assertNothingSent();
    }

    public function test_extra_headers_are_forwarded(): void
    {
        Http::fake([
            'https://example.com/hook' => Http::response('', 200),
        ]);

        $team = Team::factory()->create();
        $channel = NotificationChannel::factory()->create([
            'team_id' => $team->id,
            'channel_type' => ChannelType::Webhook,
            'provider' => 'webhook',
            'config_json' => [
                'endpoint_url' => 'https://example.com/hook',
                'secret' => 's',
                'extra_headers' => [
                    'X-Tenant-Id' => (string) $team->id,
                ],
            ],
        ]);

        $result = app(WebhookNotificationDriver::class)->send($this->rendered(), $channel);

        $this->assertTrue($result->success);
        Http::assertSent(fn (Request $r) => ($r->header('X-Tenant-Id')[0] ?? null) === (string) $team->id);
    }
}
