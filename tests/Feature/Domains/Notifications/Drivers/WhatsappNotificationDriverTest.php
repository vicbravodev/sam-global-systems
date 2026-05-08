<?php

namespace Tests\Feature\Domains\Notifications\Drivers;

use App\Domains\Notifications\Channels\TwilioMessenger;
use App\Domains\Notifications\Channels\WhatsappNotificationDriver;
use App\Domains\Notifications\Data\RenderedNotification;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;
use Twilio\Exceptions\TwilioException;

class WhatsappNotificationDriverTest extends TestCase
{
    use RefreshDatabase;

    private function rendered(array $variables = [], string $body = 'world'): RenderedNotification
    {
        return new RenderedNotification(
            channelType: ChannelType::Whatsapp,
            address: '+34666123456',
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
            'channel_type' => ChannelType::Whatsapp,
            'provider' => 'twilio',
            'config_json' => array_merge([
                'twilio_account_sid' => 'AC123',
                'twilio_auth_token' => 'tok-456',
                'from' => 'whatsapp:+14155238886',
            ], $config),
        ]);
    }

    private function bindMessenger(): MockInterface
    {
        $mock = Mockery::mock(TwilioMessenger::class);
        $this->app->instance(TwilioMessenger::class, $mock);

        return $mock;
    }

    public function test_sends_plain_message_when_no_template_configured(): void
    {
        $messenger = $this->bindMessenger();
        $messenger->shouldReceive('createMessage')
            ->once()
            ->withArgs(function (array $config, string $to, array $params) {
                $this->assertSame('whatsapp:+34666123456', $to);
                $this->assertSame('whatsapp:+14155238886', $params['from']);
                $this->assertSame('world', $params['body']);
                $this->assertArrayNotHasKey('contentSid', $params);
                $this->assertSame('AC123', $config['twilio_account_sid']);

                return true;
            })
            ->andReturn((object) ['sid' => 'SM_OK', 'status' => 'queued']);

        $team = Team::factory()->create();
        $channel = $this->channel($team, []);

        $result = app(WhatsappNotificationDriver::class)->send($this->rendered(), $channel);

        $this->assertTrue($result->success);
        $this->assertSame('SM_OK', $result->providerMessageId);
        $this->assertSame('queued', $result->response['status']);
    }

    public function test_uses_content_template_when_configured(): void
    {
        $messenger = $this->bindMessenger();
        $messenger->shouldReceive('createMessage')
            ->once()
            ->withArgs(function (array $config, string $to, array $params) {
                $this->assertSame('whatsapp:+34666123456', $to);
                $this->assertSame('HX_TEMPLATE', $params['contentSid']);
                $this->assertArrayNotHasKey('body', $params);
                $vars = json_decode($params['contentVariables'], true);
                $this->assertSame('speeding', $vars['incident_type']);
                $this->assertSame('Truck-12', $vars['asset_name']);

                return true;
            })
            ->andReturn((object) ['sid' => 'SM_T', 'status' => 'queued']);

        $team = Team::factory()->create();
        $channel = $this->channel($team, ['content_sid' => 'HX_TEMPLATE']);

        $result = app(WhatsappNotificationDriver::class)->send(
            $this->rendered([
                'incident_type' => 'speeding',
                'asset_name' => 'Truck-12',
            ]),
            $channel,
        );

        $this->assertTrue($result->success);
        $this->assertSame('SM_T', $result->providerMessageId);
    }

    public function test_handles_twilio_exception_as_failure(): void
    {
        $messenger = $this->bindMessenger();
        $messenger->shouldReceive('createMessage')->andThrow(new TwilioException('queue full'));

        $team = Team::factory()->create();
        $channel = $this->channel($team, []);

        $result = app(WhatsappNotificationDriver::class)->send($this->rendered(), $channel);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('queue full', $result->errorMessage);
        $this->assertSame('whatsapp', $result->response['driver']);
    }

    public function test_failure_when_from_missing(): void
    {
        $messenger = $this->bindMessenger();
        $messenger->shouldNotReceive('createMessage');

        $team = Team::factory()->create();
        $channel = $this->channel($team, ['from' => '']);

        $result = app(WhatsappNotificationDriver::class)->send($this->rendered(), $channel);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('`from` missing', $result->errorMessage);
    }

    public function test_failure_when_credentials_missing(): void
    {
        $messenger = $this->bindMessenger();
        $messenger->shouldNotReceive('createMessage');

        $team = Team::factory()->create();
        $channel = NotificationChannel::factory()->create([
            'team_id' => $team->id,
            'channel_type' => ChannelType::Whatsapp,
            'provider' => 'twilio',
            'config_json' => [
                'from' => 'whatsapp:+14155238886',
            ],
        ]);

        $result = app(WhatsappNotificationDriver::class)->send($this->rendered(), $channel);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('credentials missing', $result->errorMessage);
    }

    public function test_addresses_already_prefixed_are_preserved(): void
    {
        $messenger = $this->bindMessenger();
        $messenger->shouldReceive('createMessage')
            ->once()
            ->withArgs(function (array $config, string $to, array $params) {
                $this->assertSame('whatsapp:+34666123456', $to);

                return true;
            })
            ->andReturn((object) ['sid' => 'SM_OK', 'status' => 'queued']);

        $team = Team::factory()->create();
        $channel = $this->channel($team, []);

        $rendered = new RenderedNotification(
            channelType: ChannelType::Whatsapp,
            address: 'whatsapp:+34666123456',
            subject: 'Hello',
            body: 'world',
            variables: [],
        );

        $result = app(WhatsappNotificationDriver::class)->send($rendered, $channel);

        $this->assertTrue($result->success);
    }
}
