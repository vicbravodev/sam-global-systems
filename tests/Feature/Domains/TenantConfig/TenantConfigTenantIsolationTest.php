<?php

namespace Tests\Feature\Domains\TenantConfig;

use App\Domains\TenantConfig\Enums\AutomationLevel;
use App\Domains\TenantConfig\Enums\FalsePositiveTolerance;
use App\Domains\TenantConfig\Enums\MediaStrategy;
use App\Domains\TenantConfig\Enums\RiskTolerance;
use App\Domains\TenantConfig\Models\TenantAIProfile;
use App\Domains\TenantConfig\Models\TenantSetting;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantConfigTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);
    }

    public function test_tenant_settings_are_scoped_by_global_scope(): void
    {
        $userA = User::factory()->create();
        $teamA = $userA->currentTeam;

        $userB = User::factory()->create();
        $teamB = $userB->currentTeam;

        TenantSetting::withoutGlobalScopes()->create([
            'team_id' => $teamA->id,
            'setting_key' => 'ai.confidence_threshold',
            'setting_group' => 'ai',
            'value_json' => ['value' => 0.75],
            'value_type' => 'number',
            'version' => 1,
            'is_active' => true,
            'updated_by_type' => 'system',
            'updated_by_id' => null,
        ]);

        TenantSetting::withoutGlobalScopes()->create([
            'team_id' => $teamB->id,
            'setting_key' => 'ai.confidence_threshold',
            'setting_group' => 'ai',
            'value_json' => ['value' => 0.9],
            'value_type' => 'number',
            'version' => 1,
            'is_active' => true,
            'updated_by_type' => 'system',
            'updated_by_id' => null,
        ]);

        $this->actingAs($userA);

        $this->assertSame(1, TenantSetting::count());
        $this->assertSame(2, TenantSetting::withoutGlobalScopes()->count());

        $this->assertSame(
            $teamA->id,
            TenantSetting::first()->team_id,
            'TenantSetting global scope must restrict to the current team only.',
        );
    }

    public function test_ai_profile_endpoint_isolates_across_tenants(): void
    {
        $userA = User::factory()->create();
        $teamA = $userA->currentTeam;
        $teamB = User::factory()->create()->currentTeam;

        TenantAIProfile::withoutGlobalScopes()->create([
            'team_id' => $teamB->id,
            'profile_code' => 'team_b',
            'name' => 'Team B Profile',
            'risk_tolerance' => RiskTolerance::High,
            'false_positive_tolerance' => FalsePositiveTolerance::Low,
            'automation_level' => AutomationLevel::HighlyAutomated,
            'media_strategy' => MediaStrategy::WaitBeforeDeciding,
            'is_active' => true,
        ]);

        $this->actingAs($userA);

        $response = $this->getJson("/api/{$teamA->slug}/settings/ai-profile");

        $response->assertOk();

        $this->assertNotEquals(
            'highly_automated',
            $response->json('data.automation_level'),
            'TeamA must NOT see TeamB AI profile',
        );

        $this->assertSame(
            'system_default',
            $response->json('data.profile_code'),
            'No persisted profile for teamA -> default profile returned',
        );

        $this->assertFalse(
            $response->json('data.is_persisted'),
            'Defaults must report is_persisted=false',
        );
    }

    public function test_cross_tenant_setting_not_visible_via_global_scope(): void
    {
        $userA = User::factory()->create();
        $teamA = $userA->currentTeam;
        $teamB = Team::factory()->create();

        TenantSetting::withoutGlobalScopes()->create([
            'team_id' => $teamB->id,
            'setting_key' => 'ai.foo',
            'setting_group' => 'ai',
            'value_json' => ['value' => 1],
            'value_type' => 'number',
            'version' => 1,
            'is_active' => true,
            'updated_by_type' => 'system',
            'updated_by_id' => null,
        ]);

        $this->actingAs($userA);

        $this->assertSame(0, TenantSetting::count(), 'Global scope must hide other-tenant rows');
    }
}
