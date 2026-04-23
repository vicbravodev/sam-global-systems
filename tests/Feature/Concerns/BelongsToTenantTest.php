<?php

namespace Tests\Feature\Concerns;

use App\Domains\Tenancy\Enums\FeatureSource;
use App\Domains\Tenancy\Models\TenantFeature;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BelongsToTenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_scope_filters_models_by_current_team(): void
    {
        $userA = User::factory()->create();
        $teamA = $userA->currentTeam;

        $teamB = Team::factory()->create();

        TenantFeature::withoutGlobalScopes()->create([
            'team_id' => $teamA->id,
            'feature_key' => 'incidents',
            'enabled' => true,
            'source' => FeatureSource::DefaultPlan,
        ]);

        TenantFeature::withoutGlobalScopes()->create([
            'team_id' => $teamB->id,
            'feature_key' => 'incidents',
            'enabled' => true,
            'source' => FeatureSource::DefaultPlan,
        ]);

        $this->actingAs($userA);

        $this->assertEquals(1, TenantFeature::query()->count());
        $this->assertEquals($teamA->id, TenantFeature::query()->first()->team_id);
    }

    public function test_global_scope_is_not_applied_when_no_current_team(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();

        TenantFeature::withoutGlobalScopes()->create([
            'team_id' => $teamA->id,
            'feature_key' => 'assets',
            'enabled' => true,
            'source' => FeatureSource::DefaultPlan,
        ]);

        TenantFeature::withoutGlobalScopes()->create([
            'team_id' => $teamB->id,
            'feature_key' => 'assets',
            'enabled' => true,
            'source' => FeatureSource::DefaultPlan,
        ]);

        // No authenticated user → currentTeam() is null → scope is a no-op
        $this->assertEquals(2, TenantFeature::query()->count());
    }

    public function test_creating_sets_team_id_from_current_team_when_not_provided(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $this->actingAs($user);

        $feature = TenantFeature::create([
            'feature_key' => 'ai',
            'enabled' => true,
            'source' => FeatureSource::DefaultPlan,
        ]);

        $this->assertEquals($team->id, $feature->team_id);
    }

    public function test_creating_does_not_override_explicit_team_id(): void
    {
        $user = User::factory()->create();
        $otherTeam = Team::factory()->create();

        $this->actingAs($user);

        $feature = TenantFeature::withoutGlobalScopes()->create([
            'team_id' => $otherTeam->id,
            'feature_key' => 'automation',
            'enabled' => true,
            'source' => FeatureSource::DefaultPlan,
        ]);

        $this->assertEquals($otherTeam->id, $feature->team_id);
    }

    public function test_team_relation_returns_the_owning_team(): void
    {
        $team = Team::factory()->create();

        $feature = TenantFeature::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'feature_key' => 'drivers',
            'enabled' => false,
            'source' => FeatureSource::ManualOverride,
        ]);

        $this->assertEquals($team->id, $feature->team->id);
    }
}
