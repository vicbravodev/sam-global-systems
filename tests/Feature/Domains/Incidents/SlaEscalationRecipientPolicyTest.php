<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Incidents\Enums\IncidentStatusCode;
use App\Domains\Incidents\Jobs\CheckIncidentAcknowledgementJob;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentPriority;
use App\Domains\Incidents\Models\IncidentStatus;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Models\Notification;
use App\Domains\TenantConfig\Models\TenantEscalationConfig;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * P0-a: un SLA vencido sin escalación configurada (o con un paso sin
 * contactos) ya no hace blast fuera de banda a todo el equipo: sólo avisa a
 * supervisores/admins por la app y correo, con la prioridad del incidente.
 */
class SlaEscalationRecipientPolicyTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, RefreshDatabase;

    private User $owner;

    private Team $team;

    private User $admin;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);
        $this->seed(IncidentsSeeder::class);

        $this->owner = User::factory()->create();
        $this->team = $this->owner->currentTeam;

        $this->admin = User::factory()->create(['phone' => '+5215555550101', 'phone_verified_at' => now()]);
        $this->member = User::factory()->create(['phone' => '+5215555550102', 'phone_verified_at' => now()]);
        $this->team->members()->attach($this->admin, ['role' => TeamRole::Admin->value]);
        $this->team->members()->attach($this->member, ['role' => TeamRole::Member->value]);
    }

    public function test_without_escalation_config_only_supervisors_get_web_and_email(): void
    {
        Queue::fake();

        $incident = $this->breachedIncident($this->team, 'medium');

        $this->runWatchdog($incident);

        $notification = $this->slaNotification($incident);

        $recipientIds = collect($notification->payload_json['recipients'])->pluck('recipient_reference_id')->sort()->values()->all();
        $expected = collect([(string) $this->owner->id, (string) $this->admin->id])->sort()->values()->all();

        $this->assertSame($expected, $recipientIds);
        $this->assertNotContains((string) $this->member->id, $recipientIds);
        $this->assertSame(['web', 'email'], $notification->payload_json['force_channels']);
        $this->assertSame(NotificationPriority::Normal, $notification->priority);

        $c = $this->assertSystemLogged('incidents.escalation_level.notified', fn (array $c) => $c['outcome'] === 'ok');
        $this->assertSame(['incident_id' => $incident->id, 'level' => 0, 'notification_type' => 'incident.sla_breached'], $c['input']);
        $this->assertSame('supervisors', $c['calc']['recipients_source']);
        $this->assertFalse($c['calc']['step_present']);
        $this->assertSame(0, $c['calc']['contacts_count']);
        $this->assertSame(2, $c['calc']['recipients_count']);
        $this->assertFalse($c['calc']['urgent']);
        $this->assertSame(['web', 'email'], $c['calc']['forced_channel_types']);
        $this->assertSame($notification->id, $c['result']['notification_id']);

        $json = json_encode($this->systemLogEntries());
        foreach ([$this->owner, $this->admin] as $user) {
            $this->assertStringNotContainsString((string) json_encode($user->email), $json);
            $this->assertStringNotContainsString((string) json_encode($user->name), $json);
        }
        $this->assertStringNotContainsString('+5215555550101', $json);
        $this->assertNoSensitiveDataLogged();
    }

    /**
     * Decisión 2026-09-28: sin contactos en el paso, un incidente alto o
     * crítico llega a admins/supervisores por los canales del paso (voz a su
     * teléfono verificado) — pero NUNCA al equipo entero.
     */
    public function test_step_without_contacts_never_calls_every_member(): void
    {
        Queue::fake();

        TenantEscalationConfig::factory()->create([
            'team_id' => $this->team->id,
            'is_active' => true,
            'steps_json' => [['delay_minutes' => 0, 'channels' => ['voice'], 'attempts' => 3]],
        ]);

        $incident = $this->breachedIncident($this->team, 'high');

        $this->runWatchdog($incident);

        $notification = $this->slaNotification($incident);

        $this->assertSame(['voice', 'web'], $notification->payload_json['force_channels']);
        $this->assertNotContains(
            (string) $this->member->id,
            collect($notification->payload_json['recipients'])->pluck('recipient_reference_id')->all(),
        );
        $this->assertSame(NotificationPriority::High, $notification->priority);
    }

    public function test_configured_contacts_still_get_the_pinned_channels(): void
    {
        Queue::fake();

        TenantEscalationConfig::factory()->create([
            'team_id' => $this->team->id,
            'is_active' => true,
            'steps_json' => [['delay_minutes' => 0, 'contacts' => ['+5215512345678'], 'channels' => ['voice']]],
        ]);

        $incident = $this->breachedIncident($this->team, 'critical');

        $this->runWatchdog($incident);

        $notification = $this->slaNotification($incident);

        $this->assertSame('+5215512345678', $notification->payload_json['recipients'][0]['address']);
        $this->assertSame(['voice'], $notification->payload_json['force_channels']);
        $this->assertSame(NotificationPriority::Critical, $notification->priority);
        $this->assertSame(IncidentStatusCode::Escalated->value, $incident->fresh()->status->code);

        $c = $this->assertSystemLogged('incidents.escalation_level.notified', fn (array $c) => $c['outcome'] === 'ok');
        $this->assertSame('step_contacts', $c['calc']['recipients_source']);
        $this->assertTrue($c['calc']['step_present']);
        $this->assertGreaterThan(0, $c['calc']['contacts_count']);
        $this->assertSame(1, $c['calc']['recipients_count']);
        $this->assertSame(['voice'], $c['calc']['step_channel_types']);
        $this->assertNull($c['calc']['urgent']);
        $this->assertSame(['voice'], $c['calc']['forced_channel_types']);

        $json = json_encode($this->systemLogEntries());
        $this->assertStringNotContainsString('+5215512345678', $json);
        $this->assertStringNotContainsString('5512345678', $json);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_level_without_contacts_nor_supervisors_is_logged_instead_of_silent(): void
    {
        Queue::fake();

        // Un equipo sin admins ni supervisores: sólo un miembro raso.
        $team = Team::factory()->create();
        $plain = User::factory()->create();
        $team->members()->attach($plain, ['role' => TeamRole::Member->value]);

        $incident = $this->breachedIncident($team, 'medium');

        $this->runWatchdog($incident);

        $this->assertSame(0, Notification::withoutGlobalScopes()
            ->where('event_key', "incident_sla_breached:{$incident->id}:0")
            ->count());

        $this->assertSystemLogged('incidents.escalation_level.notified', fn (array $c) => $c['outcome'] === 'skipped'
            && $c['reason'] === 'no_supervisors'
            && $c['input'] === ['incident_id' => $incident->id, 'level' => 0, 'notification_type' => 'incident.sla_breached']
            && $c['calc'] === ['step_present' => false, 'contacts_count' => 0]);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_default_supervisor_fanout_never_reaches_another_tenant(): void
    {
        Queue::fake();

        // Team A con sus propios supervisores; el SLA vence en team B.
        $ownerB = User::factory()->create();
        $teamB = $ownerB->currentTeam;
        $incidentB = $this->breachedIncident($teamB, 'medium');

        $this->assertNoTenantLeak($teamB, fn () => $this->runWatchdog($incidentB));

        $recipientIds = collect($this->slaNotification($incidentB)->payload_json['recipients'])
            ->pluck('recipient_reference_id')
            ->all();

        $this->assertSame([(string) $ownerB->id], $recipientIds);
    }

    private function breachedIncident(Team $team, string $priorityCode): Incident
    {
        $open = IncidentStatus::query()->where('code', IncidentStatusCode::Open->value)->firstOrFail();
        $priority = IncidentPriority::query()->where('code', $priorityCode)->firstOrFail();

        return Incident::factory()->create([
            'team_id' => $team->id,
            'incident_status_id' => $open->id,
            'incident_priority_id' => $priority->id,
            'sla_due_at' => now()->subMinute(),
        ]);
    }

    private function runWatchdog(Incident $incident): void
    {
        app()->call([new CheckIncidentAcknowledgementJob($incident->id), 'handle']);
    }

    private function slaNotification(Incident $incident): Notification
    {
        return Notification::withoutGlobalScopes()
            ->where('team_id', $incident->team_id)
            ->where('event_key', "incident_sla_breached:{$incident->id}:0")
            ->sole();
    }
}
