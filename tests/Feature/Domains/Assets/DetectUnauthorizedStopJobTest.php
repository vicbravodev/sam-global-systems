<?php

namespace Tests\Feature\Domains\Assets;

use App\Contracts\TenantConfig\TenantConfigResolver;
use App\Domains\Assets\Enums\AssetStatus;
use App\Domains\Assets\Jobs\DetectUnauthorizedStopJob;
use App\Domains\Assets\Models\Asset;
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
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * Roadmap V2-C3: a prolonged stop outside every known geofence raises one
 * internal `suspicious_stop` event per episode.
 */
class DetectUnauthorizedStopJobTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, RefreshDatabase;

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

    /**
     * The motion state the telematics feed keeps on the asset: last moving
     * point N minutes ago (the episode anchor), still ever since, and a fresh
     * stationary position outside every geofence.
     */
    private function makeStoppedAsset(int $stoppedMinutes = 30, array $attributes = []): Asset
    {
        return Asset::factory()->create(array_merge([
            'team_id' => $this->teamId,
            'status' => AssetStatus::Active,
            'last_moving_at' => now()->subMinutes($stoppedMinutes),
            'stopped_since' => now()->subMinutes($stoppedMinutes - 1),
            'last_latitude' => 19.43,
            'last_longitude' => -99.13,
            'last_speed_kph' => 0.0,
            'last_location_at' => now()->subMinutes(2),
        ], $attributes));
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

        $raised = $this->assertSystemLogged('assets.unauthorized_stop.raised');
        $this->assertSame($this->teamId, $raised['input']['team_id']);
        $this->assertSame($asset->id, $raised['input']['asset_id']);
        $this->assertSame($rawEvent->payload_json['stopped_minutes'], $raised['calc']['stopped_minutes']);
        $this->assertSame(DetectUnauthorizedStopJob::DEFAULT_STOP_MINUTES, $raised['calc']['stop_minutes']);
        $this->assertGreaterThanOrEqual($raised['calc']['stop_minutes'], $raised['calc']['stopped_minutes']);
        $this->assertSame($rawEvent->id, $raised['result']['raw_event_id']);
        $this->assertTrue($raised['result']['job_requested']);

        $sweep = $this->systemLogEntries('assets.unauthorized_stop_sweep.completed');
        $this->assertCount(1, $sweep);
        $this->assertSame('info', $sweep[0]['level']);
        $context = $sweep[0]['context'];
        $this->assertSame('ok', $context['outcome']);
        $this->assertSame($this->teamId, $context['input']['team_id']);
        $this->assertSame(DetectUnauthorizedStopJob::DEFAULT_STOP_MINUTES, $context['calc']['stop_minutes']);
        $this->assertSame(DetectUnauthorizedStopJob::FRESHNESS_MINUTES, $context['calc']['freshness_minutes']);
        $this->assertSame(DetectUnauthorizedStopJob::MAX_ANCHOR_HOURS, $context['calc']['max_anchor_hours']);
        $this->assertSame(6, $context['calc']['realert_hours']);
        $this->assertEquals(200, $context['calc']['realert_radius_m']);
        $this->assertSame(1, $context['calc']['geofences_count']);
        $this->assertSame(1, $context['result']['candidates_count']);
        $this->assertSame(1, $context['result']['raised_count']);
        $this->assertSame(0, $context['result']['inside_geofence_count']);
        $this->assertSame(0, $context['result']['same_place_count']);
        $this->assertSame(0, $context['result']['already_raised_count']);

        // Never the unit's name, code or coordinates.
        $json = json_encode($this->systemLogEntries());
        $this->assertStringNotContainsString($asset->name, $json);
        $this->assertStringNotContainsString('19.43', $json);
        $this->assertStringNotContainsString('-99.13', $json);
        $this->assertNoSensitiveDataLogged();
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

        $sweep = $this->assertSystemLogged('assets.unauthorized_stop_sweep.completed');
        $this->assertSame(1, $sweep['result']['candidates_count']);
        $this->assertSame(1, $sweep['result']['inside_geofence_count']);
        $this->assertSame(0, $sweep['result']['raised_count']);
        $this->assertSystemNotLogged('assets.unauthorized_stop.raised');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_short_stops_do_not_alert(): void
    {
        $this->makeGeofence();
        $this->makeStoppedAsset(stoppedMinutes: 5);

        $this->runJob();

        $this->assertSame(0, RawEvent::withoutGlobalScopes()->count());

        // A sweep with no candidates still narrates itself, at debug.
        $sweep = $this->systemLogEntries('assets.unauthorized_stop_sweep.completed');
        $this->assertCount(1, $sweep);
        $this->assertSame('debug', $sweep[0]['level']);
        $this->assertSame('ok', $sweep[0]['context']['outcome']);
        $this->assertSame(0, $sweep[0]['context']['result']['candidates_count']);
        $this->assertSame(0, $sweep[0]['context']['result']['raised_count']);
        // Geofences are not loaded without candidates.
        $this->assertNull($sweep[0]['context']['calc']['geofences_count']);
        $this->assertNoSensitiveDataLogged();
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

        $sweep = $this->systemLogEntries('assets.unauthorized_stop_sweep.completed');
        $this->assertCount(1, $sweep);
        $this->assertSame('skipped', $sweep[0]['context']['outcome']);
        $this->assertSame('disabled', $sweep[0]['context']['reason']);
        $this->assertSame($this->teamId, $sweep[0]['context']['input']['team_id']);
        $this->assertSame(0, $sweep[0]['context']['calc']['stop_minutes']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_moving_assets_never_alert(): void
    {
        $this->makeGeofence();
        $this->makeStoppedAsset(attributes: ['stopped_since' => null, 'last_moving_at' => now()->subMinute(), 'last_speed_kph' => 45.0]);

        $this->runJob();

        $this->assertSame(0, RawEvent::withoutGlobalScopes()->count());
    }

    public function test_long_term_parking_without_recent_movement_is_ignored(): void
    {
        $this->makeGeofence();
        // No movement anchor inside the 24 h window.
        $this->makeStoppedAsset(attributes: ['last_moving_at' => now()->subDays(3), 'stopped_since' => now()->subDays(3)]);
        $this->makeStoppedAsset(attributes: ['last_moving_at' => null]);

        $this->runJob();

        $this->assertSame(0, RawEvent::withoutGlobalScopes()->count());
    }

    public function test_a_stop_with_a_dark_device_is_left_to_the_offline_watchdog(): void
    {
        $this->makeGeofence();
        // Neither a recent position nor a recent gateway heartbeat.
        $this->makeStoppedAsset(attributes: ['last_location_at' => now()->subHour(), 'device_last_connected_at' => now()->subHour()]);

        $this->runJob();

        $this->assertSame(0, RawEvent::withoutGlobalScopes()->count());
    }

    public function test_a_parked_unit_with_a_live_gateway_still_alerts(): void
    {
        $this->makeGeofence();
        // Parked units stop producing GPS fixes, but the gateway heartbeat
        // proves the device is alive.
        $this->makeStoppedAsset(attributes: ['last_location_at' => now()->subHour(), 'device_last_connected_at' => now()->subMinutes(3)]);

        $this->runJob();

        $this->assertSame(1, RawEvent::withoutGlobalScopes()->count());
    }

    public function test_a_handled_episode_leaves_the_candidate_set_until_the_unit_moves_again(): void
    {
        $this->makeGeofence();
        $asset = $this->makeStoppedAsset();

        $this->runJob();
        $this->assertTrue($asset->fresh()->stop_alerted_for->equalTo($asset->last_moving_at));

        // It drives ~3 km away and stops again for long enough: a new
        // episode at a new place, a new alert.
        $asset->forceFill([
            'last_moving_at' => now()->subMinutes(15),
            'stopped_since' => now()->subMinutes(14),
            'stop_latitude' => 19.46,
            'stop_longitude' => -99.13,
            'last_latitude' => 19.46,
        ])->save();
        $this->runJob();

        $this->assertSame(2, RawEvent::withoutGlobalScopes()->count());
    }

    public function test_a_new_stop_at_the_place_already_alerted_is_not_alerted_again(): void
    {
        $this->makeGeofence();
        $asset = $this->makeStoppedAsset(attributes: ['stop_latitude' => 19.43, 'stop_longitude' => -99.13]);

        $this->runJob();

        // Shuffles ~60 m around the same yard and stops again.
        $asset->forceFill([
            'last_moving_at' => now()->subMinutes(15),
            'stopped_since' => now()->subMinutes(14),
            'stop_latitude' => 19.4305,
            'stop_longitude' => -99.13,
        ])->save();
        $this->runJob();

        $this->assertSame(1, RawEvent::withoutGlobalScopes()->count());
        // Handled all the same, so the next sweeps skip it.
        $this->assertTrue($asset->fresh()->stop_alerted_for->equalTo(now()->subMinutes(15)->startOfSecond()));

        $sweeps = $this->systemLogEntries('assets.unauthorized_stop_sweep.completed');
        $this->assertCount(2, $sweeps);
        $this->assertSame(1, $sweeps[1]['context']['result']['same_place_count']);
        $this->assertSame(0, $sweeps[1]['context']['result']['raised_count']);
        $this->assertCount(1, $this->systemLogEntries('assets.unauthorized_stop.raised'));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_it_only_reads_and_writes_the_swept_tenant(): void
    {
        $this->makeGeofence();
        $this->makeStoppedAsset();

        // Another tenant with a stopped unit but no geofences of its own.
        $otherTeamId = User::factory()->create()->currentTeam->id;
        Asset::factory()->create([
            'team_id' => $otherTeamId,
            'last_moving_at' => now()->subMinutes(30),
            'stopped_since' => now()->subMinutes(29),
            'last_latitude' => 19.43,
            'last_longitude' => -99.13,
            'last_location_at' => now()->subMinutes(2),
        ]);

        $this->assertNoTenantLeak($this->teamId, fn () => $this->runJob());

        $this->assertSame(0, RawEvent::withoutGlobalScopes()->where('team_id', $otherTeamId)->count());

        // Only the swept tenant is narrated; the other one never appears.
        foreach ($this->systemLogEntries() as $entry) {
            if (str_starts_with($entry['code'], 'assets.')) {
                $this->assertSame($this->teamId, $entry['context']['input']['team_id']);
            }
        }
        $this->assertCount(1, $this->systemLogEntries('assets.unauthorized_stop_sweep.completed'));
        $this->assertNoSensitiveDataLogged();
    }
}
