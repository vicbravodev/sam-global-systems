<?php

namespace Tests\Feature\Domains\Context;

use App\Domains\Context\Actions\BuildOperationalContextProfile;
use App\Domains\Context\Enums\RiskLevel;
use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Normalization\Models\EventSeverity;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuildOperationalContextProfileTest extends TestCase
{
    use RefreshDatabase;

    private int $teamId;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->teamId = $user->currentTeam->id;
    }

    public function test_critical_severity_in_sensitive_zone_yields_sensitive_zone_critical(): void
    {
        $criticalSeverity = EventSeverity::factory()->create(['code' => 'critical']);
        $event = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'event_severity_id' => $criticalSeverity->id,
        ]);

        $snapshot = EventContextSnapshot::factory()->create([
            'normalized_event_id' => $event->id,
            'team_id' => $this->teamId,
            'signals_json' => [
                'is_in_sensitive_geofence' => true,
                'has_open_incident' => true,
                'driver_has_recent_risk_events' => true,
            ],
            'recent_history_snapshot_json' => [
                'recent_same_type_count' => 2,
                'recent_high_severity_count' => 1,
            ],
        ]);

        $profile = app(BuildOperationalContextProfile::class)->execute($snapshot->fresh());

        $this->assertSame(RiskLevel::Critical, $profile->risk_level);
        $this->assertSame('sensitive_zone_critical', $profile->profile_code);
        $this->assertTrue($profile->contextual_flags_json['is_in_sensitive_geofence']);
    }

    public function test_low_severity_without_signals_yields_baseline(): void
    {
        $lowSeverity = EventSeverity::factory()->create(['code' => 'low']);
        $event = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'event_severity_id' => $lowSeverity->id,
        ]);

        $snapshot = EventContextSnapshot::factory()->create([
            'normalized_event_id' => $event->id,
            'team_id' => $this->teamId,
            'signals_json' => [],
            'recent_history_snapshot_json' => [],
        ]);

        $profile = app(BuildOperationalContextProfile::class)->execute($snapshot->fresh());

        $this->assertSame(RiskLevel::Low, $profile->risk_level);
        $this->assertSame('baseline', $profile->profile_code);
    }

    public function test_high_severity_yields_elevated_profile(): void
    {
        $highSeverity = EventSeverity::factory()->create(['code' => 'high']);
        $event = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'event_severity_id' => $highSeverity->id,
        ]);

        $snapshot = EventContextSnapshot::factory()->create([
            'normalized_event_id' => $event->id,
            'team_id' => $this->teamId,
            'signals_json' => ['driver_has_recent_risk_events' => true, 'has_open_incident' => true],
            'recent_history_snapshot_json' => ['recent_same_type_count' => 2, 'recent_high_severity_count' => 1],
        ]);

        $profile = app(BuildOperationalContextProfile::class)->execute($snapshot->fresh());

        $this->assertSame(RiskLevel::High, $profile->risk_level);
        $this->assertSame('elevated', $profile->profile_code);
    }

    public function test_recurrence_score_is_capped_at_30(): void
    {
        $highSeverity = EventSeverity::factory()->create(['code' => 'high']);
        $event = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'event_severity_id' => $highSeverity->id,
        ]);

        $snapshot = EventContextSnapshot::factory()->create([
            'normalized_event_id' => $event->id,
            'team_id' => $this->teamId,
            'signals_json' => [],
            'recent_history_snapshot_json' => ['recent_same_type_count' => 20, 'recent_high_severity_count' => 20],
        ]);

        $profile = app(BuildOperationalContextProfile::class)->execute($snapshot->fresh());

        $this->assertEquals(30.0, $profile->recurrence_score);
    }
}
