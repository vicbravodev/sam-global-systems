<?php

namespace Tests\Feature\Domains\TenantConfig;

use App\Domains\Tenancy\Models\TenantBranding;
use App\Domains\TenantConfig\Models\TenantConfigVersion;
use App\Domains\TenantConfig\Models\TenantEscalationConfig;
use App\Domains\TenantConfig\Models\TenantNotificationPolicy;
use App\Domains\TenantConfig\Models\TenantScheduleProfile;
use App\Domains\TenantConfig\Models\TenantSetting;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * Rediseño de Ajustes: la configuración de la empresa se navega por
 * secciones (`?seccion=`) sobre la misma página, cada sección guarda sólo
 * sus propios ajustes y la sección "Avanzado" edita ajustes finos por clave.
 * Ninguno de esos caminos puede leer ni tocar la configuración de otro tenant.
 */
class TenantConfigSectionsTest extends TestCase
{
    use AssertsTenantIsolation;
    use RefreshDatabase;

    private User $user;

    private Team $team;

    private Team $otherTeam;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);

        $this->user = User::factory()->create();
        $this->team = $this->user->currentTeam;
        $this->otherTeam = User::factory()->create()->currentTeam;
    }

    public function test_every_section_renders_the_same_page_with_its_data(): void
    {
        foreach (['emergencias', 'ia', 'avisos', 'escalamiento', 'guardias', 'marca', 'avanzado', 'desconocida'] as $section) {
            $this->actingAs($this->user)
                ->get(route('tenant-config.show', ['current_team' => $this->team->slug, 'seccion' => $section]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('settings/tenant-config')
                    ->has('settings')
                    ->has('channels')
                    ->has('versions')
                    ->where('aiProfile.name', 'Perfil de la empresa')
                    ->where('canManage', true));
        }
    }

    public function test_page_read_does_not_leak_another_tenants_configuration(): void
    {
        $this->seedConfigFor($this->otherTeam);

        $response = $this->assertNoTenantLeak(
            $this->team,
            fn () => $this->actingAs($this->user)->get(
                route('tenant-config.show', ['current_team' => $this->team->slug, 'seccion' => 'avanzado']),
            ),
        );

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->has('settings', 0)
            ->has('notificationPolicies', 0)
            ->has('escalationConfigs', 0)
            ->has('scheduleProfiles', 0)
            ->has('versions', 0)
            ->where('branding.displayName', null)
            ->where('recipientOptions.users', fn ($users) => collect($users)
                ->pluck('value')->all() === [(string) $this->user->id]));
    }

    public function test_saving_a_single_section_setting_only_touches_this_tenant(): void
    {
        // El otro tenant tiene la misma clave: guardar la sección Avisos (una
        // sola clave) no puede pisarla ni leerla.
        TenantSetting::factory()->create([
            'team_id' => $this->otherTeam->id,
            'setting_key' => 'notifications.out_of_band_min_severity',
            'setting_group' => 'notification',
            'value_type' => 'string',
            'value_json' => ['value' => 'low'],
        ]);

        $response = $this->assertNoTenantLeak(
            $this->team,
            fn () => $this->actingAs($this->user)->putJson(
                route('tenant-config.settings.update', ['current_team' => $this->team->slug]),
                ['settings' => [[
                    'setting_key' => 'notifications.out_of_band_min_severity',
                    'setting_group' => 'notification',
                    'value_type' => 'string',
                    'value' => 'high',
                ]]],
            ),
        );

        $response->assertOk();
        $this->assertSame('high', $this->settingValue($this->team, 'notifications.out_of_band_min_severity'));
        $this->assertSame('low', $this->settingValue($this->otherTeam, 'notifications.out_of_band_min_severity'));
    }

    public function test_advanced_fine_tuning_edits_this_tenants_setting_by_key(): void
    {
        foreach ([$this->team, $this->otherTeam] as $team) {
            TenantSetting::factory()->create([
                'team_id' => $team->id,
                'setting_key' => 'voice.call_attempts',
                'setting_group' => 'operational',
                'value_type' => 'number',
                'value_json' => ['value' => 3],
            ]);
        }

        $response = $this->assertNoTenantLeak(
            $this->team,
            fn () => $this->actingAs($this->user)->putJson(
                route('tenant-config.settings.update', ['current_team' => $this->team->slug]),
                ['settings' => [[
                    'setting_key' => 'voice.call_attempts',
                    'setting_group' => 'operational',
                    'value_type' => 'number',
                    'value' => 5,
                ]]],
            ),
        );

        $response->assertOk();
        $this->assertEquals(5, $this->settingValue($this->team, 'voice.call_attempts'));
        $this->assertEquals(3, $this->settingValue($this->otherTeam, 'voice.call_attempts'));
    }

    public function test_invalid_minimum_severity_explains_the_choice_in_plain_spanish(): void
    {
        $this->actingAs($this->user)
            ->putJson(
                route('tenant-config.settings.update', ['current_team' => $this->team->slug]),
                ['settings' => [[
                    'setting_key' => 'notifications.out_of_band_min_severity',
                    'setting_group' => 'notification',
                    'value_type' => 'string',
                    'value' => 'urgent',
                ]]],
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'settings.0.value' => 'Elige un nivel de gravedad válido: baja, media, alta o crítica.',
            ]);
    }

    private function seedConfigFor(Team $team): void
    {
        TenantSetting::factory()->create([
            'team_id' => $team->id,
            'setting_key' => 'voice.call_attempts',
            'value_json' => ['value' => 4],
        ]);
        TenantNotificationPolicy::factory()->create(['team_id' => $team->id]);
        TenantEscalationConfig::factory()->create(['team_id' => $team->id]);
        TenantScheduleProfile::factory()->create(['team_id' => $team->id]);
        TenantConfigVersion::factory()->create(['team_id' => $team->id, 'version' => 1]);
        TenantBranding::factory()->create(['team_id' => $team->id, 'display_name' => 'Otra empresa']);
    }

    private function settingValue(Team $team, string $key): mixed
    {
        return TenantSetting::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('setting_key', $key)
            ->firstOrFail()
            ->typed_value;
    }
}
