<?php

namespace Tests\Feature\Domains\Notifications;

use App\Domains\Incidents\Models\Incident;
use App\Domains\Notifications\Actions\SendNotification;
use App\Domains\Notifications\Channels\TwilioMessenger;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\NotificationTriggeredByType;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationReplyToken;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\NotificationMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Roadmap B9: critical incident SMS carries the reply token instructions and
 * persists the correlation; non-critical traffic stays untouched.
 */
class ReplyTokenIssuanceTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATOR_PHONE = '+5215512345678';

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(NotificationMeterSeeder::class);

        $this->team = User::factory()->create()->currentTeam;

        NotificationChannel::factory()->sms()->create([
            'team_id' => $this->team->id,
            'config_json' => [
                'twilio_account_sid' => 'AC123',
                'twilio_auth_token' => 'tok-456',
                'from' => '+14155238886',
            ],
        ]);
    }

    private function captureSmsBody(): object
    {
        $captured = new \stdClass;
        $captured->body = null;

        $messenger = Mockery::mock(TwilioMessenger::class);
        $messenger->shouldReceive('createMessage')
            ->andReturnUsing(function (array $config, string $to, array $params) use ($captured) {
                $captured->body = $params['body'] ?? null;

                return (object) ['sid' => 'SM123', 'status' => 'queued'];
            });
        $this->app->instance(TwilioMessenger::class, $messenger);

        return $captured;
    }

    private function sendIncidentSms(Incident $incident, NotificationPriority $priority): void
    {
        app(SendNotification::class)->execute(
            teamId: $this->team->id,
            notificationType: 'incident.created',
            sourceType: NotificationSourceType::Incident,
            sourceReferenceId: (string) $incident->id,
            priority: $priority,
            triggeredByType: NotificationTriggeredByType::System,
            triggeredById: null,
            eventKey: 'incident_created:'.$incident->id.':'.$priority->value,
            payload: [
                'recipients' => [[
                    'address' => self::OPERATOR_PHONE,
                    'channel_preference' => 'sms',
                ]],
                'force_channels' => ['sms'],
            ],
            subject: 'Incidente crítico',
            bodyPreview: 'Pánico en unidad 42.',
        );
    }

    public function test_critical_incident_sms_carries_reply_token_instructions(): void
    {
        $captured = $this->captureSmsBody();

        $incident = Incident::factory()->open()->create(['team_id' => $this->team->id]);

        $this->sendIncidentSms($incident, NotificationPriority::Critical);

        $token = NotificationReplyToken::withoutGlobalScopes()
            ->where('incident_id', $incident->id)
            ->where('address', self::OPERATOR_PHONE)
            ->first();

        $this->assertNotNull($token);
        $this->assertNotNull($captured->body);
        $this->assertStringContainsString("SI-{$token->token}", $captured->body);
        $this->assertStringContainsString("NO-{$token->token}", $captured->body);
        $this->assertLessThanOrEqual(160, mb_strlen($captured->body));
    }

    public function test_non_critical_incident_sms_has_no_reply_token(): void
    {
        $captured = $this->captureSmsBody();

        $incident = Incident::factory()->open()->create(['team_id' => $this->team->id]);

        $this->sendIncidentSms($incident, NotificationPriority::Normal);

        $this->assertSame(0, NotificationReplyToken::withoutGlobalScopes()->count());
        $this->assertNotNull($captured->body);
        $this->assertStringNotContainsString('Responde SI-', $captured->body);
    }

    public function test_token_is_reused_for_same_incident_and_address(): void
    {
        $this->captureSmsBody();

        $incident = Incident::factory()->open()->create(['team_id' => $this->team->id]);

        $this->sendIncidentSms($incident, NotificationPriority::Critical);

        // A second notification for the same incident+address (e.g. SLA
        // reminder) reuses the live token instead of minting another.
        app(SendNotification::class)->execute(
            teamId: $this->team->id,
            notificationType: 'incident.sla_reminder',
            sourceType: NotificationSourceType::Incident,
            sourceReferenceId: (string) $incident->id,
            priority: NotificationPriority::Critical,
            triggeredByType: NotificationTriggeredByType::System,
            triggeredById: null,
            eventKey: 'incident_sla:'.$incident->id,
            payload: [
                'recipients' => [[
                    'address' => self::OPERATOR_PHONE,
                    'channel_preference' => 'sms',
                ]],
                'force_channels' => ['sms'],
            ],
            subject: 'Recordatorio',
            bodyPreview: 'Sigue sin confirmar.',
        );

        $this->assertSame(1, NotificationReplyToken::withoutGlobalScopes()
            ->where('incident_id', $incident->id)
            ->count());
    }
}
