<?php

namespace Tests\Feature\Http\Webhooks;

use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentCallVerification;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationReplyToken;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\IncidentStatusSeeder;
use Database\Seeders\PlatformChannelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Twilio\Security\RequestValidator;

/**
 * Los canales de plataforma (sam_sms / sam_whatsapp / sam_voice) no llevan
 * credenciales en config_json: viven en TWILIO_* (services.twilio) y los
 * drivers salientes las resuelven con PlatformTwilioConfig. Los webhooks
 * entrantes tienen que hacer lo mismo, o en producción cada respuesta SI/NO/
 * ESC y cada callback de voz responde 403.
 */
class TwilioPlatformChannelWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const AUTH_TOKEN = 'platform-token';

    private const SMS_FROM = '+15550001111';

    private const WHATSAPP_FROM = 'whatsapp:+15550002222';

    private const VOICE_FROM = '+15550003333';

    private const OPERATOR_PHONE = '+5215512345678';

    private Team $team;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->seed(IncidentStatusSeeder::class);

        config(['services.twilio' => [
            'account_sid' => 'AC-platform',
            'auth_token' => self::AUTH_TOKEN,
            'sms_from' => self::SMS_FROM,
            'whatsapp_from' => self::WHATSAPP_FROM,
            'voice_from' => self::VOICE_FROM,
        ]]);

        $this->seed(PlatformChannelSeeder::class);

        $this->operator = User::factory()->create();
        $this->team = $this->operator->currentTeam;
    }

    private function signedPost(string $path, array $params): TestResponse
    {
        $signature = (new RequestValidator(self::AUTH_TOKEN))->computeSignature(url($path), $params);

        return $this->post($path, $params, ['X-Twilio-Signature' => $signature]);
    }

    private function token(ChannelType $type): NotificationReplyToken
    {
        $incident = Incident::factory()->open()->create(['team_id' => $this->team->id]);

        return NotificationReplyToken::factory()->create([
            'team_id' => $this->team->id,
            'incident_id' => $incident->id,
            'user_id' => $this->operator->id,
            'channel_type' => $type,
            'address' => self::OPERATOR_PHONE,
            'token' => 'W4K9',
        ]);
    }

    public function test_platform_channels_have_no_credentials_in_config_json(): void
    {
        $this->assertSame([], NotificationChannel::query()
            ->where('provider', 'twilio')
            ->get()
            ->filter(fn (NotificationChannel $channel) => ! empty($channel->config_json))
            ->all());
    }

    public function test_sms_reply_to_the_platform_number_is_accepted(): void
    {
        $token = $this->token(ChannelType::Sms);

        $this->signedPost('/api/webhooks/twilio', [
            'From' => self::OPERATOR_PHONE,
            'To' => self::SMS_FROM,
            'Body' => 'SI-W4K9',
        ])->assertOk();

        $this->assertNotNull($token->refresh()->consumed_at);
    }

    public function test_whatsapp_reply_to_the_platform_number_is_accepted(): void
    {
        $token = $this->token(ChannelType::Whatsapp);

        $this->signedPost('/api/webhooks/twilio', [
            'From' => 'whatsapp:'.self::OPERATOR_PHONE,
            'To' => self::WHATSAPP_FROM,
            'Body' => 'SI-W4K9',
        ])->assertOk();

        $this->assertNotNull($token->refresh()->consumed_at);
    }

    public function test_forged_signature_on_a_platform_number_is_still_rejected(): void
    {
        $this->token(ChannelType::Sms);

        $this->post('/api/webhooks/twilio', [
            'From' => self::OPERATOR_PHONE,
            'To' => self::SMS_FROM,
            'Body' => 'SI-W4K9',
        ], ['X-Twilio-Signature' => 'forged'])->assertForbidden();
    }

    public function test_voice_callbacks_for_a_platform_voice_channel_are_accepted(): void
    {
        $channel = NotificationChannel::query()->where('code', 'sam_voice')->firstOrFail();
        $incident = Incident::factory()->open()->create(['team_id' => $this->team->id]);

        $verification = IncidentCallVerification::factory()->calling()->create([
            'team_id' => $this->team->id,
            'incident_id' => $incident->id,
            'notification_channel_id' => $channel->id,
            'phone' => self::OPERATOR_PHONE,
        ]);

        $this->signedPost("/api/webhooks/twilio/voice/{$verification->id}/status", [
            'CallSid' => (string) $verification->call_sid,
            'CallStatus' => 'ringing',
        ])->assertSuccessful();

        $this->signedPost("/api/webhooks/twilio/voice/{$verification->id}/gather", [
            'CallSid' => (string) $verification->call_sid,
            'Digits' => '1',
        ])->assertOk();
    }
}
