<?php

namespace Tests\Feature\Domains\Tenancy;

use App\Domains\Tenancy\Actions\SetTenantFeature;
use App\Domains\Tenancy\Enums\FeatureSource;
use App\Domains\Tenancy\Models\TenantFeature;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetTenantFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_manual_override_feature(): void
    {
        $team = Team::factory()->create(['is_personal' => false]);

        app(SetTenantFeature::class)->execute($team, 'live_map', true, ['included_quantity' => 10]);

        $feature = TenantFeature::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('feature_key', 'live_map')
            ->firstOrFail();

        $this->assertTrue($feature->enabled);
        $this->assertSame(FeatureSource::ManualOverride, $feature->source);
        $this->assertSame(10, (int) $feature->limits_json['included_quantity']);
    }

    public function test_toggling_without_limits_preserves_existing_limits(): void
    {
        $team = Team::factory()->create(['is_personal' => false]);

        TenantFeature::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'feature_key' => 'monitored_assets',
            'enabled' => true,
            'source' => FeatureSource::DefaultPlan,
            'limits_json' => ['included_quantity' => 99],
        ]);

        // Disable without passing limits → limit must survive.
        app(SetTenantFeature::class)->execute($team, 'monitored_assets', false);

        $feature = TenantFeature::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('feature_key', 'monitored_assets')
            ->firstOrFail();

        $this->assertFalse($feature->enabled);
        $this->assertSame(99, (int) $feature->limits_json['included_quantity']);
        $this->assertSame(FeatureSource::ManualOverride, $feature->source);
    }
}
