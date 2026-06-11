<?php

namespace Tests\Feature\Domains\TenantConfig;

use App\Domains\Decisions\Models\DecisionRule;
use App\Domains\Decisions\Models\RuleSet;
use App\Domains\Tenancy\Actions\CreateTenant;
use App\Domains\TenantConfig\Actions\ApplyDefaultTenantConfig;
use App\Domains\TenantConfig\Models\TenantConfigVersion;
use App\Domains\TenantConfig\Models\TenantEscalationConfig;
use App\Domains\TenantConfig\Models\TenantSetting;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Database\Seeders\DecisionOutcomeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Roadmap V2-A5: the SAM Default Config Pack — factory configuration for
 * every tenant, idempotent and never overwriting tenant-owned values.
 */
class ApplyDefaultTenantConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DecisionOutcomeSeeder::class);
    }

    private function apply(Team $team): array
    {
        return app(ApplyDefaultTenantConfig::class)->execute($team);
    }

    public function test_applies_settings_rules_escalation_and_labeled_snapshot(): void
    {
        $team = Team::factory()->create();

        $summary = $this->apply($team);

        $this->assertSame(count(ApplyDefaultTenantConfig::defaultSettings()), $summary['settings_created']);
        $this->assertSame(4, $summary['rules_created']);
        $this->assertTrue($summary['escalation_created']);
        $this->assertSame(1, $summary['snapshot_version']);

        // The protocol ships ON: media auto-request and voice verification.
        $this->assertTrue(TenantSetting::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('setting_key', 'media.auto_request_on_critical')
            ->sole()->typed_value);
        $this->assertTrue(TenantSetting::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('setting_key', 'voice.verification_enabled')
            ->sole()->typed_value);

        $ruleSet = RuleSet::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('code', ApplyDefaultTenantConfig::RULESET_CODE)
            ->sole();
        $this->assertTrue((bool) $ruleSet->is_default);

        // Both panic rules ship ACTIVE (the false-alarm rule keeps its
        // anti-coercion conditions: resolved AND parked at base only).
        $rules = DecisionRule::withoutGlobalScopes()
            ->where('ruleset_id', $ruleSet->id)
            ->get()
            ->keyBy('code');
        $this->assertTrue((bool) $rules['panic-button-always-incident']->is_active);
        $this->assertTrue((bool) $rules['panic-false-alarm-review']->is_active);
        $this->assertTrue((bool) $rules['after-hours-movement-incident']->is_active);
        $this->assertTrue((bool) $rules['suspicious-stop-review']->is_active);

        $escalation = TenantEscalationConfig::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->sole();
        $this->assertSame(['voice', 'push', 'web'], $escalation->steps_json[0]['channels']);
        $this->assertSame(2, $escalation->steps_json[0]['attempts']);

        $version = TenantConfigVersion::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->sole();
        $this->assertSame('sam-default-v1', $version->snapshot_json['label']);
    }

    public function test_is_idempotent_on_rerun(): void
    {
        $team = Team::factory()->create();

        $this->apply($team);
        $summary = $this->apply($team);

        $this->assertSame(0, $summary['settings_created']);
        $this->assertSame(0, $summary['rules_created']);
        $this->assertFalse($summary['escalation_created']);
        $this->assertNull($summary['snapshot_version']);

        $this->assertSame(1, TenantConfigVersion::withoutGlobalScopes()->where('team_id', $team->id)->count());
        $this->assertSame(
            count(ApplyDefaultTenantConfig::defaultSettings()),
            TenantSetting::withoutGlobalScopes()->where('team_id', $team->id)->count(),
        );
    }

    public function test_never_overwrites_a_tenant_modified_setting(): void
    {
        $team = Team::factory()->create();

        TenantSetting::factory()->create([
            'team_id' => $team->id,
            'setting_key' => 'voice.call_attempts',
            'value_json' => ['value' => 5],
        ]);

        $this->apply($team);

        $setting = TenantSetting::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('setting_key', 'voice.call_attempts')
            ->sole();

        $this->assertSame(5, $setting->typed_value);
    }

    public function test_skips_rules_when_the_tenant_already_owns_a_ruleset(): void
    {
        $team = Team::factory()->create();

        RuleSet::factory()->create(['team_id' => $team->id]);

        $summary = $this->apply($team);

        $this->assertSame(0, $summary['rules_created']);
        $this->assertSame(0, RuleSet::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('code', ApplyDefaultTenantConfig::RULESET_CODE)
            ->count());
    }

    public function test_does_not_touch_other_tenants(): void
    {
        $team = Team::factory()->create();
        $other = Team::factory()->create();

        $this->apply($team);

        $this->assertSame(0, TenantSetting::withoutGlobalScopes()->where('team_id', $other->id)->count());
        $this->assertSame(0, RuleSet::withoutGlobalScopes()->where('team_id', $other->id)->count());
        $this->assertSame(0, TenantEscalationConfig::withoutGlobalScopes()->where('team_id', $other->id)->count());
    }

    public function test_create_tenant_applies_the_pack_via_listener(): void
    {
        $owner = User::factory()->create();

        $team = app(CreateTenant::class)->execute('Flota Norte', $owner);

        $this->assertSame(
            count(ApplyDefaultTenantConfig::defaultSettings()),
            TenantSetting::withoutGlobalScopes()->where('team_id', $team->id)->count(),
        );
        $this->assertSame(1, RuleSet::withoutGlobalScopes()->where('team_id', $team->id)->count());
    }

    public function test_console_command_applies_by_slug(): void
    {
        $team = Team::factory()->create();

        $this->artisan('tenants:apply-default-config', ['team' => $team->slug])
            ->assertExitCode(0);

        $this->assertSame(
            count(ApplyDefaultTenantConfig::defaultSettings()),
            TenantSetting::withoutGlobalScopes()->where('team_id', $team->id)->count(),
        );
    }

    public function test_console_command_requires_a_target(): void
    {
        $this->artisan('tenants:apply-default-config')->assertExitCode(1);
    }

    public function test_web_endpoint_applies_defaults_with_permission(): void
    {
        $this->seed(AccessSeeder::class);

        $user = User::factory()->create();
        $team = $user->currentTeam;

        $response = $this->actingAs($user)->post(
            route('tenant-config.apply-sam-defaults', ['current_team' => $team->slug]),
        );

        $response->assertRedirect();

        $this->assertSame(
            count(ApplyDefaultTenantConfig::defaultSettings()),
            TenantSetting::withoutGlobalScopes()->where('team_id', $team->id)->count(),
        );
    }
}
