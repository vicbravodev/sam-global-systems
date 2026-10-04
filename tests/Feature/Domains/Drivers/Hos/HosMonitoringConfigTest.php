<?php

namespace Tests\Feature\Domains\Drivers\Hos;

use App\Domains\Access\Enums\RoleScope;
use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Assets\Models\Asset;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Drivers\Actions\ResolveHosMonitoringConfig;
use App\Domains\Drivers\Enums\HosSituation;
use App\Domains\Drivers\Support\HosMonitoringConfig;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Tenancy\Models\TenantFeature;
use App\Domains\TenantConfig\Enums\SettingGroup;
use App\Domains\TenantConfig\Enums\SettingValueType;
use App\Domains\TenantConfig\Models\TenantConfigVersion;
use App\Domains\TenantConfig\Models\TenantSetting;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

class HosMonitoringConfigTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);
        $this->user = User::factory()->create();
        $this->team = $this->user->currentTeam;
        $this->enable($this->team);
    }

    private function enable(Team $team): void
    {
        TenantFeature::factory()->create(['team_id' => $team->id, 'feature_key' => HosMonitoringConfig::FEATURE_KEY, 'enabled' => true]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'tag_ids' => ['4738197'],
            'included_asset_ids' => [],
            'excluded_asset_ids' => [],
            'situations' => ['break_due' => true, 'drive_limit' => true, 'shift_limit' => true, 'cycle_limit' => false, 'rest_complete' => true],
            'lead_minutes' => [45, 15, 0],
            'cycle_lead_hours' => [6, 2],
            'rest_complete_nudge_minutes' => [10, 25],
            'rest_complete_expire_minutes' => 30,
            'ladder' => [
                ['after_minutes' => 0, 'channels' => ['samsara_driver_app'], 'escalate' => null],
                ['after_minutes' => 4, 'channels' => ['samsara_driver_app', 'sms'], 'escalate' => null],
                ['after_minutes' => 8, 'channels' => [], 'escalate' => 'incident'],
            ],
        ], $overrides);
    }

    private function url(): string
    {
        return route('tenant-config.hos.update', ['current_team' => $this->team->slug]);
    }

    /**
     * @param  list<string>  $permissionCodes
     * @return array{0: User, 1: Team}
     */
    private function userWithRole(array $permissionCodes): array
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $role = Role::factory()->create(['code' => 'hos_cfg_'.$user->id, 'scope' => RoleScope::Tenant]);
        $role->permissions()->sync(array_map(fn (string $code): int => Permission::firstOrCreate(
            ['code' => $code],
            ['name' => $code, 'module' => explode('.', $code, 2)[0]],
        )->id, $permissionCodes));
        $team->members()->updateExistingPivot($user->id, ['role' => TeamRole::Member->value, 'role_id' => $role->id]);

        return [$user, $team];
    }

    public function test_the_settings_page_carries_the_hos_form_with_the_feature(): void
    {
        $asset = Asset::factory()->create(['team_id' => $this->team->id, 'name' => 'T-0321']);
        NotificationChannel::factory()->samsaraDriverApp()->create();

        $this->actingAs($this->user)
            ->get(route('tenant-config.show', ['current_team' => $this->team->slug, 'seccion' => 'hos']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/tenant-config')
                ->where('hos.canManage', true)
                ->where('hos.hasIntegration', false)
                ->where('hos.minGapMinutes', 2)
                ->where('hos.config.leadMinutes', [30, 15])
                ->where('hos.config.situations.cycle_limit', true)
                ->where('hos.config.ladder.3', ['afterMinutes' => 15, 'channels' => [], 'escalate' => true])
                ->where('hos.assets.0', ['id' => $asset->id, 'name' => 'T-0321', 'code' => $asset->code, 'monitored' => true])
                ->where('hos.channels', fn ($channels) => collect($channels)->firstWhere('value', 'samsara_driver_app')['available'] === true
                    && collect($channels)->firstWhere('value', 'voice')['available'] === false)
            );
    }

    public function test_without_the_feature_the_form_is_null_and_the_endpoints_answer_403(): void
    {
        TenantFeature::withoutGlobalScopes()->where('team_id', $this->team->id)->update(['enabled' => false]);

        $this->actingAs($this->user)
            ->get(route('tenant-config.show', ['current_team' => $this->team->slug, 'seccion' => 'hos']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('hos', null));

        $this->actingAs($this->user)->putJson($this->url(), $this->payload())->assertForbidden();
        $this->actingAs($this->user)->getJson("/api/{$this->team->slug}/settings/hos")->assertForbidden();
        $this->actingAs($this->user)->putJson("/api/{$this->team->slug}/settings/hos", $this->payload())->assertForbidden();
        $this->assertDatabaseMissing('tenant_settings', ['setting_key' => HosMonitoringConfig::SETTING_KEY]);
    }

    public function test_a_viewer_reads_the_form_but_cannot_save(): void
    {
        [$viewer, $team] = $this->userWithRole(['config.view']);
        $this->enable($team);

        $this->actingAs($viewer)
            ->get(route('tenant-config.show', ['current_team' => $team->slug, 'seccion' => 'hos']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('hos.canManage', false));

        $this->actingAs($viewer)
            ->putJson(route('tenant-config.hos.update', ['current_team' => $team->slug]), $this->payload())
            ->assertForbidden();
    }

    public function test_saving_stores_the_canonical_config_audits_and_logs_it(): void
    {
        $this->actingAs($this->user)
            ->putJson($this->url(), $this->payload(['lead_minutes' => ['15', 45, 0]]))
            ->assertOk()
            ->assertJsonPath('data.config.leadMinutes', [45, 15])
            ->assertJsonPath('data.config.situations.cycle_limit', false);

        $setting = TenantSetting::withoutGlobalScopes()
            ->where('team_id', $this->team->id)
            ->where('setting_key', HosMonitoringConfig::SETTING_KEY)
            ->sole();
        $this->assertSame(SettingGroup::Compliance, $setting->setting_group);
        $this->assertSame([45, 15, 0], $setting->value_json['lead_minutes']);
        $this->assertSame(['after_minutes' => 4, 'channels' => ['samsara_driver_app', 'sms']], $setting->value_json['ladder'][1]);
        $this->assertSame(['after_minutes' => 8, 'escalate' => 'incident'], $setting->value_json['ladder'][2]);
        $this->assertFalse($setting->value_json['situations']['cycle_limit']);

        // Lo que guarda la pantalla es lo que lee el sondeo.
        $config = app(ResolveHosMonitoringConfig::class)->execute($this->team->id);
        $this->assertSame(['4738197'], $config->tagIds);
        $this->assertSame(2700, $config->leadSeconds());
        $this->assertFalse($config->enabled(HosSituation::CycleLimit));

        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('team_id', $this->team->id)->where('action', 'hos.config.updated')->where('actor_id', $this->user->id)->count());
        $this->assertTrue(TenantConfigVersion::withoutGlobalScopes()->where('team_id', $this->team->id)->exists());

        $log = $this->assertSystemLogged('hos.config.updated');
        $this->assertSame(1, $log['calc']['tag_ids_count']);
        $this->assertSame(3, $log['calc']['ladder_steps_count']);
        $this->assertTrue($log['calc']['ladder_escalates']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_each_save_leaves_its_own_audit_entry(): void
    {
        $this->actingAs($this->user)->putJson($this->url(), $this->payload())->assertOk();
        $this->actingAs($this->user)->putJson($this->url(), $this->payload(['tag_ids' => []]))->assertOk();

        $this->assertSame(2, AuditLog::withoutGlobalScopes()->where('team_id', $this->team->id)->where('action', 'hos.config.updated')->count());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidPayloads(): array
    {
        $situations = ['break_due' => true, 'drive_limit' => true, 'shift_limit' => true, 'cycle_limit' => true, 'rest_complete' => true];

        return [
            'switch como texto' => [['situations' => ['break_due' => 'false'] + $situations], 'situations.break_due'],
            'situación desconocida' => [['situations' => $situations + ['violation' => false]], 'situations'],
            'minutos negativos' => [['lead_minutes' => [30, -5, 0]], 'lead_minutes.1'],
            'horas de ciclo en cero' => [['cycle_lead_hours' => [0]], 'cycle_lead_hours.0'],
            'etiqueta como número' => [['tag_ids' => [4738197]], 'tag_ids.0'],
            'canal que el chofer no tiene' => [['ladder' => [['after_minutes' => 0, 'channels' => ['email'], 'escalate' => null]]], 'ladder.0.channels.0'],
            'primer escalón fuera de cero' => [['ladder' => [['after_minutes' => 3, 'channels' => ['sms'], 'escalate' => null]]], 'ladder.0.after_minutes'],
            'escalones a menos de 2 min' => [['ladder' => [
                ['after_minutes' => 0, 'channels' => ['sms'], 'escalate' => null],
                ['after_minutes' => 1, 'channels' => ['voice'], 'escalate' => null],
            ]], 'ladder.1.after_minutes'],
            'escalones desordenados' => [['ladder' => [
                ['after_minutes' => 0, 'channels' => ['sms'], 'escalate' => null],
                ['after_minutes' => 10, 'channels' => ['voice'], 'escalate' => null],
                ['after_minutes' => 5, 'channels' => ['whatsapp'], 'escalate' => null],
            ]], 'ladder.2.after_minutes'],
            'incidente a la mitad' => [['ladder' => [
                ['after_minutes' => 0, 'channels' => ['sms'], 'escalate' => null],
                ['after_minutes' => 5, 'channels' => [], 'escalate' => 'incident'],
                ['after_minutes' => 10, 'channels' => ['voice'], 'escalate' => null],
            ]], 'ladder.1.escalate'],
            'dos incidentes' => [['ladder' => [
                ['after_minutes' => 0, 'channels' => ['sms'], 'escalate' => null],
                ['after_minutes' => 5, 'channels' => [], 'escalate' => 'incident'],
                ['after_minutes' => 10, 'channels' => [], 'escalate' => 'incident'],
            ]], 'ladder.1.escalate'],
            'incidente con canales' => [['ladder' => [
                ['after_minutes' => 0, 'channels' => ['sms'], 'escalate' => null],
                ['after_minutes' => 5, 'channels' => ['voice'], 'escalate' => 'incident'],
            ]], 'ladder.1.channels'],
            'escalón sin canales' => [['ladder' => [
                ['after_minutes' => 0, 'channels' => ['sms'], 'escalate' => null],
                ['after_minutes' => 5, 'channels' => [], 'escalate' => null],
            ]], 'ladder.1.channels'],
            'sólo incidente' => [['ladder' => [['after_minutes' => 0, 'channels' => [], 'escalate' => 'incident']]], 'ladder'],
            'llave extra en un escalón' => [['ladder' => [['after_minutes' => 0, 'channels' => ['sms'], 'escalate' => null, 'retry_minutes' => 3]]], 'ladder.0'],
            'fin de recordatorios antes del último' => [['rest_complete_nudge_minutes' => [15, 30], 'rest_complete_expire_minutes' => 30], 'rest_complete_expire_minutes'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidPayloads')]
    public function test_invalid_values_are_rejected_without_saving(array $overrides, string $errorKey): void
    {
        $this->actingAs($this->user)
            ->putJson($this->url(), $this->payload($overrides))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$errorKey]);

        $this->assertDatabaseMissing('tenant_settings', ['setting_key' => HosMonitoringConfig::SETTING_KEY]);
    }

    public function test_units_must_belong_to_the_team_and_cannot_be_both_included_and_excluded(): void
    {
        $mine = Asset::factory()->create(['team_id' => $this->team->id]);
        $foreign = Asset::factory()->create();

        $this->actingAs($this->user)
            ->putJson($this->url(), $this->payload(['included_asset_ids' => [$foreign->id]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['included_asset_ids.0']);

        $this->actingAs($this->user)
            ->putJson($this->url(), $this->payload(['included_asset_ids' => [$mine->id], 'excluded_asset_ids' => [$mine->id]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['excluded_asset_ids']);
    }

    public function test_the_form_omits_deleted_units_and_legacy_channels_so_it_saves_as_presented(): void
    {
        $live = Asset::factory()->create(['team_id' => $this->team->id]);
        $gone = Asset::factory()->create(['team_id' => $this->team->id]);
        $goneExcluded = Asset::factory()->create(['team_id' => $this->team->id]);
        $foreign = Asset::factory()->create();
        $gone->delete();
        $goneExcluded->delete();

        TenantSetting::factory()->create([
            'team_id' => $this->team->id,
            'setting_key' => HosMonitoringConfig::SETTING_KEY,
            'setting_group' => SettingGroup::Compliance,
            'value_type' => SettingValueType::Json,
            'value_json' => [
                'tag_ids' => ['4738197'],
                'included_asset_ids' => [$live->id, $gone->id, $foreign->id],
                'excluded_asset_ids' => [$goneExcluded->id],
                'ladder' => [
                    ['after_minutes' => 0, 'channels' => ['email']],
                    ['after_minutes' => 5, 'channels' => ['samsara_driver_app', 'email']],
                    ['after_minutes' => 10, 'escalate' => 'incident'],
                ],
            ],
        ]);

        $form = $this->actingAs($this->user)
            ->getJson("/api/{$this->team->slug}/settings/hos")
            ->assertOk()
            ->json('data.config');

        $this->assertSame([$live->id], $form['includedAssetIds']);
        $this->assertSame([], $form['excludedAssetIds']);
        // El escalón que sólo avisaba por correo desaparece y el primero vuelve a salir en el límite.
        $this->assertSame([
            ['afterMinutes' => 0, 'channels' => ['samsara_driver_app'], 'escalate' => false],
            ['afterMinutes' => 10, 'channels' => [], 'escalate' => true],
        ], $form['ladder']);

        // Lo que la pantalla presenta se guarda tal cual (como serializeDraft).
        $this->actingAs($this->user)
            ->putJson($this->url(), [
                'tag_ids' => $form['tagIds'],
                'included_asset_ids' => $form['includedAssetIds'],
                'excluded_asset_ids' => $form['excludedAssetIds'],
                'situations' => $form['situations'],
                'lead_minutes' => [...$form['leadMinutes'], 0],
                'cycle_lead_hours' => $form['cycleLeadHours'],
                'rest_complete_nudge_minutes' => $form['restCompleteNudgeMinutes'],
                'rest_complete_expire_minutes' => $form['restCompleteExpireMinutes'],
                'ladder' => array_map(fn (array $step): array => [
                    'after_minutes' => $step['afterMinutes'],
                    'channels' => $step['channels'],
                    'escalate' => $step['escalate'] ? 'incident' : null,
                ], $form['ladder']),
            ])
            ->assertOk();
    }

    public function test_unit_and_channel_errors_read_as_text_without_field_keys(): void
    {
        $foreign = Asset::factory()->create();

        $errors = $this->actingAs($this->user)
            ->putJson($this->url(), $this->payload([
                'included_asset_ids' => [$foreign->id],
                'excluded_asset_ids' => [$foreign->id + 1000],
                'ladder' => [
                    ['after_minutes' => 0, 'channels' => ['email'], 'escalate' => null],
                    ['after_minutes' => 4, 'channels' => [], 'escalate' => 'incident'],
                ],
            ]))
            ->assertUnprocessable()
            ->json('errors');

        foreach (['included_asset_ids.0', 'excluded_asset_ids.0', 'ladder.0.channels.0'] as $key) {
            $this->assertArrayHasKey($key, $errors);
            $this->assertStringNotContainsString('_ids', $errors[$key][0]);
            $this->assertStringNotContainsString('ladder', $errors[$key][0]);
        }
    }

    public function test_saving_never_touches_another_tenant(): void
    {
        $other = User::factory()->create()->currentTeam;
        TenantSetting::factory()->create([
            'team_id' => $other->id,
            'setting_key' => HosMonitoringConfig::SETTING_KEY,
            'setting_group' => SettingGroup::Compliance,
            'value_type' => SettingValueType::Json,
            'value_json' => ['tag_ids' => ['1']],
        ]);

        $this->assertNoTenantLeak($this->team, fn () => $this->actingAs($this->user)->putJson($this->url(), $this->payload())->assertOk());
    }

    public function test_the_api_mirrors_reading_and_saving(): void
    {
        $this->actingAs($this->user)
            ->getJson("/api/{$this->team->slug}/settings/hos")
            ->assertOk()
            ->assertJsonPath('data.config.leadMinutes', [30, 15]);

        $this->actingAs($this->user)
            ->putJson("/api/{$this->team->slug}/settings/hos", $this->payload())
            ->assertOk()
            ->assertJsonPath('data.config.tagIds', ['4738197']);
    }

    public function test_the_generic_settings_endpoint_cannot_write_the_hos_config(): void
    {
        $this->actingAs($this->user)
            ->putJson(route('tenant-config.settings.update', ['current_team' => $this->team->slug]), ['settings' => [[
                'setting_key' => HosMonitoringConfig::SETTING_KEY,
                'setting_group' => 'compliance',
                'value_type' => 'json',
                'value' => ['lead_minutes' => [-5]],
            ]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['settings.0.setting_key']);
    }
}
