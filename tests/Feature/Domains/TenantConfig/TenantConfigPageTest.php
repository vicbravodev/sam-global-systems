<?php

namespace Tests\Feature\Domains\TenantConfig;

use App\Domains\Access\Enums\RoleScope;
use App\Domains\Access\Models\Role;
use App\Domains\TenantConfig\Models\TenantConfigVersion;
use App\Domains\TenantConfig\Models\TenantEscalationConfig;
use App\Domains\TenantConfig\Models\TenantNotificationPolicy;
use App\Domains\TenantConfig\Models\TenantScheduleProfile;
use App\Domains\TenantConfig\Models\TenantSetting;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Roadmap F-TC: the tenant configuration page and its web mutations (which
 * reuse the TenantConfig API controllers under session+CSRF routes).
 */
class TenantConfigPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);

        $this->user = User::factory()->create();
        $this->team = $this->user->currentTeam;
    }

    public function test_page_renders_all_config_sections(): void
    {
        TenantSetting::factory()->create([
            'team_id' => $this->team->id,
            'setting_key' => 'media.auto_request_on_critical',
            'value_json' => ['value' => true],
        ]);
        TenantNotificationPolicy::factory()->create(['team_id' => $this->team->id]);
        TenantEscalationConfig::factory()->create(['team_id' => $this->team->id]);
        TenantScheduleProfile::factory()->create(['team_id' => $this->team->id]);
        TenantConfigVersion::factory()->create(['team_id' => $this->team->id, 'version' => 1]);

        $response = $this->actingAs($this->user)->get(
            route('tenant-config.show', ['current_team' => $this->team->slug]),
        );

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('settings/tenant-config')
                ->has('settings', 1)
                ->where('settings.0.key', 'media.auto_request_on_critical')
                ->has('aiProfile')
                ->has('aiProfileOptions.riskTolerances')
                ->has('notificationPolicies', 1)
                ->has('escalationConfigs', 1)
                ->has('escalationConditionFields', 2)
                ->where('escalationConditionFields.0.key', 'incident_type')
                ->has('scheduleProfiles', 1)
                ->has('versions', 1)
                ->where('canManage', true),
        );
    }

    public function test_page_does_not_leak_other_tenant_config(): void
    {
        TenantSetting::factory()->create([
            'team_id' => User::factory()->create()->currentTeam->id,
        ]);

        $response = $this->actingAs($this->user)->get(
            route('tenant-config.show', ['current_team' => $this->team->slug]),
        );

        $response->assertInertia(fn (Assert $page) => $page->has('settings', 0));
    }

    public function test_web_settings_update_persists_and_snapshots_version(): void
    {
        $response = $this->actingAs($this->user)->putJson(
            route('tenant-config.settings.update', ['current_team' => $this->team->slug]),
            [
                'settings' => [
                    [
                        'setting_key' => 'media.auto_request_on_critical',
                        'setting_group' => 'operational',
                        'value_type' => 'boolean',
                        'value' => true,
                    ],
                ],
            ],
        );

        $response->assertOk();

        $setting = TenantSetting::withoutGlobalScopes()
            ->where('team_id', $this->team->id)
            ->where('setting_key', 'media.auto_request_on_critical')
            ->first();

        $this->assertNotNull($setting);
        $this->assertTrue((bool) $setting->typed_value);

        // D-18: guardar settings del grupo operational (tab General) registra
        // una versión del tenant-config. Antes no se creaba ninguna.
        $this->assertSame(
            1,
            TenantConfigVersion::withoutGlobalScopes()
                ->where('team_id', $this->team->id)
                ->count(),
            'Saving operational settings must create exactly one config version',
        );
    }

    public function test_each_operational_settings_save_registers_a_new_version(): void
    {
        // D-18: el repro de la auditoría fueron 4 guardados que no dejaron
        // ninguna versión; cada guardado debe sumar exactamente una fila.
        foreach ([60, 90, 120, 180] as $i => $value) {
            $this->actingAs($this->user)->putJson(
                route('tenant-config.settings.update', ['current_team' => $this->team->slug]),
                [
                    'settings' => [
                        [
                            'setting_key' => 'context.live_location_staleness_seconds',
                            'setting_group' => 'operational',
                            'value_type' => 'number',
                            'value' => $value,
                        ],
                    ],
                ],
            )->assertOk();

            $this->assertSame(
                $i + 1,
                TenantConfigVersion::withoutGlobalScopes()
                    ->where('team_id', $this->team->id)
                    ->count(),
            );
        }
    }

    public function test_saving_notification_policies_registers_a_version(): void
    {
        // D-18: guardar políticas de notificación también deja una versión.
        $this->actingAs($this->user)->putJson(
            route('tenant-config.notifications.update', ['current_team' => $this->team->slug]),
            [
                'policies' => [
                    [
                        'policy_code' => 'critical_default',
                        'allowed_channels' => ['email', 'sms'],
                    ],
                ],
            ],
        )->assertOk();

        $this->assertSame(
            1,
            TenantConfigVersion::withoutGlobalScopes()
                ->where('team_id', $this->team->id)
                ->count(),
        );
    }

    public function test_gps_staleness_setting_rejects_non_positive_values(): void
    {
        // D-07: el umbral de obsolescencia GPS no puede ser negativo ni cero.
        foreach ([-5, 0, 'no-es-numero'] as $invalid) {
            $response = $this->actingAs($this->user)->putJson(
                route('tenant-config.settings.update', ['current_team' => $this->team->slug]),
                [
                    'settings' => [
                        [
                            'setting_key' => 'context.live_location_staleness_seconds',
                            'setting_group' => 'operational',
                            'value_type' => 'number',
                            'value' => $invalid,
                        ],
                    ],
                ],
            );

            $response->assertUnprocessable();
            $response->assertJsonValidationErrors(['settings.0.value']);
        }

        $this->assertNull(
            TenantSetting::withoutGlobalScopes()
                ->where('team_id', $this->team->id)
                ->where('setting_key', 'context.live_location_staleness_seconds')
                ->first(),
        );
    }

    public function test_gps_staleness_setting_accepts_valid_integer(): void
    {
        $response = $this->actingAs($this->user)->putJson(
            route('tenant-config.settings.update', ['current_team' => $this->team->slug]),
            [
                'settings' => [
                    [
                        'setting_key' => 'context.live_location_staleness_seconds',
                        'setting_group' => 'operational',
                        'value_type' => 'number',
                        'value' => 120,
                    ],
                ],
            ],
        );

        $response->assertOk();

        $setting = TenantSetting::withoutGlobalScopes()
            ->where('team_id', $this->team->id)
            ->where('setting_key', 'context.live_location_staleness_seconds')
            ->first();

        $this->assertNotNull($setting);
        $this->assertSame(120, (int) $setting->typed_value);
    }

    public function test_web_ai_profile_update_persists(): void
    {
        $response = $this->actingAs($this->user)->putJson(
            route('tenant-config.ai-profile.update', ['current_team' => $this->team->slug]),
            [
                'profile_code' => 'custom',
                'name' => 'Perfil agresivo',
                'risk_tolerance' => 'high',
                'false_positive_tolerance' => 'low',
                'automation_level' => 'semi_automatic',
                'media_strategy' => 'preferred',
            ],
        );

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Perfil agresivo');
    }

    public function test_member_without_config_view_gets_403(): void
    {
        $stranger = User::factory()->create();
        $team = $stranger->currentTeam;

        $role = Role::factory()->create([
            'code' => 'no_perms_config',
            'scope' => RoleScope::Tenant,
        ]);

        $team->members()->updateExistingPivot($stranger->id, [
            'role' => TeamRole::Member->value,
            'role_id' => $role->id,
        ]);

        $this->actingAs($stranger)->get(
            route('tenant-config.show', ['current_team' => $team->slug]),
        )->assertForbidden();

        $this->actingAs($stranger)->putJson(
            route('tenant-config.settings.update', ['current_team' => $team->slug]),
            ['settings' => [[
                'setting_key' => 'x',
                'setting_group' => 'operational',
                'value_type' => 'string',
                'value' => 'y',
            ]]],
        )->assertForbidden();
    }
}
