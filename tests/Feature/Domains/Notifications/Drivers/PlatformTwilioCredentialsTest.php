<?php

namespace Tests\Feature\Domains\Notifications\Drivers;

use App\Domains\Notifications\Channels\SmsNotificationDriver;
use App\Domains\Notifications\Channels\TwilioClientFactory;
use App\Domains\Notifications\Channels\TwilioMessenger;
use App\Domains\Notifications\Channels\TwilioVoiceCaller;
use App\Domains\Notifications\Channels\VoiceNotificationDriver;
use App\Domains\Notifications\Channels\WhatsappNotificationDriver;
use App\Domains\Notifications\Data\RenderedNotification;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\MessagingResourceType;
use App\Domains\Notifications\Models\NotificationChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;
use Twilio\Exceptions\RestException;

/**
 * SAM operates messaging with ONE platform Twilio account (env TWILIO_*).
 * Channel `config_json` may only override non-secret values (sender,
 * WhatsApp template, ring timeout); per-channel/tenant credentials of the
 * legacy approach are ignored. Twilio drivers report "accepted", not
 * "delivered", and ask Twilio for status callbacks when the URL is public.
 */
class PlatformTwilioCredentialsTest extends TestCase
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
            'status_callback_url' => 'https://sam.example.com/api/webhooks/twilio/status',
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

    /**
     * @param  array<string, mixed>|null  $config
     */
    private function channel(ChannelType $type, ?array $config = null): NotificationChannel
    {
        return NotificationChannel::factory()->create([
            'channel_type' => $type,
            'provider' => 'twilio',
            'config_json' => $config,
        ]);
    }

    private function bindMessenger(): MockInterface
    {
        $mock = Mockery::mock(TwilioMessenger::class);
        $this->app->instance(TwilioMessenger::class, $mock);

        return $mock;
    }

    public function test_client_is_built_only_from_platform_credentials(): void
    {
        $client = app(TwilioClientFactory::class)->make();

        $this->assertSame('AC_PLATFORM', $client->getUsername());
        $this->assertSame('tok_platform', $client->getPassword());
    }

    public function test_sms_uses_platform_sender_and_requests_status_callback(): void
    {
        $messenger = $this->bindMessenger();
        $messenger->shouldReceive('createMessage')
            ->once()
            ->withArgs(function (string $to, array $params) {
                $this->assertSame('+526641234567', $to);
                $this->assertSame('+15550001111', $params['from']);
                $this->assertSame('https://sam.example.com/api/webhooks/twilio/status', $params['statusCallback']);

                return true;
            })
            ->andReturn((object) ['sid' => 'SM_OK', 'status' => 'queued', 'numSegments' => '1']);

        $result = app(SmsNotificationDriver::class)->send($this->rendered(ChannelType::Sms), $this->channel(ChannelType::Sms));

        $this->assertTrue($result->success);
        $this->assertTrue($result->awaitingProviderConfirmation);
        $this->assertSame(MessagingResourceType::Message, $result->resourceType);
        $this->assertSame('queued', $result->providerStatus);
        $this->assertSame(1, $result->segments);
    }

    public function test_status_callback_is_not_sent_when_the_url_is_not_public(): void
    {
        config()->set('services.twilio.status_callback_url', null);
        config()->set('app.url', 'http://localhost');

        $messenger = $this->bindMessenger();
        $messenger->shouldReceive('createMessage')
            ->once()
            ->withArgs(function (string $to, array $params) {
                $this->assertArrayNotHasKey('statusCallback', $params);

                return true;
            })
            ->andReturn((object) ['sid' => 'SM_OK', 'status' => 'queued']);

        $result = app(SmsNotificationDriver::class)->send($this->rendered(ChannelType::Sms), $this->channel(ChannelType::Sms));

        $this->assertTrue($result->success);
    }

    public function test_whatsapp_uses_platform_sender(): void
    {
        $messenger = $this->bindMessenger();
        $messenger->shouldReceive('createMessage')
            ->once()
            ->withArgs(function (string $to, array $params) {
                $this->assertSame('whatsapp:+526641234567', $to);
                $this->assertSame('whatsapp:+15550002222', $params['from']);
                $this->assertArrayHasKey('statusCallback', $params);

                return true;
            })
            ->andReturn((object) ['sid' => 'SM_WA', 'status' => 'queued']);

        $result = app(WhatsappNotificationDriver::class)->send($this->rendered(ChannelType::Whatsapp), $this->channel(ChannelType::Whatsapp));

        $this->assertTrue($result->awaitingProviderConfirmation);
    }

    public function test_voice_call_requests_answered_and_completed_progress_events(): void
    {
        $caller = Mockery::mock(TwilioVoiceCaller::class);
        $this->app->instance(TwilioVoiceCaller::class, $caller);

        $caller->shouldReceive('createCall')
            ->once()
            ->withArgs(function (string $to, string $from, array $params) {
                $this->assertSame('+15550003333', $from);
                $this->assertSame(VoiceNotificationDriver::STATUS_CALLBACK_EVENTS, $params['statusCallbackEvent']);
                $this->assertSame(['initiated', 'ringing', 'answered', 'completed'], $params['statusCallbackEvent']);
                $this->assertArrayHasKey('statusCallback', $params);
                $this->assertSame(25, $params['timeout']);

                return true;
            })
            ->andReturn((object) ['sid' => 'CA_OK', 'status' => 'queued']);

        $result = app(VoiceNotificationDriver::class)->send($this->rendered(ChannelType::Voice), $this->channel(ChannelType::Voice));

        $this->assertTrue($result->awaitingProviderConfirmation);
        $this->assertSame(MessagingResourceType::Call, $result->resourceType);
    }

    public function test_channel_may_override_the_sender(): void
    {
        $messenger = $this->bindMessenger();
        $messenger->shouldReceive('createMessage')
            ->once()
            ->withArgs(function (string $to, array $params) {
                $this->assertSame('+15559998888', $params['from']);

                return true;
            })
            ->andReturn((object) ['sid' => 'SM_OK', 'status' => 'queued']);

        $result = app(SmsNotificationDriver::class)->send(
            $this->rendered(ChannelType::Sms),
            $this->channel(ChannelType::Sms, ['from' => '+15559998888']),
        );

        $this->assertTrue($result->success);
    }

    public function test_legacy_channel_credentials_are_ignored(): void
    {
        config()->set('services.twilio.account_sid', null);
        config()->set('services.twilio.auth_token', null);

        $messenger = $this->bindMessenger();
        $messenger->shouldNotReceive('createMessage');

        $result = app(SmsNotificationDriver::class)->send(
            $this->rendered(ChannelType::Sms),
            $this->channel(ChannelType::Sms, [
                'twilio_account_sid' => 'AC_TENANT',
                'twilio_auth_token' => 'tok_tenant',
                'account_sid' => 'AC_LEGACY',
                'auth_token' => 'tok_legacy',
            ]),
        );

        $this->assertFalse($result->success);
        $this->assertStringContainsString('credentials missing', (string) $result->errorMessage);
        $this->assertTrue($result->permanent);
    }

    public function test_permanent_twilio_error_is_flagged_with_its_code(): void
    {
        $messenger = $this->bindMessenger();
        $messenger->shouldReceive('createMessage')
            ->andThrow(new RestException('The To number is not a valid phone number.', 21211, 400));

        $result = app(SmsNotificationDriver::class)->send($this->rendered(ChannelType::Sms), $this->channel(ChannelType::Sms));

        $this->assertFalse($result->success);
        $this->assertTrue($result->permanent);
        $this->assertSame('21211', $result->providerErrorCode);
    }

    public function test_transient_twilio_error_is_not_permanent(): void
    {
        $messenger = $this->bindMessenger();
        $messenger->shouldReceive('createMessage')
            ->andThrow(new RestException('Too many requests', 20429, 429));

        $result = app(SmsNotificationDriver::class)->send($this->rendered(ChannelType::Sms), $this->channel(ChannelType::Sms));

        $this->assertFalse($result->success);
        $this->assertFalse($result->permanent);
    }

    public function test_sms_fails_when_no_platform_sender_is_configured(): void
    {
        config()->set('services.twilio.sms_from', null);

        $result = app(SmsNotificationDriver::class)->send($this->rendered(ChannelType::Sms), $this->channel(ChannelType::Sms));

        $this->assertFalse($result->success);
        $this->assertTrue($result->permanent);
    }
}
