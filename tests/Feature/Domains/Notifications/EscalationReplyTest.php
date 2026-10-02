<?php

namespace Tests\Feature\Domains\Notifications;

use App\Domains\Incidents\Actions\NotifyEscalationLevel;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentPriority;
use App\Domains\Notifications\Actions\DispatchNotification;
use App\Domains\Notifications\Actions\SendNotification;
use App\Domains\Notifications\Channels\TwilioMessenger;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\NotificationStatus;
use App\Domains\Notifications\Enums\NotificationTriggeredByType;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Models\NotificationReplyToken;
use App\Domains\TenantConfig\Models\TenantEscalationConfig;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Database\Seeders\IncidentsSeeder;
use Database\Seeders\NotificationMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;
use Twilio\Security\RequestValidator;

/**
 * La escalación por SMS a los supervisores (destinatarios usuario, cuya
 * dirección base es su correo) debe poder atenderse respondiendo desde su
 * teléfono, también en incidentes altos; y un aviso de escalación que espera
 * en cola no sale si alguien ya atendió el incidente.
 */
class EscalationReplyTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

    private const AUTH_TOKEN = 'tok-456';

    private const TWILIO_NUMBER = '+14155238886';

    private const SUPERVISOR_PHONE = '+5215511112222';

    private Team $team;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);
        $this->seed(IncidentsSeeder::class);
        $this->seed(NotificationMeterSeeder::class);

        $this->owner = User::factory()->withVerifiedPhone(self::SUPERVISOR_PHONE)->create();
        $this->team = $this->owner->currentTeam;

        config()->set('services.twilio.account_sid', 'AC123');
        config()->set('services.twilio.auth_token', self::AUTH_TOKEN);

        NotificationChannel::factory()->sms()->create(['config_json' => ['from' => self::TWILIO_NUMBER]]);
        NotificationChannel::factory()->web()->create();

        TenantEscalationConfig::factory()->create([
            'team_id' => $this->team->id,
            'is_active' => true,
            'steps_json' => [['delay_minutes' => 0, 'channels' => ['sms']]],
        ]);
    }

    private function captureSms(): \stdClass
    {
        $captured = new \stdClass;
        $captured->to = null;
        $captured->body = null;

        $messenger = Mockery::mock(TwilioMessenger::class);
        $messenger->shouldReceive('createMessage')->andReturnUsing(function (string $to, array $params) use ($captured) {
            $captured->to = $to;
            $captured->body = $params['body'] ?? null;

            return (object) ['sid' => 'SM'.str_repeat('a', 32), 'status' => 'queued'];
        });
        $this->app->instance(TwilioMessenger::class, $messenger);

        return $captured;
    }

    private function highIncident(): Incident
    {
        $high = IncidentPriority::query()->updateOrCreate(
            ['code' => 'high'],
            ['name' => 'High', 'level' => 3, 'sla_seconds' => 1800, 'color' => '#f97316'],
        );

        return Incident::factory()->open()->create(['team_id' => $this->team->id, 'incident_priority_id' => $high->id]);
    }

    private function notifyLevelZero(Incident $incident): void
    {
        app(NotifyEscalationLevel::class)->execute(
            incident: $incident,
            level: 0,
            eventKey: "incident_sla_breached:{$incident->id}:0",
            notificationType: 'incident.sla_breached',
            subject: 'SLA vencido sin atención',
            body: 'El incidente superó su SLA.',
        );
    }

    public function test_supervisor_reply_from_their_phone_acknowledges_a_high_escalation(): void
    {
        $captured = $this->captureSms();
        $incident = $this->highIncident();

        $this->notifyLevelZero($incident);

        $this->assertSame(self::SUPERVISOR_PHONE, $captured->to);

        // El token se emite para el teléfono al que salió el SMS (no el correo
        // del usuario) y el aviso alto también lleva las instrucciones.
        $token = NotificationReplyToken::withoutGlobalScopes()->where('incident_id', $incident->id)->sole();
        $this->assertSame(self::SUPERVISOR_PHONE, $token->address);
        $this->assertSame($this->owner->id, $token->user_id);
        $this->assertStringContainsString("SI-{$token->token}", (string) $captured->body);

        $params = ['From' => self::SUPERVISOR_PHONE, 'To' => self::TWILIO_NUMBER, 'Body' => "SI-{$token->token}"];
        $url = url('/api/webhooks/twilio');
        $signature = (new RequestValidator(self::AUTH_TOKEN))->computeSignature($url, $params);

        $this->post('/api/webhooks/twilio', $params, ['X-Twilio-Signature' => $signature])->assertOk();

        $fresh = $incident->fresh();
        $this->assertNotNull($fresh->acknowledged_at, 'la respuesta del supervisor detiene la escalera');
        $this->assertSame($this->owner->id, (int) $fresh->acknowledged_by);
    }

    public function test_queued_escalation_notice_is_cancelled_when_the_incident_was_handled(): void
    {
        $this->captureSms();
        $incident = $this->highIncident();

        $notification = app(SendNotification::class)->execute(
            teamId: $this->team->id,
            notificationType: 'incident.sla_breached',
            sourceType: NotificationSourceType::Incident,
            sourceReferenceId: (string) $incident->id,
            priority: NotificationPriority::High,
            triggeredByType: NotificationTriggeredByType::System,
            triggeredById: null,
            eventKey: "incident_sla_breached:{$incident->id}:0",
            payload: ['escalation_level' => 0, 'force_channels' => ['sms'], 'recipients' => [['address' => self::SUPERVISOR_PHONE]]],
            dispatchJob: false,
        );

        // Mientras esperaba en cola, alguien del equipo lo tomó.
        $incident->forceFill(['claimed_by_user_id' => $this->owner->id, 'claimed_at' => now()])->save();

        app(DispatchNotification::class)->execute($notification);

        $this->assertSame(NotificationStatus::Cancelled, $notification->fresh()->status);
        $this->assertSame(0, NotificationDelivery::withoutGlobalScopes()->where('notification_id', $notification->id)->count());

        $this->assertSystemLogged('notifications.escalation_notice.cancelled', fn (array $c) => $c['reason'] === 'incident_handled'
            && $c['input'] === ['notification_id' => $notification->id]
            && $c['calc'] === ['incident_id' => $incident->id, 'acknowledged' => false, 'claimed' => true, 'terminal' => false]);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_non_escalation_notices_still_go_out_for_handled_incidents(): void
    {
        $captured = $this->captureSms();
        $incident = $this->highIncident();
        $incident->forceFill(['acknowledged_at' => now()])->save();

        app(SendNotification::class)->execute(
            teamId: $this->team->id,
            notificationType: 'incident.status_changed',
            sourceType: NotificationSourceType::Incident,
            sourceReferenceId: (string) $incident->id,
            priority: NotificationPriority::High,
            triggeredByType: NotificationTriggeredByType::System,
            triggeredById: null,
            eventKey: "incident_status:{$incident->id}:closed",
            payload: ['force_channels' => ['sms'], 'recipients' => [['address' => self::SUPERVISOR_PHONE]]],
        );

        $this->assertNotNull($captured->body);
        $this->assertSame(0, Notification::withoutGlobalScopes()->where('status', NotificationStatus::Cancelled)->count());
    }
}
