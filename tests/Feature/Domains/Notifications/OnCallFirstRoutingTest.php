<?php

namespace Tests\Feature\Domains\Notifications;

use App\Domains\Access\Models\Role;
use App\Domains\Incidents\Actions\NotifyEscalationLevel;
use App\Domains\Incidents\Events\IncidentCreated;
use App\Domains\Incidents\Events\IncidentStatusChanged;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentPriority;
use App\Domains\Notifications\Actions\DispatchNotification;
use App\Domains\Notifications\Actions\SendNotification;
use App\Domains\Notifications\Channels\TwilioMessenger;
use App\Domains\Notifications\Enums\DeliveryStatus;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\NotificationTriggeredByType;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Models\NotificationRecipient;
use App\Domains\TenantConfig\Models\TenantEscalationConfig;
use App\Domains\TenantConfig\Models\TenantScheduleProfile;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Database\Seeders\IncidentsSeeder;
use Database\Seeders\NotificationMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * Decisiones 2026-10-01: los canales pagados son para la persona en turno;
 * la escalera va en turno → operación → admins; un cambio de estado es
 * informativo; la misma persona no recibe dos avisos pagados del mismo
 * incidente en segundos.
 */
class OnCallFirstRoutingTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, RefreshDatabase;

    private Team $team;

    private User $owner;

    private User $supervisor;

    private User $onCall;

    private User $plainMember;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);
        $this->seed(IncidentsSeeder::class);
        $this->seed(NotificationMeterSeeder::class);
        Cache::flush();

        $this->owner = User::factory()->withVerifiedPhone('+5215510000001')->create();
        $this->team = $this->owner->currentTeam;
        $this->supervisor = $this->member('supervisor', '+5215510000002');
        $this->onCall = $this->member('monitorista', '+5215510000003');
        $this->plainMember = $this->member('viewer', '+5215510000004');
    }

    private function member(string $roleCode, string $phone): User
    {
        $user = User::factory()->withVerifiedPhone($phone)->create();
        $this->team->members()->attach($user, ['role' => 'member']);

        Membership::query()
            ->where('team_id', $this->team->id)
            ->where('user_id', $user->id)
            ->update(['role_id' => Role::query()->where('code', $roleCode)->firstOrFail()->id]);

        return $user;
    }

    private function scheduleOnCall(User $user): void
    {
        TenantScheduleProfile::factory()->create([
            'team_id' => $this->team->id,
            'is_active' => true,
            'shift_rules_json' => ['on_call' => [['user_id' => $user->id]]],
        ]);
    }

    private function incident(string $priority = 'critical'): Incident
    {
        $priorityModel = IncidentPriority::query()->where('code', $priority)->firstOrFail();

        return Incident::factory()->open()->create(['team_id' => $this->team->id, 'incident_priority_id' => $priorityModel->id]);
    }

    /**
     * @return list<string>
     */
    private function recipientUserIds(Notification $notification): array
    {
        return collect($notification->payload_json['recipients'] ?? [])->pluck('recipient_reference_id')->map(fn ($id) => (string) $id)->sort()->values()->all();
    }

    private function notifyLevel(Incident $incident, int $level): Notification
    {
        app(NotifyEscalationLevel::class)->execute(
            incident: $incident,
            level: $level,
            eventKey: "test_level:{$incident->id}:{$level}",
            notificationType: 'incident.sla_breached',
            subject: 'SLA',
            body: 'SLA vencido',
        );

        return Notification::withoutGlobalScopes()->where('event_key', "test_level:{$incident->id}:{$level}")->sole();
    }

    public function test_the_default_ladder_goes_on_call_then_operations_then_admins(): void
    {
        Bus::fake();
        $this->scheduleOnCall($this->onCall);
        $incident = $this->incident();

        $this->assertSame([(string) $this->onCall->id], $this->recipientUserIds($this->notifyLevel($incident, 0)));

        $operations = collect([(string) $this->supervisor->id, (string) $this->onCall->id])->sort()->values()->all();
        $this->assertSame($operations, $this->recipientUserIds($this->notifyLevel($incident, 1)));

        $this->assertSame([(string) $this->owner->id], $this->recipientUserIds($this->notifyLevel($incident, 2)));

        // Nunca el miembro raso, en ningún nivel.
        $this->assertSame(0, Notification::withoutGlobalScopes()
            ->get()
            ->filter(fn (Notification $n) => in_array((string) $this->plainMember->id, $this->recipientUserIds($n), true))
            ->count());

        $this->assertSystemLogged('incidents.escalation_level.notified', fn (array $c) => $c['input']['level'] === 0
            && $c['calc']['audience'] === 'on_call'
            && $c['calc']['audience_fallback'] === false);
        $this->assertSystemLogged('incidents.escalation_level.notified', fn (array $c) => $c['input']['level'] === 2
            && $c['calc']['audience'] === 'admins');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_without_anyone_on_call_level_zero_falls_to_operations(): void
    {
        Bus::fake();
        $incident = $this->incident();

        $notice = $this->notifyLevel($incident, 0);

        $this->assertSame(
            collect([(string) $this->supervisor->id, (string) $this->onCall->id])->sort()->values()->all(),
            $this->recipientUserIds($notice),
        );
        $this->assertSystemLogged('incidents.escalation_level.notified', fn (array $c) => $c['calc']['audience_requested'] === 'on_call'
            && $c['calc']['audience'] === 'operations'
            && $c['calc']['audience_fallback'] === true);
    }

    public function test_a_step_can_pin_its_audience_and_never_sends_sms_and_whatsapp_together(): void
    {
        Bus::fake();
        TenantEscalationConfig::factory()->create([
            'team_id' => $this->team->id,
            'is_active' => true,
            'steps_json' => [['delay_minutes' => 0, 'audience' => 'admins', 'channels' => ['sms', 'whatsapp', 'push']]],
        ]);
        $incident = $this->incident();

        $notice = $this->notifyLevel($incident, 0);

        $this->assertSame([(string) $this->owner->id], $this->recipientUserIds($notice));
        $this->assertSame(['sms', 'push', 'web'], $notice->payload_json['force_channels']);
        $this->assertSystemLogged('incidents.escalation_level.notified', fn (array $c) => $c['calc']['whatsapp_dropped_for_sms'] === true);
    }

    public function test_a_critical_incident_pages_only_the_on_call_person(): void
    {
        Bus::fake();
        $this->scheduleOnCall($this->onCall);
        $otherTeam = User::factory()->create()->currentTeam;
        $incident = $this->incident();

        $this->assertNoTenantLeak($this->team, fn () => IncidentCreated::dispatch($incident));

        $responder = Notification::withoutGlobalScopes()->where('event_key', "incident_created_responder:{$incident->id}")->sole();
        $this->assertSame([(string) $this->onCall->id], $this->recipientUserIds($responder));
        $this->assertArrayNotHasKey('force_channels', $responder->payload_json);
        $this->assertSame(NotificationPriority::Critical, $responder->priority);

        $team = Notification::withoutGlobalScopes()->where('event_key', "incident_created:{$incident->id}")->sole();
        $this->assertSame(['web', 'email'], $team->payload_json['force_channels']);
        $this->assertSame([$this->onCall->id], $team->payload_json['exclude_user_ids']);

        $this->assertSame(0, Notification::withoutGlobalScopes()->where('team_id', $otherTeam->id)->count());
        $this->assertSystemLogged('notifications.incident_created.routed', fn (array $c) => $c['outcome'] === 'ok'
            && $c['calc'] === ['audience' => 'on_call', 'audience_fallback' => false, 'responders_count' => 1]);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_the_team_notice_skips_whoever_already_got_the_responder_notice(): void
    {
        $this->scheduleOnCall($this->onCall);
        NotificationChannel::factory()->web()->create();
        $incident = $this->incident();

        Bus::fake();
        IncidentCreated::dispatch($incident);

        $team = Notification::withoutGlobalScopes()->where('event_key', "incident_created:{$incident->id}")->sole();
        app(DispatchNotification::class)->execute($team);

        $reached = NotificationDelivery::withoutGlobalScopes()
            ->where('notification_id', $team->id)
            ->with('recipient')
            ->get()
            ->pluck('recipient.recipient_reference_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        $expected = collect([$this->owner->id, $this->supervisor->id, $this->plainMember->id])->sort()->values()->all();
        $this->assertSame($expected, $reached);
    }

    public function test_status_changes_are_informational_and_system_escalations_are_left_to_the_ladder(): void
    {
        Bus::fake();
        $incident = $this->incident();
        $incident->forceFill(['claimed_by_user_id' => $this->supervisor->id, 'claimed_at' => now()])->save();

        // Escalación del sistema (verificación sin respuesta, emergencia
        // confirmada): la escalera ya avisa; aquí no se repite.
        IncidentStatusChanged::dispatch($incident, 'open', 'escalated', null);
        $this->assertSame(0, Notification::withoutGlobalScopes()->where('event_key', 'like', "incident_status:{$incident->id}:%")->count());
        $this->assertSystemLogged('notifications.status_change.skipped', fn (array $c) => $c['reason'] === 'escalated_by_system');

        // Un cambio hecho por una persona sí avisa, pero sólo app + correo,
        // también en un crítico.
        IncidentStatusChanged::dispatch($incident->fresh(), 'escalated', 'resolved', $this->owner->id);
        $resolved = Notification::withoutGlobalScopes()->where('event_key', 'like', "incident_status:{$incident->id}:resolved:%")->sole();
        $this->assertSame(['web', 'email'], $resolved->payload_json['force_channels']);

        // Volver al mismo estado más tarde también avisa (clave por transición).
        $this->travel(1)->minutes();
        $incident->touch();
        IncidentStatusChanged::dispatch($incident->fresh(), 'open', 'resolved', $this->owner->id);
        $this->assertSame(2, Notification::withoutGlobalScopes()->where('event_key', 'like', "incident_status:{$incident->id}:resolved:%")->count());
    }

    private function fakeTwilio(): \stdClass
    {
        config()->set('services.twilio.account_sid', 'AC123');
        config()->set('services.twilio.auth_token', 'tok-456');

        $sent = new \stdClass;
        $sent->to = [];

        $messenger = Mockery::mock(TwilioMessenger::class);
        $messenger->shouldReceive('createMessage')->andReturnUsing(function (string $to) use ($sent) {
            $sent->to[] = $to;

            return (object) ['sid' => 'SM'.bin2hex(random_bytes(16)), 'status' => 'queued'];
        });
        $this->app->instance(TwilioMessenger::class, $messenger);

        return $sent;
    }

    private function sendToOnCall(Incident $incident, string $eventKey): Notification
    {
        return app(SendNotification::class)->execute(
            teamId: $this->team->id,
            notificationType: 'incident.created',
            sourceType: NotificationSourceType::Incident,
            sourceReferenceId: (string) $incident->id,
            priority: NotificationPriority::Critical,
            triggeredByType: NotificationTriggeredByType::System,
            triggeredById: null,
            eventKey: $eventKey,
            payload: [
                'force_channels' => ['sms', 'web'],
                'recipients' => [[
                    'recipient_type' => 'user',
                    'address' => $this->onCall->email,
                    'email' => $this->onCall->email,
                    'phone' => $this->onCall->verifiedPhone(),
                    'recipient_reference_id' => (string) $this->onCall->id,
                ]],
            ],
        );
    }

    public function test_the_same_person_is_not_paged_twice_for_one_incident_within_seconds(): void
    {
        $sent = $this->fakeTwilio();
        NotificationChannel::factory()->sms()->create(['config_json' => ['from' => '+14155238886']]);
        NotificationChannel::factory()->web()->create();
        $incident = $this->incident();

        $first = $this->sendToOnCall($incident, 'first');
        $second = $this->sendToOnCall($incident, 'second');

        $this->assertSame(['+5215510000003'], $sent->to, 'un solo SMS para dos avisos seguidos');

        $skipped = NotificationDelivery::withoutGlobalScopes()->where('notification_id', $second->id)->where('status', DeliveryStatus::Skipped)->sole();
        $this->assertStringContainsString('paid cooldown', (string) $skipped->error_message);
        $this->assertSame(1, NotificationDelivery::withoutGlobalScopes()
            ->where('notification_id', $second->id)
            ->where('status', DeliveryStatus::Delivered)
            ->count(), 'la app sigue avisando');

        $this->assertSystemLogged('notifications.delivery.skipped', fn (array $c) => $c['reason'] === 'paid_cooldown'
            && $c['calc']['incident_id'] === $incident->id
            && $c['calc']['cooldown_seconds'] === DispatchNotification::PAID_COOLDOWN_SECONDS
            && $c['calc']['previous_notification_id'] === $first->id);
        $this->assertNoSensitiveDataLogged();

        // Pasada la ventana, un nuevo aviso sí vuelve a salir por SMS.
        $this->travel(DispatchNotification::PAID_COOLDOWN_SECONDS + 1)->seconds();
        $this->sendToOnCall($incident, 'third');
        $this->assertCount(2, $sent->to);

        // Y otro incidente nunca queda bloqueado por la ventana del primero.
        $this->sendToOnCall($this->incident(), 'other_incident');
        $this->assertCount(3, $sent->to);
    }

    public function test_another_tenants_page_for_the_same_incident_id_and_phone_never_triggers_the_cooldown(): void
    {
        $sent = $this->fakeTwilio();
        NotificationChannel::factory()->sms()->create(['config_json' => ['from' => '+14155238886']]);
        $incident = $this->incident();

        // Otro tenant acaba de mandar un SMS al mismo teléfono con un aviso
        // cuyo origen tiene el mismo id de incidente.
        $otherTeam = User::factory()->create()->currentTeam;
        $foreign = Notification::factory()->create([
            'team_id' => $otherTeam->id,
            'source_type' => NotificationSourceType::Incident,
            'source_reference_id' => (string) $incident->id,
        ]);
        $foreignRecipient = NotificationRecipient::factory()->create([
            'notification_id' => $foreign->id,
            'team_id' => $otherTeam->id,
            'address' => '+5215510000003',
            'phone' => '+5215510000003',
        ]);
        NotificationDelivery::factory()->create([
            'notification_id' => $foreign->id,
            'recipient_id' => $foreignRecipient->id,
            'channel_id' => NotificationChannel::query()->where('channel_type', 'sms')->value('id'),
            'team_id' => $otherTeam->id,
            'status' => DeliveryStatus::Sent,
        ]);

        $this->sendToOnCall($incident, 'own_page');

        $this->assertSame(['+5215510000003'], $sent->to, 'el aviso de otro tenant no silencia el propio');
        $this->assertSystemNotLogged('notifications.delivery.skipped');
    }
}
