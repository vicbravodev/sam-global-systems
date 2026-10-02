<?php

namespace Tests\Feature\Http\Webhooks;

use App\Domains\Incidents\Models\Incident;
use App\Domains\Notifications\Channels\TwilioVoiceCaller;
use App\Domains\Notifications\Channels\VoiceNotificationDriver;
use App\Domains\Notifications\Data\RenderedNotification;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\RecipientType;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Models\NotificationRecipient;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\IncidentStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;
use Twilio\Security\RequestValidator;

/**
 * Una llamada de aviso de incidente se puede atender con una tecla: 1 =
 * "lo atiendo", reconoce el incidente y detiene la escalera.
 */
class TwilioNotificationCallWebhookTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, RefreshDatabase;

    private const AUTH_TOKEN = 'voice-tok-789';

    private Team $team;

    private User $operator;

    private NotificationChannel $voice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(IncidentStatusSeeder::class);

        $this->operator = User::factory()->create();
        $this->team = $this->operator->currentTeam;

        config()->set('services.twilio.account_sid', 'AC-voice');
        config()->set('services.twilio.auth_token', self::AUTH_TOKEN);

        $this->voice = NotificationChannel::factory()->voice()->create(['config_json' => ['from' => '+15005550006']]);
    }

    private function delivery(Incident $incident, ?User $user = null): NotificationDelivery
    {
        $notification = Notification::factory()->create([
            'team_id' => $this->team->id,
            'source_type' => NotificationSourceType::Incident,
            'source_reference_id' => (string) $incident->id,
        ]);

        $recipient = NotificationRecipient::factory()->create([
            'notification_id' => $notification->id,
            'team_id' => $this->team->id,
            'recipient_type' => $user !== null ? RecipientType::User : RecipientType::ExternalContact,
            'recipient_reference_id' => $user !== null ? (string) $user->id : null,
            'address' => '+5215512345678',
            'phone' => '+5215512345678',
        ]);

        return NotificationDelivery::factory()->create([
            'notification_id' => $notification->id,
            'recipient_id' => $recipient->id,
            'channel_id' => $this->voice->id,
            'team_id' => $this->team->id,
        ]);
    }

    private function press(NotificationDelivery $delivery, string $digits, ?string $authToken = self::AUTH_TOKEN): TestResponse
    {
        $path = "/api/webhooks/twilio/voice/notification/{$delivery->id}/gather";
        $params = ['CallSid' => 'CA123', 'Digits' => $digits];
        $signature = $authToken !== null
            ? (new RequestValidator($authToken))->computeSignature(url($path), $params)
            : 'forged';

        return $this->post($path, $params, ['X-Twilio-Signature' => $signature]);
    }

    public function test_pressing_1_acknowledges_the_incident_as_the_recipient_user(): void
    {
        $incident = Incident::factory()->open()->create(['team_id' => $this->team->id]);
        $delivery = $this->delivery($incident, $this->operator);

        $response = $this->press($delivery, '1');

        $response->assertOk();
        $this->assertStringContainsString('Incidente atendido', $response->getContent());

        $fresh = $incident->fresh();
        $this->assertNotNull($fresh->acknowledged_at);
        $this->assertSame($this->operator->id, (int) $fresh->acknowledged_by);

        $this->assertSystemLogged('notifications.voice_ack.received', fn (array $c) => $c['outcome'] === 'ok'
            && $c['input'] === ['delivery_id' => $delivery->id, 'notification_id' => $delivery->notification_id, 'incident_id' => $incident->id]
            && $c['result'] === ['acknowledged' => true, 'by_user' => true]);
        $this->assertStringNotContainsString('5512345678', json_encode($this->systemLogEntries()));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_an_external_contact_acknowledges_without_a_user(): void
    {
        $incident = Incident::factory()->open()->create(['team_id' => $this->team->id]);

        $this->press($this->delivery($incident), '1')->assertOk();

        $this->assertNotNull($incident->fresh()->acknowledged_at);
        $this->assertNull($incident->fresh()->acknowledged_by);
    }

    public function test_a_user_who_left_the_team_acknowledges_without_their_identity(): void
    {
        $outsider = User::factory()->create();
        $incident = Incident::factory()->open()->create(['team_id' => $this->team->id]);

        $this->press($this->delivery($incident, $outsider), '1')->assertOk();

        $this->assertNotNull($incident->fresh()->acknowledged_at);
        $this->assertNull($incident->fresh()->acknowledged_by);
    }

    public function test_other_digits_and_handled_incidents_change_nothing(): void
    {
        $incident = Incident::factory()->open()->create(['team_id' => $this->team->id]);
        $delivery = $this->delivery($incident, $this->operator);

        $this->press($delivery, '9')->assertOk();
        $this->assertNull($incident->fresh()->acknowledged_at);
        $this->assertSystemLogged('notifications.voice_ack.received', fn (array $c) => ($c['reason'] ?? null) === 'invalid_digit');

        $incident->forceFill(['acknowledged_at' => now()->subMinute()])->save();
        $this->press($delivery, '1')->assertOk();
        $this->assertSystemLogged('notifications.voice_ack.received', fn (array $c) => ($c['reason'] ?? null) === 'already_handled');
    }

    public function test_a_forged_signature_is_rejected(): void
    {
        $incident = Incident::factory()->open()->create(['team_id' => $this->team->id]);

        $this->press($this->delivery($incident, $this->operator), '1', authToken: null)->assertForbidden();

        $this->assertNull($incident->fresh()->acknowledged_at);
    }

    public function test_an_unknown_delivery_is_not_found_only_with_a_valid_signature(): void
    {
        $path = '/api/webhooks/twilio/voice/notification/999999/gather';
        $params = ['Digits' => '1'];

        // Sin firma válida ni siquiera se revela si la entrega existe.
        $this->post($path, $params)->assertForbidden();

        $signature = (new RequestValidator(self::AUTH_TOKEN))->computeSignature(url($path), $params);
        $this->post($path, $params, ['X-Twilio-Signature' => $signature])->assertNotFound();
    }

    public function test_a_delivery_never_acknowledges_another_tenants_incident(): void
    {
        $otherTeam = User::factory()->create()->currentTeam;
        $foreignIncident = Incident::factory()->open()->create(['team_id' => $otherTeam->id]);

        // Entrega de este tenant cuyo origen apunta (por error o manipulación
        // de datos) al id de un incidente ajeno.
        $delivery = $this->delivery($foreignIncident, $this->operator);

        $this->assertNoTenantLeak($this->team, fn () => $this->press($delivery, '1')->assertOk());

        $this->assertNull($foreignIncident->fresh()->acknowledged_at);
        $this->assertSystemLogged('notifications.voice_ack.received', fn (array $c) => ($c['reason'] ?? null) === 'incident_missing');
    }

    public function test_the_call_for_an_incident_notice_gathers_the_digit(): void
    {
        URL::forceRootUrl('https://sam.example.com');
        URL::forceScheme('https');

        $captured = new \stdClass;
        $caller = Mockery::mock(TwilioVoiceCaller::class);
        $caller->shouldReceive('createCall')->andReturnUsing(function (string $to, string $from, array $params) use ($captured) {
            $captured->twiml = $params['twiml'];

            return (object) ['sid' => 'CA'.str_repeat('a', 32), 'status' => 'queued'];
        });

        $driver = new VoiceNotificationDriver($caller);
        $rendered = new RenderedNotification(ChannelType::Voice, '+5215512345678', 'Pánico', 'Unidad 42.');

        $driver->send($rendered->forDelivery(77, true), $this->voice);
        $this->assertStringContainsString('<Gather numDigits="1"', $captured->twiml);
        $this->assertStringContainsString('https://sam.example.com/api/webhooks/twilio/voice/notification/77/gather', $captured->twiml);
        $this->assertStringContainsString('presiona 1', $captured->twiml);

        // Un aviso que no es de un incidente sólo se lee.
        $driver->send($rendered->forDelivery(78, false), $this->voice);
        $this->assertStringNotContainsString('<Gather', $captured->twiml);
    }
}
