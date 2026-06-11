<?php

namespace Tests\Feature\Domains\Assets;

use App\Contracts\TenantConfig\TenantConfigResolver;
use App\Domains\Assets\Enums\AssetStatus;
use App\Domains\Assets\Jobs\DetectUnauthorizedStopJob;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetLocationSnapshot;
use App\Domains\Context\Actions\ResolveGeofenceContext;
use App\Domains\Context\Models\Geofence;
use App\Domains\Ingestion\Actions\QueueRawEventForProcessing;
use App\Domains\Ingestion\Actions\StoreRawEvent;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\TenantConfig\Enums\SettingGroup;
use App\Domains\TenantConfig\Enums\SettingValueType;
use App\Domains\TenantConfig\Models\TenantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Roadmap V2-C3: a prolonged stop outside every known geofence raises one
 * internal `suspicious_stop` event per episode.
 */
class DetectUnauthorizedStopJobTest extends TestCase
{
    use RefreshDatabase;

    private int $teamId;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->teamId = User::factory()->create()->currentTeam->id;
    }

    /**
     * Base geofence far from the stop location so "outside" holds.
     */
    private function makeGeofence(?array $coordinates = null): Geofence
    {
        return Geofence::factory()->create([
            'team_id' => $this->teamId,
            'is_active' => true,
            'geometry_json' => [
                'type' => 'Polygon',
                'coordinates' => [$coordinates ?? [
                    [-98.10, 18.10],
                    [-98.00, 18.10],
                    [-98.00, 18.20],
                    [-98.10, 18.20],
                    [-98.10, 18.10],
                ]],
            ],
        ]);
    }

    private function makeStoppedAsset(int $stoppedMinutes = 30, array $attributes = []): Asset
    {
        $asset = Asset::factory()->create(array_merge([
            'team_id' => $this->teamId,
            'status' => AssetStatus::Active,
        ], $attributes));

        // Last moving position N minutes ago (the episode anchor)…
        AssetLocationSnapshot::factory()->create([
            'asset_id' => $asset->id,
            'speed' => 60.0,
            'latitude' => 19.40,
            'longitude' => -99.10,
            'recorded_at' => now()->subMinutes($stoppedMinutes),
        ]);

        // …and a fresh stationary position outside every geofence.
        AssetLocationSnapshot::factory()->create([
            'asset_id' => $asset->id,
            'speed' => 0.0,
            'latitude' => 19.43,
            'longitude' => -99.13,
            'recorded_at' => now()->subMinutes(2),
        ]);

        return $asset;
    }

    private function runJob(): void
    {
        (new DetectUnauthorizedStopJob)->handle(
            app(TenantConfigResolver::class),
            app(ResolveGeofenceContext::class),
            app(StoreRawEvent::class),
            app(QueueRawEventForProcessing::class),
        );
    }

    public function test_prolonged_stop_outside_geofences_raises_a_suspicious_stop_event(): void
    {
        $this->makeGeofence();
        $asset = $this->makeStoppedAsset(stoppedMinutes: 30);

        $this->runJob();

        $rawEvent = RawEvent::withoutGlobalScopes()->sole();

        $this->assertSame('suspicious_stop', $rawEvent->event_type_raw);
        $this->assertSame($asset->id, $rawEvent->payload_json['internal']['asset_id']);
        $this->assertGreaterThanOrEqual(29, $rawEvent->payload_json['stopped_minutes']);
    }

    public function test_one_event_per_stop_episode(): void
    {
        $this->makeGeofence();
        $this->makeStoppedAsset();

        $this->runJob();
        $this->runJob();

        $this->assertSame(1, RawEvent::withoutGlobalScopes()->count());
    }

    public function test_stop_inside_a_known_geofence_is_authorized(): void
    {
        // Geofence containing the stationary position.
        $this->makeGeofence([
            [-99.20, 19.30],
            [-99.05, 19.30],
            [-99.05, 19.50],
            [-99.20, 19.50],
            [-99.20, 19.30],
        ]);

        $this->makeStoppedAsset();

        $this->runJob();

        $this->assertSame(0, RawEvent::withoutGlobalScopes()->count());
    }

    public function test_short_stops_do_not_alert(): void
    {
        $this->makeGeofence();
        $this->makeStoppedAsset(stoppedMinutes: 5);

        $this->runJob();

        $this->assertSame(0, RawEvent::withoutGlobalScopes()->count());
    }

    public function test_tenants_without_geofences_never_alert(): void
    {
        $this->makeStoppedAsset();

        $this->runJob();

        $this->assertSame(0, RawEvent::withoutGlobalScopes()->count());
    }

    public function test_zero_threshold_disables_the_detector(): void
    {
        TenantSetting::factory()->create([
            'team_id' => $this->teamId,
            'setting_key' => DetectUnauthorizedStopJob::SETTING_KEY,
            'setting_group' => SettingGroup::Operational,
            'value_json' => ['value' => 0],
            'value_type' => SettingValueType::Number,
        ]);

        $this->makeGeofence();
        $this->makeStoppedAsset();

        $this->runJob();

        $this->assertSame(0, RawEvent::withoutGlobalScopes()->count());
    }

    public function test_moving_assets_never_alert(): void
    {
        $this->makeGeofence();

        $asset = Asset::factory()->create(['team_id' => $this->teamId, 'status' => AssetStatus::Active]);
        AssetLocationSnapshot::factory()->create([
            'asset_id' => $asset->id,
            'speed' => 45.0,
            'latitude' => 19.43,
            'longitude' => -99.13,
            'recorded_at' => now()->subMinutes(2),
        ]);

        $this->runJob();

        $this->assertSame(0, RawEvent::withoutGlobalScopes()->count());
    }

    public function test_long_term_parking_without_recent_movement_is_ignored(): void
    {
        $this->makeGeofence();

        $asset = Asset::factory()->create(['team_id' => $this->teamId, 'status' => AssetStatus::Active]);

        // Only stationary positions: no movement anchor inside the 24h window.
        AssetLocationSnapshot::factory()->create([
            'asset_id' => $asset->id,
            'speed' => 0.0,
            'latitude' => 19.43,
            'longitude' => -99.13,
            'recorded_at' => now()->subMinutes(2),
        ]);

        $this->runJob();

        $this->assertSame(0, RawEvent::withoutGlobalScopes()->count());
    }
}
