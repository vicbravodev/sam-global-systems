<?php

namespace Tests\Feature\Domains\Assets;

use App\Contracts\TenantConfig\TenantScheduleResolver;
use App\Domains\Assets\Enums\AssetStatus;
use App\Domains\Assets\Jobs\DetectAfterHoursMovementJob;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetLocationSnapshot;
use App\Domains\Ingestion\Actions\QueueRawEventForProcessing;
use App\Domains\Ingestion\Actions\StoreRawEvent;
use App\Domains\Ingestion\Jobs\ProcessRawEventJob;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\TenantConfig\Models\TenantScheduleProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Roadmap V2-C2: a unit moving while the tenant's schedule says "closed"
 * raises one internal `after_hours_movement` event per asset per local day.
 */
class DetectAfterHoursMovementJobTest extends TestCase
{
    use RefreshDatabase;

    private int $teamId;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        // Sunday 03:00 UTC (Saturday 21:00 in Mexico City) — outside the
        // factory profile's Mon–Fri 08:00–18:00 window either way.
        Carbon::setTestNow(Carbon::parse('2026-06-14 03:00:00', 'UTC'));

        $this->teamId = User::factory()->create()->currentTeam->id;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function makeSchedule(): TenantScheduleProfile
    {
        return TenantScheduleProfile::factory()->create([
            'team_id' => $this->teamId,
            'is_active' => true,
        ]);
    }

    private function makeMovingAsset(float $speed = 40.0, ?\DateTimeInterface $recordedAt = null, array $attributes = []): Asset
    {
        $asset = Asset::factory()->create(array_merge([
            'team_id' => $this->teamId,
            'status' => AssetStatus::Active,
        ], $attributes));

        AssetLocationSnapshot::factory()->create([
            'asset_id' => $asset->id,
            'speed' => $speed,
            'latitude' => 19.43,
            'longitude' => -99.13,
            'recorded_at' => $recordedAt ?? now()->subMinutes(2),
        ]);

        return $asset;
    }

    private function runJob(): void
    {
        (new DetectAfterHoursMovementJob)->handle(
            app(TenantScheduleResolver::class),
            app(StoreRawEvent::class),
            app(QueueRawEventForProcessing::class),
        );
    }

    public function test_moving_asset_outside_operating_hours_raises_an_internal_event(): void
    {
        $this->makeSchedule();
        $asset = $this->makeMovingAsset();

        $this->runJob();

        $rawEvent = RawEvent::withoutGlobalScopes()->sole();

        $this->assertSame('after_hours_movement', $rawEvent->event_type_raw);
        $this->assertSame($asset->id, $rawEvent->payload_json['internal']['asset_id']);
        $this->assertEquals(40.0, $rawEvent->payload_json['speed_kph']);

        Queue::assertPushed(ProcessRawEventJob::class);
    }

    public function test_one_event_per_asset_per_local_day(): void
    {
        $this->makeSchedule();
        $this->makeMovingAsset();

        $this->runJob();
        $this->runJob();

        $this->assertSame(1, RawEvent::withoutGlobalScopes()->count());
    }

    public function test_without_a_schedule_profile_nothing_is_raised(): void
    {
        $this->makeMovingAsset();

        $this->runJob();

        $this->assertSame(0, RawEvent::withoutGlobalScopes()->count());
    }

    public function test_within_operating_hours_nothing_is_raised(): void
    {
        // Wednesday 16:00 in Mexico City — inside Mon–Fri 08:00–18:00.
        Carbon::setTestNow(Carbon::parse('2026-06-10 22:00:00', 'UTC'));

        $this->makeSchedule();
        $this->makeMovingAsset();

        $this->runJob();

        $this->assertSame(0, RawEvent::withoutGlobalScopes()->count());
    }

    public function test_slow_or_stale_positions_do_not_count_as_movement(): void
    {
        $this->makeSchedule();
        $this->makeMovingAsset(speed: 2.0);
        $this->makeMovingAsset(speed: 60.0, recordedAt: now()->subHours(2));

        $this->runJob();

        $this->assertSame(0, RawEvent::withoutGlobalScopes()->count());
    }

    public function test_inactive_assets_are_ignored(): void
    {
        $this->makeSchedule();
        $this->makeMovingAsset(attributes: ['status' => AssetStatus::Inactive]);

        $this->runJob();

        $this->assertSame(0, RawEvent::withoutGlobalScopes()->count());
    }

    public function test_schedule_of_another_tenant_does_not_trigger_alerts_here(): void
    {
        // Only the OTHER tenant has a schedule profile; my moving asset stays silent.
        $otherTeamId = User::factory()->create()->currentTeam->id;
        TenantScheduleProfile::factory()->create(['team_id' => $otherTeamId, 'is_active' => true]);

        $this->makeMovingAsset();

        $this->runJob();

        $this->assertSame(0, RawEvent::withoutGlobalScopes()->where('team_id', $this->teamId)->count());
    }
}
