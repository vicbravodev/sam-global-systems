<?php

namespace Tests\Feature\Domains\Notifications;

use App\Domains\Notifications\Actions\RenderNotificationContent;
use App\Domains\Notifications\Channels\TwilioVoiceCaller;
use App\Domains\Notifications\Channels\VoiceNotificationDriver;
use App\Domains\Notifications\Data\RenderedNotification;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\DeliveryStatus;
use App\Domains\Notifications\Jobs\FallbackNotificationChannelJob;
use App\Domains\Notifications\Jobs\RetryNotificationDeliveryJob;
use App\Domains\Notifications\Models\MessagingCharge;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Models\NotificationRecipient;
use App\Domains\Notifications\Models\NotificationTemplate;
use App\Domains\Notifications\Support\TwilioErrorCatalog;
use App\Domains\Notifications\Support\TwilioSpeech;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\NotificationMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;
use Twilio\Security\RequestValidator;

/**
 * Las llamadas de SAM suenan en cuanto contestan, con una voz natural y
 * pausada, y leen un texto pensado para oírse. La detección de contestadora
 * corre en paralelo: antes Twilio dejaba hasta 30 s de silencio mientras
 * decidía y la gente colgaba.
 */
class NaturalVoiceCallsTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

    private const PHONE = '+5215512345678';

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(NotificationMeterSeeder::class);
        $this->team = User::factory()->create()->currentTeam;

        config()->set('services.twilio.account_sid', 'AC123');
        config()->set('services.twilio.auth_token', 'tok-456');
    }

    /**
     * @return array<string, mixed>
     */
    private function placeCall(RenderedNotification $notification): array
    {
        $captured = new \stdClass;
        $caller = Mockery::mock(TwilioVoiceCaller::class);
        $caller->shouldReceive('createCall')->once()->andReturnUsing(function (string $_to, string $_from, array $params) use ($captured) {
            $captured->params = $params;

            return (object) ['sid' => 'CA'.str_repeat('a', 32), 'status' => 'queued'];
        });

        $voice = NotificationChannel::factory()->voice()->create(['config_json' => ['from' => '+15005550006']]);

        (new VoiceNotificationDriver($caller))->send($notification, $voice);

        return $captured->params;
    }

    public function test_answering_machine_detection_runs_in_parallel_so_the_message_plays_at_once(): void
    {
        config()->set('services.twilio.status_callback_url', 'https://sam.example.com/api/webhooks/twilio/status');

        $params = $this->placeCall(new RenderedNotification(ChannelType::Voice, self::PHONE, null, 'Hola, te llama SAM.'));

        $this->assertSame('Enable', $params['machineDetection']);
        $this->assertSame('true', $params['asyncAmd']);
        $this->assertSame('https://sam.example.com/api/webhooks/twilio/status', $params['asyncAmdStatusCallback']);
        $this->assertSame('POST', $params['asyncAmdStatusCallbackMethod']);
        // Lo primero del TwiML es la voz: nada que esperar antes del mensaje.
        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?><Response><Say ', $params['twiml']);
    }

    public function test_without_a_public_callback_detection_still_runs_in_parallel(): void
    {
        $params = $this->placeCall(new RenderedNotification(ChannelType::Voice, self::PHONE, null, 'Hola, te llama SAM.'));

        // El conciliador lee el veredicto al consultar la llamada.
        $this->assertSame('true', $params['asyncAmd']);
        $this->assertArrayNotHasKey('asyncAmdStatusCallback', $params);
        $this->assertArrayNotHasKey('statusCallback', $params);
    }

    public function test_calls_use_the_configured_natural_voice_at_a_calm_pace(): void
    {
        $params = $this->placeCall(new RenderedNotification(ChannelType::Voice, self::PHONE, null, 'Hola, te llama SAM. Hay una alerta.'));

        $this->assertStringContainsString('<Say voice="Polly.Mia-Neural" language="es-MX">', $params['twiml']);
        $this->assertStringContainsString('<prosody rate="90%">Hola, te llama SAM.</prosody><break time="400ms"/><prosody rate="90%">Hay una alerta.</prosody>', $params['twiml']);

        config()->set('services.twilio.tts_voice', 'Polly.Mía-Generative');
        config()->set('services.twilio.tts_rate', '85%');

        $params = $this->placeCall(new RenderedNotification(ChannelType::Voice, self::PHONE, null, 'Hola, te llama SAM.'));

        $this->assertStringContainsString('<Say voice="Polly.Mía-Generative" language="es-MX"><prosody rate="85%">Hola, te llama SAM.</prosody>', $params['twiml']);
    }

    public function test_speech_falls_back_to_safe_defaults_and_skips_ssml_for_basic_voices(): void
    {
        config()->set('services.twilio.tts_voice', '');
        config()->set('services.twilio.tts_rate', 'rapidísimo');

        $this->assertSame(TwilioSpeech::DEFAULT_VOICE, TwilioSpeech::voice());
        $this->assertSame(TwilioSpeech::DEFAULT_RATE, TwilioSpeech::rate());

        config()->set('services.twilio.tts_rate', '500%');
        $this->assertSame(TwilioSpeech::DEFAULT_RATE, TwilioSpeech::rate());

        // Una voz básica no entiende SSML: texto plano, escapado.
        config()->set('services.twilio.tts_voice', 'Polly.Woman');
        $this->assertSame(
            '<Say voice="Polly.Woman" language="es-MX">Uno &amp; dos. Tres.</Say>',
            TwilioSpeech::say(['Uno & dos.', 'Tres.']),
        );
    }

    public function test_a_call_reads_the_spoken_text_instead_of_the_sms(): void
    {
        $notification = Notification::factory()->create([
            'team_id' => $this->team->id,
            'subject' => 'Botón de pánico en la unidad T-77 JC PZ4388A',
            'body_preview' => 'SAM: Botón de pánico en la unidad T-77, conductor Jesus Nolasco.',
            'payload_json' => ['spoken' => 'Hola, te llama SAM. Hay una alerta de botón de pánico en la unidad T 77.'],
        ]);
        $recipient = NotificationRecipient::factory()->create(['notification_id' => $notification->id, 'team_id' => $this->team->id]);
        $render = app(RenderNotificationContent::class);

        $voice = $render->execute($notification, $recipient, ChannelType::Voice);
        $this->assertNull($voice->subject);
        $this->assertSame('Hola, te llama SAM. Hay una alerta de botón de pánico en la unidad T 77.', $voice->body);

        $sms = $render->execute($notification, $recipient, ChannelType::Sms);
        $this->assertSame('SAM: Botón de pánico en la unidad T-77, conductor Jesus Nolasco.', $sms->body);

        // Una plantilla de voz del tenant sigue mandando.
        $template = NotificationTemplate::factory()->create([
            'team_id' => $this->team->id,
            'channel_type' => ChannelType::Voice->value,
            'event_type' => $notification->notification_type,
            'subject_template' => null,
            'body_template' => 'Texto propio del cliente.',
            'is_active' => true,
        ]);

        $this->assertSame('Texto propio del cliente.', $render->execute($notification, $recipient, ChannelType::Voice, $template)->body);
    }

    /**
     * @return array{0: NotificationDelivery, 1: string}
     */
    private function answeredCall(?Team $team = null, string $sidChar = 'f'): array
    {
        $team ??= $this->team;
        $sid = 'CA'.str_repeat($sidChar, 32);
        $voice = NotificationChannel::factory()->voice()->create();
        $notification = Notification::factory()->create(['team_id' => $team->id]);
        $recipient = NotificationRecipient::factory()->create(['notification_id' => $notification->id, 'team_id' => $team->id]);
        $delivery = NotificationDelivery::factory()->create([
            'notification_id' => $notification->id,
            'recipient_id' => $recipient->id,
            'channel_id' => $voice->id,
            'team_id' => $team->id,
            'status' => DeliveryStatus::Sending,
            'provider_message_id' => $sid,
        ]);
        MessagingCharge::factory()->forDelivery($delivery)->create();

        // Contestaron: el mensaje ya empezó a sonar.
        $this->postStatus(['CallSid' => $sid, 'CallStatus' => 'in-progress']);
        $this->assertSame(DeliveryStatus::Delivered, $delivery->fresh()->status);

        return [$delivery, $sid];
    }

    /**
     * @param  array<string, string>  $params
     */
    private function postStatus(array $params): void
    {
        $signature = (new RequestValidator('tok-456'))->computeSignature(url('/api/webhooks/twilio/status'), $params);

        $this->post('/api/webhooks/twilio/status', $params, ['X-Twilio-Signature' => $signature])->assertOk();
    }

    public function test_a_late_voicemail_verdict_fails_the_answered_call_and_falls_back(): void
    {
        Queue::fake([RetryNotificationDeliveryJob::class, FallbackNotificationChannelJob::class]);
        [$delivery, $sid] = $this->answeredCall();

        // El veredicto llega aparte, sin CallStatus.
        $this->postStatus(['CallSid' => $sid, 'AnsweredBy' => 'machine_start']);

        $fresh = $delivery->fresh();
        $this->assertSame(DeliveryStatus::Failed, $fresh->status);
        $this->assertSame(TwilioErrorCatalog::ANSWERED_BY_MACHINE, $fresh->provider_error_code);
        Queue::assertPushed(FallbackNotificationChannelJob::class);

        $this->assertSystemLogged('notifications.provider_status.amd_verdict', fn (array $c) => $c['calc'] === ['answered_by' => 'machine_start', 'applied_as' => 'in-progress']);
        $this->assertSystemLogged('notifications.provider_status.applied', fn (array $c) => $c['input']['delivery_id'] === $delivery->id
            && $c['result']['from_status'] === DeliveryStatus::Delivered->value
            && $c['result']['to_status'] === DeliveryStatus::Failed->value
            && $c['calc']['answered_by'] === 'machine_start');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_late_human_verdict_keeps_the_call_delivered(): void
    {
        Queue::fake([RetryNotificationDeliveryJob::class, FallbackNotificationChannelJob::class]);
        [$delivery, $sid] = $this->answeredCall();

        $this->postStatus(['CallSid' => $sid, 'AnsweredBy' => 'human']);

        $this->assertSame(DeliveryStatus::Delivered, $delivery->fresh()->status);
        $this->assertNotNull($delivery->fresh()->answered_at);
        Queue::assertNotPushed(FallbackNotificationChannelJob::class);
    }

    public function test_a_voicemail_verdict_only_fails_the_call_it_belongs_to(): void
    {
        Queue::fake([RetryNotificationDeliveryJob::class, FallbackNotificationChannelJob::class]);
        [$mine, $mySid] = $this->answeredCall();
        [$theirs] = $this->answeredCall(User::factory()->create()->currentTeam, 'e');

        $this->postStatus(['CallSid' => $mySid, 'AnsweredBy' => 'machine_start']);

        $this->assertSame(DeliveryStatus::Failed, $mine->fresh()->status);
        $this->assertSame(DeliveryStatus::Delivered, $theirs->fresh()->status, 'el veredicto de otra llamada no toca la de otro tenant');
        $this->assertNull($theirs->fresh()->provider_error_code);
    }
}
