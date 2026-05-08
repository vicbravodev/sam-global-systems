<?php

namespace Tests\Feature\Domains\Notifications\Drivers;

use App\Domains\Notifications\Channels\SmsNotificationDriver;
use App\Domains\Notifications\Channels\TwilioMessenger;
use App\Domains\Notifications\Data\RenderedNotification;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;
use Twilio\Exceptions\TwilioException;

class SmsNotificationDriverTest extends TestCase
{
    use RefreshDatabase;

    private function rendered(string $body = 'short body'): RenderedNotification
    {
        return new RenderedNotification(
            channelType: ChannelType::Sms,
            address: '+34666123456',
            subject: null,
            body: $body,
            variables: [],
        );
    }

    private function channel(Team $team, array $overrides = []): NotificationChannel
    {
        return NotificationChannel::factory()->create([
            'team_id' => $team->id,
            'channel_type' => ChannelType::Sms,
            'provider' => 'twilio',
            'config_json' => array_merge([
                'twilio_account_sid' => 'AC123',
                'twilio_auth_token' => 'tok-456',
                'from' => '+14155238886',
            ], $overrides),
        ]);
    }

    private function bindMessenger(): MockInterface
    {
        $mock = Mockery::mock(TwilioMessenger::class);
        $this->app->instance(TwilioMessenger::class, $mock);

        return $mock;
    }

    public function test_sends_short_body_unmodified(): void
    {
        $messenger = $this->bindMessenger();
        $messenger->shouldReceive('createMessage')
            ->once()
            ->withArgs(function (array $config, string $to, array $params) {
                $this->assertSame('+34666123456', $to);
                $this->assertSame('+14155238886', $params['from']);
                $this->assertSame('short body', $params['body']);

                return true;
            })
            ->andReturn((object) ['sid' => 'SM_OK', 'status' => 'queued']);

        $team = Team::factory()->create();
        $channel = $this->channel($team);

        $result = app(SmsNotificationDriver::class)->send($this->rendered(), $channel);

        $this->assertTrue($result->success);
        $this->assertSame('SM_OK', $result->providerMessageId);
        $this->assertSame(10, $result->response['truncated_body_length']);
    }

    public function test_truncates_long_body_with_suffix_under_160_chars(): void
    {
        $longBody = str_repeat('A', 200);

        $messenger = $this->bindMessenger();
        $messenger->shouldReceive('createMessage')
            ->once()
            ->withArgs(function (array $config, string $to, array $params) {
                $this->assertLessThanOrEqual(SmsNotificationDriver::MAX_LENGTH, mb_strlen($params['body']));
                $this->assertStringEndsWith(SmsNotificationDriver::SUFFIX, $params['body']);

                return true;
            })
            ->andReturn((object) ['sid' => 'SM_T', 'status' => 'queued']);

        $team = Team::factory()->create();
        $channel = $this->channel($team);

        $result = app(SmsNotificationDriver::class)->send($this->rendered($longBody), $channel);

        $this->assertTrue($result->success);
        $this->assertLessThanOrEqual(SmsNotificationDriver::MAX_LENGTH, $result->response['truncated_body_length']);
    }

    public function test_uses_messaging_service_sid_when_from_starts_with_mg(): void
    {
        $messenger = $this->bindMessenger();
        $messenger->shouldReceive('createMessage')
            ->once()
            ->withArgs(function (array $config, string $to, array $params) {
                $this->assertSame('MG1234567890abcdef', $params['messagingServiceSid']);
                $this->assertArrayNotHasKey('from', $params);

                return true;
            })
            ->andReturn((object) ['sid' => 'SM_OK', 'status' => 'queued']);

        $team = Team::factory()->create();
        $channel = $this->channel($team, ['from' => 'MG1234567890abcdef']);

        $result = app(SmsNotificationDriver::class)->send($this->rendered(), $channel);

        $this->assertTrue($result->success);
    }

    public function test_handles_twilio_exception_as_failure(): void
    {
        $messenger = $this->bindMessenger();
        $messenger->shouldReceive('createMessage')->andThrow(new TwilioException('rate limited'));

        $team = Team::factory()->create();
        $channel = $this->channel($team);

        $result = app(SmsNotificationDriver::class)->send($this->rendered(), $channel);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('rate limited', $result->errorMessage);
        $this->assertSame('sms', $result->response['driver']);
    }

    public function test_failure_when_from_missing(): void
    {
        $messenger = $this->bindMessenger();
        $messenger->shouldNotReceive('createMessage');

        $team = Team::factory()->create();
        $channel = $this->channel($team, ['from' => '']);

        $result = app(SmsNotificationDriver::class)->send($this->rendered(), $channel);

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
            'channel_type' => ChannelType::Sms,
            'provider' => 'twilio',
            'config_json' => ['from' => '+14155238886'],
        ]);

        $result = app(SmsNotificationDriver::class)->send($this->rendered(), $channel);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('credentials missing', $result->errorMessage);
    }
}
