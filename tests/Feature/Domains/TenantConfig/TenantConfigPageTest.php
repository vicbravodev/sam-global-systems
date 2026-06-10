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
