<?php

namespace Tests\Feature\Domains\TenantConfig;

use App\Domains\Access\Enums\RoleScope;
use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Decisions\Models\DecisionRule;
use App\Domains\Decisions\Models\RuleSet;
use App\Domains\TenantConfig\Models\TenantConfigVersion;
use App\Domains\TenantConfig\Models\TenantScheduleProfile;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TenantConfigApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);
    }

    public function test_settings_index_requires_config_view(): void
    {
        [$user, $team] = $this->createUserWithRole('no_perms', []);

        $response = $this->actingAs($user)->getJson("/api/{$team->slug}/settings/config");

        $response->assertForbidden();
    }

    public function test_admin_can_index_and_update_settings(): void
    {
        [$user, $team] = $this->createUserWithRole('cfg_admin', ['config.view', 'config.manage']);

        $update = $this->actingAs($user)->putJson("/api/{$team->slug}/settings/config", [
            'settings' => [
                [
                    'setting_key' => 'ai.confidence_threshold',
                    'setting_group' => 'ai',
                    'value_type' => 'number',
                    'value' => 0.8,
                ],
            ],
        ]);

        $update->assertOk();

        $list = $this->actingAs($user)->getJson("/api/{$team->slug}/settings/config");
        $list->assertOk();

        $this->assertSame(
            'ai.confidence_threshold',
            $list->json('data.ai.0.setting_key'),
        );
    }

    public function test_ai_profile_can_be_persisted_and_read(): void
    {
        [$user, $team] = $this->createUserWithRole('cfg_admin_ai', ['config.view', 'config.manage']);

        $put = $this->actingAs($user)->putJson("/api/{$team->slug}/settings/ai-profile", [
            'profile_code' => 'custom',
            'name' => 'Custom Profile',
            'risk_tolerance' => 'high',
            'false_positive_tolerance' => 'low',
            'automation_level' => 'semi_automatic',
            'media_strategy' => 'preferred',
        ]);

        $put->assertOk();

        $show = $this->actingAs($user)->getJson("/api/{$team->slug}/settings/ai-profile");
        $show->assertOk()
            ->assertJsonPath('data.profile_code', 'custom')
            ->assertJsonPath('data.automation_level', 'semi_automatic')
            ->assertJsonPath('data.is_persisted', true);

        $this->assertSame(
            1,
            TenantConfigVersion::withoutGlobalScopes()->where('team_id', $team->id)->count(),
            'Updating AI profile must create a config version snapshot',
        );
    }

    public function test_versions_endpoint_shows_history_and_individual_snapshot(): void
    {
        [$user, $team] = $this->createUserWithRole('cfg_admin_v', ['config.view', 'config.manage']);

        $this->actingAs($user)->putJson("/api/{$team->slug}/settings/ai-profile", [
            'profile_code' => 'v1',
            'name' => 'V1',
            'risk_tolerance' => 'medium',
            'false_positive_tolerance' => 'medium',
            'automation_level' => 'assisted',
            'media_strategy' => 'preferred',
        ])->assertOk();

        $list = $this->actingAs($user)->getJson("/api/{$team->slug}/settings/versions");
        $list->assertOk();

        $items = $list->json('data');
        $this->assertGreaterThanOrEqual(1, count($items));

        $first = $items[0];
        $detail = $this->actingAs($user)->getJson("/api/{$team->slug}/settings/versions/{$first['id']}");
        $detail->assertOk()->assertJsonPath('data.version', $first['version']);
    }

    public function test_rule_override_crud(): void
    {
        [$user, $team] = $this->createUserWithRole('cfg_rule_admin', ['config.view', 'config.manage']);

        $ruleset = RuleSet::factory()->create(['team_id' => $team->id]);
        DecisionRule::factory()->create([
            'team_id' => $team->id,
            'ruleset_id' => $ruleset->id,
            'code' => 'speed_violation',
        ]);

        $this->actingAs($user)->getJson("/api/{$team->slug}/settings/rules")->assertOk();

        $created = $this->actingAs($user)->postJson("/api/{$team->slug}/settings/rules", [
            'base_rule_code' => 'speed_violation',
            'override_type' => 'change_threshold',
            'override_config' => ['threshold_mph' => 80],
            'reason' => 'Highway fleet adjustment',
        ]);
        $created->assertCreated();
        $overrideId = $created->json('data.id');

        $this->actingAs($user)->putJson("/api/{$team->slug}/settings/rules/{$overrideId}", [
            'override_config' => ['threshold_mph' => 85],
            'is_active' => true,
        ])->assertOk();

        $this->actingAs($user)->deleteJson("/api/{$team->slug}/settings/rules/{$overrideId}")
            ->assertNoContent();
    }

    public function test_notification_policies_can_be_batch_updated(): void
    {
        [$user, $team] = $this->createUserWithRole('cfg_notif_admin', ['config.view', 'config.manage']);

        $update = $this->actingAs($user)->putJson("/api/{$team->slug}/settings/notifications", [
            'policies' => [
                [
                    'policy_code' => 'incident_default',
                    'notification_type' => 'incident_created',
                    'allowed_channels' => ['email', 'sms'],
                    'fallback_channels' => ['email'],
                ],
            ],
        ]);

        $update->assertOk();

        $this->actingAs($user)->getJson("/api/{$team->slug}/settings/notifications")
            ->assertOk()
            ->assertJsonPath('data.0.policy_code', 'incident_default');
    }

    public function test_escalation_config_crud(): void
    {
        [$user, $team] = $this->createUserWithRole('cfg_esc_admin', ['config.view', 'config.manage']);

        $this->actingAs($user)->getJson("/api/{$team->slug}/settings/escalation")->assertOk();

        $created = $this->actingAs($user)->postJson("/api/{$team->slug}/settings/escalation", [
            'escalation_type' => 'incident_critical',
            'trigger_conditions' => ['priority' => 'critical'],
            'steps' => [['delay_minutes' => 0, 'channels' => ['push']]],
        ]);
        $created->assertCreated();
        $configId = $created->json('data.id');

        $this->actingAs($user)->putJson("/api/{$team->slug}/settings/escalation/{$configId}", [
            'is_active' => false,
        ])->assertOk();
    }

    public function test_schedule_profile_can_be_listed_and_updated(): void
    {
        [$user, $team] = $this->createUserWithRole('cfg_sched_admin', ['config.view', 'config.manage']);

        $profile = TenantScheduleProfile::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'profile_code' => 'biz',
            'timezone' => 'UTC',
            'operating_hours_json' => [
                'monday' => ['start' => '08:00', 'end' => '18:00'],
            ],
            'is_active' => true,
        ]);

        $this->actingAs($user)->getJson("/api/{$team->slug}/settings/schedule")->assertOk();

        $update = $this->actingAs($user)->putJson("/api/{$team->slug}/settings/schedule/{$profile->id}", [
            'timezone' => 'America/Mexico_City',
            'after_hours_behavior' => ['suppress_low_priority' => true],
        ]);
        $update->assertOk()->assertJsonPath('data.timezone', 'America/Mexico_City');
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function malformedShiftRules(): array
    {
        return [
            'shift that is not an object' => [['on_call' => ['oops']], 'shift_rules.on_call.0'],
            'on_call that is not a list' => [['on_call' => 'oops'], 'shift_rules.on_call'],
            'nested days entry' => [['on_call' => [['user_id' => 5, 'days' => [['mon']]]]], 'shift_rules.on_call.0.days.0'],
            'unknown day name' => [['on_call' => [['user_id' => 5, 'days' => ['mon']]]], 'shift_rules.on_call.0.days.0'],
            'days that is not a list' => [['on_call' => [['user_id' => 5, 'days' => 'monday']]], 'shift_rules.on_call.0.days'],
            'missing user_id' => [['on_call' => [['days' => ['monday']]]], 'shift_rules.on_call.0.user_id'],
            'non-integer user_id' => [['on_call' => [['user_id' => 'abc']]], 'shift_rules.on_call.0.user_id'],
            'nested user_id' => [['on_call' => [['user_id' => [5]]]], 'shift_rules.on_call.0.user_id'],
            'numeric start' => [['on_call' => [['user_id' => 5, 'start' => 800, 'end' => '20:00']]], 'shift_rules.on_call.0.start'],
            'bad end format' => [['on_call' => [['user_id' => 5, 'start' => '08:00', 'end' => '8pm']]], 'shift_rules.on_call.0.end'],
            'non-integer fallback' => [['on_call' => [], 'fallback_on_call_user_id' => 'abc'], 'shift_rules.fallback_on_call_user_id'],
        ];
    }

    /**
     * @param  array<string, mixed>  $shiftRules
     */
    #[DataProvider('malformedShiftRules')]
    public function test_schedule_profile_update_rejects_malformed_on_call_shifts(array $shiftRules, string $errorKey): void
    {
        [$user, $team] = $this->createUserWithRole('cfg_sched_admin', ['config.view', 'config.manage']);

        $profile = TenantScheduleProfile::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'profile_code' => 'biz',
            'timezone' => 'UTC',
            'operating_hours_json' => [],
            'is_active' => true,
        ]);

        $this->actingAs($user)->putJson("/api/{$team->slug}/settings/schedule/{$profile->id}", [
            'shift_rules' => $shiftRules,
        ])->assertUnprocessable()->assertJsonValidationErrors([$errorKey]);

        $this->assertNull($profile->fresh()->shift_rules_json);
    }

    public function test_schedule_profile_update_accepts_well_formed_on_call_shifts(): void
    {
        [$user, $team] = $this->createUserWithRole('cfg_sched_admin', ['config.view', 'config.manage']);

        $profile = TenantScheduleProfile::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'profile_code' => 'biz',
            'timezone' => 'UTC',
            'operating_hours_json' => [],
            'is_active' => true,
        ]);

        // Lo que envía el editor de guardias: días completos en minúscula,
        // horas H:i (un turno nocturno cruza la medianoche) y respaldo.
        $rules = [
            'on_call' => [
                ['user_id' => $user->id, 'days' => ['monday', 'friday'], 'start' => '22:00', 'end' => '06:00'],
                ['user_id' => $user->id, 'days' => ['Saturday'], 'note' => 'claves extra del editor avanzado'],
                ['user_id' => $user->id],
            ],
            'fallback_on_call_user_id' => $user->id,
        ];

        $response = $this->actingAs($user)->putJson("/api/{$team->slug}/settings/schedule/{$profile->id}", [
            'shift_rules' => $rules,
        ])->assertOk();

        // jsonb reordena las claves: se compara sin depender del orden.
        $this->assertEquals($rules, $response->json('data.shift_rules_json'));
        $this->assertEquals($rules, $profile->fresh()->shift_rules_json);

        // Formato antiguo (lista de turnos sin persona, el del seeder de
        // vitrina): no es `on_call`, se sigue aceptando tal cual.
        $legacy = [['name' => 'Matutino', 'start' => '06:00', 'end' => '14:00']];

        $response = $this->actingAs($user)->putJson("/api/{$team->slug}/settings/schedule/{$profile->id}", [
            'shift_rules' => $legacy,
        ])->assertOk();

        $this->assertEquals($legacy, $response->json('data.shift_rules_json'));
    }

    public function test_viewer_can_read_but_not_update_or_create(): void
    {
        [$user, $team] = $this->createUserWithRole('cfg_viewer', ['config.view']);

        $this->actingAs($user)->getJson("/api/{$team->slug}/settings/config")->assertOk();
        $this->actingAs($user)->getJson("/api/{$team->slug}/settings/rules")->assertOk();
        $this->actingAs($user)->getJson("/api/{$team->slug}/settings/notifications")->assertOk();
        $this->actingAs($user)->getJson("/api/{$team->slug}/settings/ai-profile")->assertOk();
        $this->actingAs($user)->getJson("/api/{$team->slug}/settings/escalation")->assertOk();
        $this->actingAs($user)->getJson("/api/{$team->slug}/settings/schedule")->assertOk();
        $this->actingAs($user)->getJson("/api/{$team->slug}/settings/versions")->assertOk();

        $this->actingAs($user)->putJson("/api/{$team->slug}/settings/ai-profile", [
            'profile_code' => 'x',
            'name' => 'X',
            'risk_tolerance' => 'medium',
            'false_positive_tolerance' => 'medium',
            'automation_level' => 'assisted',
            'media_strategy' => 'preferred',
        ])->assertForbidden();

        // El código de regla debe existir para que el request pase la
        // validación (D-06) y la negativa provenga de la policy (403).
        $ruleset = RuleSet::factory()->create(['team_id' => $team->id]);
        DecisionRule::factory()->create([
            'team_id' => $team->id,
            'ruleset_id' => $ruleset->id,
            'code' => 'x',
        ]);

        $this->actingAs($user)->postJson("/api/{$team->slug}/settings/rules", [
            'base_rule_code' => 'x',
            'override_type' => 'disable_rule',
            'override_config' => ['noop' => true],
        ])->assertForbidden();

        $this->actingAs($user)->postJson("/api/{$team->slug}/settings/escalation", [
            'escalation_type' => 'x',
            'trigger_conditions' => ['priority' => 'low'],
            'steps' => [['delay_minutes' => 0, 'channels' => ['push']]],
        ])->assertForbidden();

        $this->actingAs($user)->putJson("/api/{$team->slug}/settings/notifications", [
            'policies' => [
                ['policy_code' => 'x', 'allowed_channels' => ['email']],
            ],
        ])->assertForbidden();
    }

    /**
     * @param  array<string>  $permissionCodes
     * @return array{0: User, 1: Team}
     */
    private function createUserWithRole(string $roleCode, array $permissionCodes): array
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $role = Role::factory()->create([
            'code' => $roleCode,
            'scope' => RoleScope::Tenant,
        ]);

        $permissionIds = [];
        foreach ($permissionCodes as $code) {
            $permission = Permission::firstOrCreate(
                ['code' => $code],
                [
                    'name' => ucfirst(str_replace('.', ' ', $code)),
                    'module' => explode('.', $code, 2)[0],
                ],
            );
            $permissionIds[] = $permission->id;
        }
        $role->permissions()->sync($permissionIds);

        $team->members()->updateExistingPivot($user->id, [
            'role' => TeamRole::Member->value,
            'role_id' => $role->id,
        ]);

        return [$user, $team];
    }
}
