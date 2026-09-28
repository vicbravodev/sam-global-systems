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
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * P2: un incidente por debajo de la severidad mínima del tenant (por defecto
 * `medium`) sólo se avisa dentro de la app; desde el umbral, la política
 * normal (correo, y fuera de banda si es crítico).
 */
class IncidentCreatedSeverityThresholdTest extends TestCase
{
    use AssertsTenantIsolation, RefreshDatabase;

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
    }

    public function test_medium_and_critical_incidents_keep_the_normal_policy_by_default(): void
    {
        $team = User::factory()->create()->currentTeam;

        $this->assertArrayNotHasKey('force_channels', $this->notifyFor($team, 'medium')->payload_json);
        $this->assertArrayNotHasKey('force_channels', $this->notifyFor($team, 'critical')->payload_json);
    }

    public function test_tenant_threshold_high_keeps_medium_in_app_but_not_critical(): void
    {
        $team = User::factory()->create()->currentTeam;
        $this->setThreshold($team, 'high');

        $this->assertSame(['web'], $this->notifyFor($team, 'medium')->payload_json['force_channels']);
        $this->assertArrayNotHasKey('force_channels', $this->notifyFor($team, 'critical')->payload_json);
    }

    public function test_another_tenants_threshold_never_applies(): void
    {
        $teamA = User::factory()->create()->currentTeam;
        $this->setThreshold($teamA, 'critical');

        $teamB = User::factory()->create()->currentTeam;
        $incident = $this->incident($teamB, 'medium');

        $this->assertNoTenantLeak($teamB, fn () => IncidentCreated::dispatch($incident));

        $notification = Notification::withoutGlobalScopes()->where('event_key', "incident_created:{$incident->id}")->sole();
        $this->assertArrayNotHasKey('force_channels', $notification->payload_json);
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
