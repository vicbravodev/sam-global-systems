<?php

namespace Tests\Feature\Domains\Drivers;

use App\Domains\Drivers\Enums\RiskLevel;
use App\Domains\Drivers\Jobs\RecalculateDriverRiskProfilesJob;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverRiskProfile;
use App\Domains\Normalization\Models\EventCategory;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Notifications\Actions\SendNotification;
use App\Domains\Notifications\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Roadmap V2-D1: the daily risk recalculation aggregates safety events into
 * DriverRiskProfile and raises preventive deterioration alerts.
 */
class RecalculateDriverRiskProfilesJobTest extends TestCase
{
    use RefreshDatabase;

    private int $teamId;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->teamId = User::factory()->create()->currentTeam->id;
    }

    private function makeEventType(string $code): EventType
    {
        $category = EventCategory::query()->firstOrCreate(
            ['code' => 'safety'],
            ['name' => 'Safety'],
        );

        return EventType::query()->firstOrCreate(
            ['code' => $code],
            ['name' => $code, 'category_id' => $category->id],
        );
    }

    private function addEvents(Driver $driver, string $code, int $count, int $daysAgo = 5): void
    {
        $type = $this->makeEventType($code);

        NormalizedEvent::factory()->count($count)->create([
            'team_id' => $this->teamId,
            'driver_id' => $driver->id,
            'event_type_id' => $type->id,
            'occurred_at' => now()->subDays($daysAgo),
        ]);
    }

    private function runJob(): void
    {
        (new RecalculateDriverRiskProfilesJob)->handle(app(SendNotification::class));
    }

    public function test_aggregates_the_window_into_the_risk_profile(): void
    {
        $driver = Driver::factory()->create(['team_id' => $this->teamId]);

        $this->addEvents($driver, 'harsh_braking', 3);
        $this->addEvents($driver, 'driver_fatigue', 2);
        $this->addEvents($driver, 'speeding', 4);
        // Outside the 30-day window: never counted.
        $this->addEvents($driver, 'harsh_braking', 5, daysAgo: 40);

        $this->runJob();

        $profile = DriverRiskProfile::query()->where('driver_id', $driver->id)->sole();

        $this->assertSame(3, $profile->harsh_events_count);
        $this->assertSame(2, $profile->fatigue_flags_count);
        // 3*4 + 2*8 + 4*2 = 36 → medium.
        $this->assertEquals(36.0, (float) $profile->risk_score);
        $this->assertSame(RiskLevel::Medium, $profile->risk_level);
        $this->assertSame('baseline', $profile->metadata_json['trend']);
        $this->assertNotNull($profile->last_calculated_at);
    }

    public function test_score_decays_and_trend_improves_when_events_age_out(): void
    {
        $driver = Driver::factory()->create(['team_id' => $this->teamId]);

        DriverRiskProfile::factory()->create([
            'driver_id' => $driver->id,
            'risk_score' => 80,
            'risk_level' => RiskLevel::Critical,
        ]);

        $this->runJob();

        $profile = DriverRiskProfile::query()->where('driver_id', $driver->id)->sole();

        $this->assertEquals(0.0, (float) $profile->risk_score);
        $this->assertSame(RiskLevel::Low, $profile->risk_level);
        $this->assertSame('improving', $profile->metadata_json['trend']);

        Queue::assertNothingPushed();
        $this->assertSame(0, Notification::withoutGlobalScopes()->count());
    }

    public function test_crossing_into_high_raises_a_preventive_notification_once(): void
    {
        $driver = Driver::factory()->create(['team_id' => $this->teamId]);

        // 5 fatiga (40) + 2 severas (30) = 70 → high.
        $this->addEvents($driver, 'driver_fatigue', 5);
        $this->addEvents($driver, 'collision', 2);

        $this->runJob();
        $this->runJob();

        $notifications = Notification::withoutGlobalScopes()
            ->where('team_id', $this->teamId)
            ->where('notification_type', 'driver.risk_deteriorated')
            ->get();

        // The second run sees high → high with no score increase: no re-alert.
        $this->assertCount(1, $notifications);
        $this->assertSame((string) $driver->id, $notifications->first()->source_reference_id);
    }

    public function test_drivers_without_events_or_profile_are_skipped(): void
    {
        Driver::factory()->create(['team_id' => $this->teamId]);

        $this->runJob();

        $this->assertSame(0, DriverRiskProfile::query()->count());
    }

    public function test_events_of_another_driver_do_not_leak(): void
    {
        $driver = Driver::factory()->create(['team_id' => $this->teamId]);
        $other = Driver::factory()->create(['team_id' => $this->teamId]);

        $this->addEvents($other, 'collision', 3);
        $this->addEvents($driver, 'speeding', 1);

        $this->runJob();

        $profile = DriverRiskProfile::query()->where('driver_id', $driver->id)->sole();

        $this->assertEquals(2.0, (float) $profile->risk_score);
        $this->assertSame(0, $profile->harsh_events_count);
    }
}
