<?php

namespace Tests\Feature\Domains\Notifications;

use App\Domains\Incidents\Events\IncidentCreated;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentPriority;
use App\Domains\Notifications\Listeners\NotifyOnIncidentCreated;
use App\Domains\Notifications\Models\Notification;
use App\Domains\TenantConfig\Enums\SettingGroup;
use App\Domains\TenantConfig\Enums\SettingValueType;
use App\Domains\TenantConfig\Models\TenantSetting;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * P2: un incidente por debajo de la severidad mínima del tenant (por defecto
 * `medium`) sólo se avisa dentro de la app; desde el umbral, la política
 * normal (correo, y fuera de banda si es crítico).
 */
class IncidentCreatedSeverityThresholdTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IncidentsSeeder::class);
        Bus::fake();
        Cache::flush();
    }

    public function test_low_severity_incident_is_in_app_only_by_default(): void
    {
        $team = User::factory()->create()->currentTeam;

        $notification = $this->notifyFor($team, 'low');

        $this->assertSame(['web'], $notification->payload_json['force_channels']);

        $incidentId = (int) $notification->payload_json['incident_id'];
        $context = $this->assertSystemLogged('notifications.out_of_band.skipped', fn (array $c) => $c['reason'] === 'below_min_severity'
            && $c['input']['incident_id'] === $incidentId
            && $c['input']['setting_key'] === NotifyOnIncidentCreated::SETTING_MIN_SEVERITY
            && $c['input']['type_source'] === 'generic'
            && $c['calc']['severity'] === 'low'
            && $c['calc']['severity_rank'] === 1
            && $c['calc']['severity_rank_source'] === 'priority'
            && $c['calc']['min_severity'] === 'medium'
            && $c['calc']['min_severity_rank'] === 2
            && $c['calc']['min_severity_valid'] === true
            && $c['result']['forced_channel_types'] === ['web']);

        // Se rehace el umbral con los términos registrados.
        $this->assertTrue($context['calc']['severity_rank'] < $context['calc']['min_severity_rank']);

        $this->assertSystemLogged('notifications.notification.requested', fn (array $c) => $c['result']['notification_id'] === $notification->id
            && $c['calc']['forced_channel_types'] === ['web']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_medium_and_critical_incidents_keep_the_normal_policy_by_default(): void
    {
        $team = User::factory()->create()->currentTeam;

        $this->assertNormalPolicyGoesToFirstResponders($this->notifyFor($team, 'medium'));
        $this->assertNormalPolicyGoesToFirstResponders($this->notifyFor($team, 'critical'));
    }

    public function test_tenant_threshold_high_keeps_medium_in_app_but_not_critical(): void
    {
        $team = User::factory()->create()->currentTeam;
        $this->setThreshold($team, 'high');

        $medium = $this->notifyFor($team, 'medium');
        $this->assertSame(['web'], $medium->payload_json['force_channels']);
        $critical = $this->notifyFor($team, 'critical');
        $this->assertNormalPolicyGoesToFirstResponders($critical);

        $this->assertSystemLogged('notifications.out_of_band.skipped', fn (array $c) => $c['input']['incident_id'] === (int) $medium->payload_json['incident_id']
            && $c['calc']['severity_rank'] === 2
            && $c['calc']['min_severity'] === 'high'
            && $c['calc']['min_severity_rank'] === 3
            && $c['calc']['severity_rank'] < $c['calc']['min_severity_rank']);
        $this->assertCount(1, $this->systemLogEntries('notifications.out_of_band.skipped'));
        $this->assertSystemLogged('notifications.notification.requested', fn (array $c) => $c['result']['notification_id'] === $critical->id
            && $c['calc']['forced_channel_types'] === ['web', 'email']);
        $this->assertSystemLogged('notifications.incident_created.routed', fn (array $c) => $c['input']['incident_id'] === (int) $critical->payload_json['incident_id']
            && $c['calc']['responders_count'] === 1
            && $c['result']['team_forced_channel_types'] === ['web', 'email']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_an_invalid_tenant_threshold_falls_back_to_the_default_and_is_logged_as_such(): void
    {
        $team = User::factory()->create()->currentTeam;
        $this->setThreshold($team, 'urgent now');

        $notification = $this->notifyFor($team, 'low');

        $this->assertSame(['web'], $notification->payload_json['force_channels']);
        $this->assertSystemLogged('notifications.out_of_band.skipped', fn (array $c) => $c['calc']['min_severity'] === null
            && $c['calc']['min_severity_valid'] === false
            && $c['calc']['min_severity_rank'] === 2);
        $this->assertStringNotContainsString('urgent now', json_encode($this->systemLogEntries()));
    }

    public function test_another_tenants_threshold_never_applies(): void
    {
        $teamA = User::factory()->create()->currentTeam;
        $this->setThreshold($teamA, 'critical');

        $teamB = User::factory()->create()->currentTeam;
        $incident = $this->incident($teamB, 'medium');

        $this->assertNoTenantLeak($teamB, fn () => IncidentCreated::dispatch($incident));

        $notification = Notification::withoutGlobalScopes()->where('event_key', "incident_created:{$incident->id}")->sole();
        $this->assertNormalPolicyGoesToFirstResponders($notification);
    }

    public function test_settings_endpoint_validates_the_threshold_value(): void
    {
        $this->seed(AccessSeeder::class);
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $payload = fn (string $value) => ['settings' => [[
            'setting_key' => NotifyOnIncidentCreated::SETTING_MIN_SEVERITY,
            'setting_group' => 'notification',
            'value_type' => 'string',
            'value' => $value,
        ]]];

        $this->actingAs($user)
            ->putJson("/api/{$team->slug}/settings/config", $payload('urgent'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('settings.0.value');

        $this->actingAs($user)
            ->putJson("/api/{$team->slug}/settings/config", $payload('high'))
            ->assertOk();
    }

    private function setThreshold(Team $team, string $value): void
    {
        TenantSetting::factory()->create([
            'team_id' => $team->id,
            'setting_key' => NotifyOnIncidentCreated::SETTING_MIN_SEVERITY,
            'setting_group' => SettingGroup::Notification,
            'value_type' => SettingValueType::String,
            'value_json' => ['value' => $value],
        ]);
    }

    /**
     * Desde el umbral, la política normal del tenant (fuera de banda si es
     * crítico) va en el aviso a los primeros respondientes; el resto del
     * equipo queda fijado a app + correo (decisión 2026-10-01).
     */
    private function assertNormalPolicyGoesToFirstResponders(Notification $teamNotice): void
    {
        $this->assertSame(['web', 'email'], $teamNotice->payload_json['force_channels']);

        $responder = Notification::withoutGlobalScopes()
            ->where('team_id', $teamNotice->team_id)
            ->where('event_key', 'incident_created_responder:'.$teamNotice->payload_json['incident_id'])
            ->sole();

        $this->assertArrayNotHasKey('force_channels', $responder->payload_json);
        $this->assertTrue($responder->payload_json['first_responder']);
        $this->assertSame($responder->payload_json['recipients'][0]['recipient_reference_id'], (string) $teamNotice->payload_json['exclude_user_ids'][0]);
    }

    private function notifyFor(Team $team, string $priorityCode): Notification
    {
        $incident = $this->incident($team, $priorityCode);

        IncidentCreated::dispatch($incident);

        return Notification::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('event_key', "incident_created:{$incident->id}")
            ->sole();
    }

    private function incident(Team $team, string $priorityCode): Incident
    {
        return Incident::factory()->create([
            'team_id' => $team->id,
            'incident_priority_id' => IncidentPriority::query()->where('code', $priorityCode)->value('id'),
        ]);
    }
}
