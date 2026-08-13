<?php

namespace Tests\Feature\Domains\Notifications\Drivers;

use App\Domains\Notifications\Channels\SmsNotificationDriver;
use App\Domains\Notifications\Channels\TwilioMessenger;
use App\Domains\Notifications\Channels\TwilioVoiceCaller;
use App\Domains\Notifications\Channels\VoiceNotificationDriver;
use App\Domains\Notifications\Channels\WhatsappNotificationDriver;
use App\Domains\Notifications\Data\RenderedNotification;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * SAM operates messaging centrally: platform Twilio credentials live in
 * config/services.php (env), and channel rows without config_json must fall
 * back to them so tenants never configure messaging themselves.
 */
class PlatformTwilioFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.twilio', [
            'account_sid' => 'AC_PLATFORM',
            'auth_token' => 'tok_platform',
            'sms_from' => '+15550001111',
            'whatsapp_from' => '+15550002222',
            'voice_from' => '+15550003333',
        ]);
    }

    private function rendered(ChannelType $type): RenderedNotification
    {
        return new RenderedNotification(
            channelType: $type,
            address: '+526641234567',
            subject: null,
            body: 'aviso de incidente',
            variables: [],
        );
    }

    private function bareChannel(ChannelType $type): NotificationChannel
    {
        return NotificationChannel::factory()->create([
            'team_id' => Team::factory()->create()->id,
            'channel_type' => $type,
            'provider' => 'twilio',
            'config_json' => null,
        ]);
    }

    private function bindMessenger(): MockInterface
    {
        $mock = Mockery::mock(TwilioMessenger::class);
        $this->app->instance(TwilioMessenger::class, $mock);

        return $mock;
    }

    public function test_sms_driver_falls_back_to_platform_credentials(): void
    {
        $messenger = $this->bindMessenger();
        $messenger->shouldReceive('createMessage')
            ->once()
            ->withArgs(function (array $config, string $to, array $params) {
                $this->assertSame('AC_PLATFORM', $config['twilio_account_sid']);
                $this->assertSame('tok_platform', $config['twilio_auth_token']);
                $this->assertSame('+15550001111', $params['from']);

                return true;
            })
            ->andReturn((object) ['sid' => 'SM_OK', 'status' => 'queued']);

        $result = app(SmsNotificationDriver::class)->send(
            $this->rendered(ChannelType::Sms),
            $this->bareChannel(ChannelType::Sms),
        );

        $this->assertTrue($result->success);
    }

    public function test_whatsapp_driver_falls_back_to_platform_credentials(): void
    {
        $messenger = $this->bindMessenger();
        $messenger->shouldReceive('createMessage')
            ->once()
            ->withArgs(function (array $config, string $to, array $params) {
                $this->assertSame('AC_PLATFORM', $config['twilio_account_sid']);
                $this->assertSame('whatsapp:+15550002222', $params['from']);

                return true;
            })
            ->andReturn((object) ['sid' => 'WA_OK', 'status' => 'queued']);

        $result = app(WhatsappNotificationDriver::class)->send(
            $this->rendered(ChannelType::Whatsapp),
            $this->bareChannel(ChannelType::Whatsapp),
        );

        $this->assertTrue($result->success);
    }

    public function test_voice_driver_falls_back_to_platform_credentials(): void
    {
        $caller = Mockery::mock(TwilioVoiceCaller::class);
        $this->app->instance(TwilioVoiceCaller::class, $caller);

        $caller->shouldReceive('createCall')
            ->once()
            ->withArgs(function (array $config, string $to, string $from) {
                $this->assertSame('AC_PLATFORM', $config['twilio_account_sid']);
                $this->assertSame('+15550003333', $from);

                return true;
            })
            ->andReturn((object) ['sid' => 'CA_OK', 'status' => 'queued']);

        $result = app(VoiceNotificationDriver::class)->send(
            $this->rendered(ChannelType::Voice),
            $this->bareChannel(ChannelType::Voice),
        );

        $this->assertTrue($result->success);
    }

    public function test_channel_config_wins_over_platform_credentials(): void
    {
        $messenger = $this->bindMessenger();
        $messenger->shouldReceive('createMessage')
            ->once()
            ->withArgs(function (array $config, string $to, array $params) {
                $this->assertSame('AC_TENANT', $config['twilio_account_sid']);
                $this->assertSame('tok_tenant', $config['twilio_auth_token']);
                $this->assertSame('+15559998888', $params['from']);

                return true;
            })
            ->andReturn((object) ['sid' => 'SM_OK', 'status' => 'queued']);

        $channel = NotificationChannel::factory()->create([
            'team_id' => Team::factory()->create()->id,
            'channel_type' => ChannelType::Sms,
            'provider' => 'twilio',
            'config_json' => [
                'twilio_account_sid' => 'AC_TENANT',
                'twilio_auth_token' => 'tok_tenant',
                'from' => '+15559998888',
            ],
        ]);

        $result = app(SmsNotificationDriver::class)->send($this->rendered(ChannelType::Sms), $channel);

        $this->assertTrue($result->success);
    }

    public function test_legacy_alias_keys_are_not_clobbered_by_platform_defaults(): void
    {
        $messenger = $this->bindMessenger();
        $messenger->shouldReceive('createMessage')
            ->once()
            ->withArgs(function (array $config) {
                // The channel used the legacy `account_sid` alias: the platform
                // default must not shadow it under `twilio_account_sid`.
                $this->assertSame('AC_LEGACY', $config['twilio_account_sid'] ?? $config['account_sid']);

                return true;
            })
            ->andReturn((object) ['sid' => 'SM_OK', 'status' => 'queued']);

        $channel = NotificationChannel::factory()->create([
            'team_id' => Team::factory()->create()->id,
            'channel_type' => ChannelType::Sms,
            'provider' => 'twilio',
            'config_json' => [
                'account_sid' => 'AC_LEGACY',
                'auth_token' => 'tok_legacy',
                'from' => '+15557776666',
            ],
        ]);

        $result = app(SmsNotificationDriver::class)->send($this->rendered(ChannelType::Sms), $channel);

        $this->assertTrue($result->success);
    }

    public function test_sms_fails_when_neither_channel_nor_platform_config_exists(): void
    {
        config()->set('services.twilio', []);

        $result = app(SmsNotificationDriver::class)->send(
            $this->rendered(ChannelType::Sms),
            $this->bareChannel(ChannelType::Sms),
        );

        $this->assertFalse($result->success);
    }
}
