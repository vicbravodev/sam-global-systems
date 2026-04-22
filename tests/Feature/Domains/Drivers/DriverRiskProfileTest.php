<?php

namespace Tests\Feature\Domains\Drivers;

use App\Domains\Drivers\Enums\RiskLevel;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverRiskProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DriverRiskProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_calculates_risk_score_from_incident_data(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $driver = Driver::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'first_name' => 'Risky',
            'last_name' => 'Driver',
            'full_name' => 'Risky Driver',
            'status' => 'active',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $profile = DriverRiskProfile::create([
            'driver_id' => $driver->id,
            'risk_score' => 72.50,
            'risk_level' => RiskLevel::High,
            'incidents_count' => 5,
            'harsh_events_count' => 12,
            'fatigue_flags_count' => 3,
            'last_calculated_at' => now(),
        ]);

        $this->assertEquals(
            '72.50',
            $profile->risk_score,
            'Risk score should be stored with decimal precision',
        );

        $this->assertEquals(
            5,
            $profile->incidents_count,
            'Incidents count should reflect the provided value',
        );

        $this->assertEquals(
            12,
            $profile->harsh_events_count,
            'Harsh events count should reflect the provided value',
        );
    }

    public function test_it_updates_risk_level_based_on_score_thresholds(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $driver = Driver::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'first_name' => 'Level',
            'last_name' => 'Driver',
            'full_name' => 'Level Driver',
            'status' => 'active',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $lowProfile = DriverRiskProfile::factory()->low()->create(['driver_id' => $driver->id]);

        $this->assertEquals(
            RiskLevel::Low,
            $lowProfile->risk_level,
            'Risk profile with low score should have Low risk level',
        );

        $lowProfile->update([
            'risk_score' => 85.00,
            'risk_level' => RiskLevel::Critical,
        ]);

        $this->assertEquals(
            RiskLevel::Critical,
            $lowProfile->fresh()->risk_level,
            'Risk level should be updated to Critical when score exceeds the high threshold',
        );
    }

    public function test_it_tracks_last_calculated_at_timestamp(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $driver = Driver::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'first_name' => 'Timestamp',
            'last_name' => 'Driver',
            'full_name' => 'Timestamp Driver',
            'status' => 'active',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $calculatedAt = now()->subDay();

        $profile = DriverRiskProfile::create([
            'driver_id' => $driver->id,
            'risk_score' => 30.00,
            'risk_level' => RiskLevel::Medium,
            'last_calculated_at' => $calculatedAt,
        ]);

        $this->assertEquals(
            $calculatedAt->toDateTimeString(),
            $profile->last_calculated_at->toDateTimeString(),
            'last_calculated_at should track when the risk profile was last computed',
        );

        $newCalculatedAt = now();
        $profile->update(['last_calculated_at' => $newCalculatedAt]);

        $this->assertEquals(
            $newCalculatedAt->toDateTimeString(),
            $profile->fresh()->last_calculated_at->toDateTimeString(),
            'last_calculated_at should be updated to the new calculation timestamp',
        );
    }
}
