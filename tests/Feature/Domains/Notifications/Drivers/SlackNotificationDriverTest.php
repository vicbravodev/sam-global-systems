<?php

namespace Tests\Feature\Domains\Notifications\Drivers;

use App\Domains\Notifications\Channels\SlackNotificationDriver;
use App\Domains\Notifications\Data\RenderedNotification;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SlackNotificationDriverTest extends TestCase
{
    use RefreshDatabase;

    private function rendered(?string $priority = null, ?string $incidentId = null, ?string $body = 'world'): RenderedNotification
    {
        $variables = [];
        if ($priority !== null) {
            $variables['priority'] = $priority;
        }
        if ($incidentId !== null) {
            $variables['incident_id'] = $incidentId;
        }

        return new RenderedNotification(
            channelType: ChannelType::Slack,
            address: '#ops',
            subject: 'Hello',
            body: $body,
            variables: $variables,
            recipientName: 'Ops',
        );
    }

    private function channel(Team $team, array $config): NotificationChannel
    {
        return NotificationChannel::factory()->create([
            'team_id' => $team->id,
            'channel_type' => ChannelType::Slack,
            'provider' => 'slack',
            'config_json' => $config,
        ]);
    }

    public function test_posts_flat_text_for_normal_priority(): void
    {
        Http::fake([
            'https://hooks.slack.com/*' => Http::response('ok', 200),
        ]);

        $team = Team::factory()->create();
        $channel = $this->channel($team, [
            'slack_webhook_url' => 'https://hooks.slack.com/services/T0/B0/abc',
        ]);

        $result = app(SlackNotificationDriver::class)->send($this->rendered(), $channel);

        $this->assertTrue($result->success);
        $this->assertSame(200, $result->response['status_code']);

        Http::assertSent(function (Request $request) {
            $payload = json_decode($request->body(), true);

            return $request->url() === 'https://hooks.slack.com/services/T0/B0/abc'
                && $payload['text'] === 'Hello — world'
                && ! array_key_exists('blocks', $payload);
        });
    }

    public function test_uses_blocks_with_action_button_for_critical_with_incident(): void
    {
        Http::fake([
            'https://hooks.slack.com/*' => Http::response('ok', 200),
        ]);

        $team = Team::factory()->create();
        $channel = $this->channel($team, [
            'slack_webhook_url' => 'https://hooks.slack.com/services/T0/B0/abc',
            'incident_url_template' => 'https://app.example.com/incidents/%s',
        ]);

        $result = app(SlackNotificationDriver::class)->send(
            $this->rendered(priority: NotificationPriority::Critical->value, incidentId: '42'),
            $channel,
        );

        $this->assertTrue($result->success);

        Http::assertSent(function (Request $request) {
            $payload = json_decode($request->body(), true);

            $this->assertCount(3, $payload['blocks']);
            $this->assertSame('header', $payload['blocks'][0]['type']);
            $this->assertSame('section', $payload['blocks'][1]['type']);
            $this->assertSame('actions', $payload['blocks'][2]['type']);
            $this->assertSame(
                'https://app.example.com/incidents/42',
                $payload['blocks'][2]['elements'][0]['url'],
            );

            return true;
        });
    }

    public function test_critical_without_incident_url_template_skips_button(): void
    {
        Http::fake(['https://hooks.slack.com/*' => Http::response('ok', 200)]);

        $team = Team::factory()->create();
        $channel = $this->channel($team, [
            'slack_webhook_url' => 'https://hooks.slack.com/services/T0/B0/abc',
        ]);

        $result = app(SlackNotificationDriver::class)->send(
            $this->rendered(priority: NotificationPriority::Critical->value, incidentId: '42'),
            $channel,
        );

        $this->assertTrue($result->success);

        Http::assertSent(function (Request $request) {
            $payload = json_decode($request->body(), true);

            $this->assertCount(2, $payload['blocks']);
            foreach ($payload['blocks'] as $block) {
                $this->assertNotSame('actions', $block['type']);
            }

            return true;
        });
    }

    public function test_escapes_markdown_special_characters(): void
    {
        Http::fake(['https://hooks.slack.com/*' => Http::response('ok', 200)]);

        $team = Team::factory()->create();
        $channel = $this->channel($team, [
            'slack_webhook_url' => 'https://hooks.slack.com/services/T0/B0/abc',
        ]);

        $result = app(SlackNotificationDriver::class)->send(
            $this->rendered(body: 'use *bold* & <stuff> _italic_ ~strike~ `code`'),
            $channel,
        );

        $this->assertTrue($result->success);

        Http::assertSent(function (Request $request) {
            $payload = json_decode($request->body(), true);

            $this->assertStringContainsString('&amp;', $payload['text']);
            $this->assertStringContainsString('&lt;stuff&gt;', $payload['text']);
            $this->assertStringContainsString('\\*bold\\*', $payload['text']);
            $this->assertStringContainsString('\\_italic\\_', $payload['text']);
            $this->assertStringContainsString('\\~strike\\~', $payload['text']);
            $this->assertStringContainsString('\\`code\\`', $payload['text']);

            return true;
        });
    }

    public function test_failure_when_webhook_url_missing(): void
    {
        Http::fake();

        $team = Team::factory()->create();
        $channel = $this->channel($team, []);

        $result = app(SlackNotificationDriver::class)->send($this->rendered(), $channel);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('slack_webhook_url missing', $result->errorMessage);
        Http::assertNothingSent();
    }

    public function test_returns_failure_on_4xx_with_error_body(): void
    {
        Http::fake([
            'https://hooks.slack.com/*' => Http::response('invalid_payload', 400),
        ]);

        $team = Team::factory()->create();
        $channel = $this->channel($team, [
            'slack_webhook_url' => 'https://hooks.slack.com/services/T0/B0/abc',
        ]);

        $result = app(SlackNotificationDriver::class)->send($this->rendered(), $channel);

        $this->assertFalse($result->success);
        $this->assertSame(400, $result->response['status_code']);
        $this->assertStringContainsString('invalid_payload', $result->errorMessage);
    }

    public function test_returns_failure_on_2xx_with_non_ok_body(): void
    {
        Http::fake([
            'https://hooks.slack.com/*' => Http::response('channel_not_found', 200),
        ]);

        $team = Team::factory()->create();
        $channel = $this->channel($team, [
            'slack_webhook_url' => 'https://hooks.slack.com/services/T0/B0/abc',
        ]);

        $result = app(SlackNotificationDriver::class)->send($this->rendered(), $channel);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('channel_not_found', $result->errorMessage);
    }
}
