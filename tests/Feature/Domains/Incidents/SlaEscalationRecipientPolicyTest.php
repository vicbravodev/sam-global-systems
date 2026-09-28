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
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * P0-a: un SLA vencido sin escalación configurada (o con un paso sin
 * contactos) ya no hace blast fuera de banda a todo el equipo: sólo avisa a
 * supervisores/admins por la app y correo, con la prioridad del incidente.
 */
class SlaEscalationRecipientPolicyTest extends TestCase
{
    use AssertsTenantIsolation, RefreshDatabase;

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
