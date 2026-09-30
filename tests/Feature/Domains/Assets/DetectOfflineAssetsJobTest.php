<?php

namespace Tests\Feature\Domains\Assets;

use App\Contracts\TenantConfig\TenantConfigResolver;
use App\Domains\Assets\Enums\AssetStatus;
use App\Domains\Assets\Jobs\DetectOfflineAssetsJob;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetLocationSnapshot;
use App\Domains\Incidents\Jobs\ApplyExternalResolutionJob;
use App\Domains\Ingestion\Actions\QueueRawEventForProcessing;
use App\Domains\Ingestion\Actions\StoreRawEvent;
use App\Domains\Ingestion\Jobs\ProcessRawEventJob;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Normalization\Models\EventCategory;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\TenantConfig\Enums\SettingGroup;
use App\Domains\TenantConfig\Enums\SettingValueType;
use App\Domains\TenantConfig\Models\TenantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

/**
 * Roadmap V2-C1: the offline-asset watchdog raises one internal
 * `device_offline` event per silence episode of the asset's DEVICE (gateway
 * heartbeat, not GPS fix) and resolves it when the device connects again.
 */
class DetectOfflineAssetsJobTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

    private int $teamId;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->teamId = User::factory()->create()->currentTeam->id;
    }

    private function makeAsset(array $attributes = []): Asset
    {
        return Asset::factory()->create(array_merge([
            'team_id' => $this->teamId,
            'status' => AssetStatus::Active,
            'last_seen_at' => now()->subHours(2),
            'device_last_connected_at' => now()->subMinutes(30),
            'device_health_status' => 'Connected',
            'device_connectivity_polled_at' => now()->subMinutes(2),
        ], $attributes));
    }

    /** Last GPS fix taken while moving, right before the device dropped. */
    private function moving(Asset $asset): Asset
    {
        AssetLocationSnapshot::factory()->create([
            'asset_id' => $asset->id,
            'speed' => 72.0,
            'recorded_at' => $asset->device_last_connected_at->copy()->subMinute(),
        ]);

        // Estado de movimiento que mantiene la ingesta (MovementCriterion).
        $asset->forceFill([
            'last_moving_at' => $asset->device_last_connected_at->copy()->subMinute(),
            'stopped_since' => null,
        ])->save();

        return $asset;
    }

    private function parkedSetting(int $minutes): void
    {
        TenantSetting::factory()->create([
            'team_id' => $this->teamId,
            'setting_key' => DetectOfflineAssetsJob::PARKED_SETTING_KEY,
            'setting_group' => SettingGroup::Operational,
            'value_json' => ['value' => $minutes],
            'value_type' => SettingValueType::Number,
        ]);
    }

    private function offlineType(): EventType
    {
        $category = EventCategory::factory()->create(['code' => 'maintenance']);

        return EventType::factory()->create(['code' => 'device_offline', 'category_id' => $category->id]);
    }

    private function rawCount(): int
    {
        return RawEvent::withoutGlobalScopes()->count();
    }

    private function runJob(): void
    {
        (new DetectOfflineAssetsJob)->handle(
            app(TenantConfigResolver::class),
            app(StoreRawEvent::class),
            app(QueueRawEventForProcessing::class),
        );
    }

    public function test_device_dropping_mid_trip_raises_an_internal_event(): void
    {
        $asset = $this->moving($this->makeAsset(['device_last_connected_at' => now()->subMinutes(20)]));
        AssetLocationSnapshot::query()->where('asset_id', $asset->id)->update(['latitude' => 19.4326, 'longitude' => -99.1332]);

        $this->runJob();

        $rawEvent = RawEvent::withoutGlobalScopes()
            ->where('team_id', $this->teamId)
            ->sole();

        $this->assertSame('device_offline', $rawEvent->event_type_raw);
        $this->assertSame($asset->id, $rawEvent->payload_json['internal']['asset_id']);
        $this->assertTrue($rawEvent->payload_json['was_in_motion']);
        $this->assertSame(15, $rawEvent->payload_json['threshold_minutes']);
        $this->assertSame('Connected', $rawEvent->payload_json['device_health_status']);
        $this->assertSame(
            sprintf('offline:%d:%d', $asset->id, $asset->device_last_connected_at->getTimestamp()),
            $rawEvent->deduplication_key,
        );

        Queue::assertPushed(
            ProcessRawEventJob::class,
            fn (ProcessRawEventJob $job) => $job->rawEventId === $rawEvent->id,
        );

        $raised = $this->assertSystemLogged('assets.offline.raised', fn (array $c) => $c['input']['asset_id'] === $asset->id);
        $this->assertSame($this->teamId, $raised['input']['team_id']);
        $this->assertTrue($raised['calc']['was_in_motion']);
        $this->assertSame('in_motion', $raised['calc']['threshold_applied']);
        $this->assertSame('tenant_setting', $raised['calc']['threshold_source']);
        $this->assertSame(15, $raised['calc']['threshold_minutes']);
        $this->assertSame(15, $raised['calc']['in_motion_minutes']);
        $this->assertNull($raised['calc']['parked_minutes']);
        $this->assertGreaterThanOrEqual($raised['calc']['threshold_minutes'], $raised['calc']['silent_minutes']);
        $this->assertSame($rawEvent->payload_json['silent_minutes'], $raised['calc']['silent_minutes']);
        // Last fix taken one minute before the device dropped.
        $this->assertSame(60, $raised['calc']['location_age_s']);
        $this->assertSame($rawEvent->id, $raised['result']['raw_event_id']);
        $this->assertTrue($raised['result']['job_requested']);

        $sweep = $this->assertSystemLogged('assets.offline_sweep.completed');
        $this->assertSame(1, $sweep['result']['raised_count']);
        $this->assertSame(1, $sweep['result']['scanned_count']);
        $this->assertSame(1, $sweep['result']['in_motion_count']);
        $this->assertSame(DetectOfflineAssetsJob::CONNECTIVITY_FRESHNESS_MINUTES, $sweep['calc']['connectivity_freshness_minutes']);
        $this->assertSame(DetectOfflineAssetsJob::MAX_EPISODE_AGE_HOURS, $sweep['calc']['max_episode_age_hours']);
        $this->assertSame(DetectOfflineAssetsJob::DEFAULT_OFFLINE_MINUTES, $sweep['calc']['default_moving_threshold_minutes']);
        $this->assertSame(DetectOfflineAssetsJob::DEFAULT_PARKED_OFFLINE_MINUTES, $sweep['calc']['default_parked_threshold_minutes']);

        // Never the unit's name, code or coordinates.
        $json = json_encode($this->systemLogEntries());
        $this->assertStringNotContainsString($asset->name, $json);
        if ($asset->code !== null) {
            $this->assertStringNotContainsString($asset->code, $json);
        }
        $this->assertStringNotContainsString('19.43', $json);
        $this->assertStringNotContainsString('-99.13', $json);
        $this->assertStringNotContainsString('"location"', $json);
        $this->assertNoSensitiveDataLogged();
    }

    /**
     * The bug this watchdog was rewritten for: a parked vehicle produces a
     * GPS fix about once an hour, so its `last_seen_at` goes stale while its
     * gateway is perfectly connected.
     */
    public function test_stale_gps_with_a_connected_device_is_not_offline(): void
    {
        $this->makeAsset([
            'last_seen_at' => now()->subMinutes(58),
            'device_last_connected_at' => now()->subSeconds(20),
        ]);

        $this->runJob();

        $this->assertSame(0, $this->rawCount());
    }

    public function test_parked_vehicle_gets_the_longer_parked_grace(): void
    {
        $this->makeAsset(['device_last_connected_at' => now()->subMinutes(90)]);

        $this->runJob();

        $this->assertSame(0, $this->rawCount());
    }

    public function test_parked_vehicle_silent_beyond_the_parked_grace_raises_an_event(): void
    {
        $this->makeAsset(['device_last_connected_at' => now()->subMinutes(200)]);

        $this->runJob();

        $rawEvent = RawEvent::withoutGlobalScopes()->sole();
        $this->assertFalse($rawEvent->payload_json['was_in_motion']);
        $this->assertSame(DetectOfflineAssetsJob::DEFAULT_PARKED_OFFLINE_MINUTES, $rawEvent->payload_json['threshold_minutes']);

        $raised = $this->assertSystemLogged('assets.offline.raised');
        $this->assertFalse($raised['calc']['was_in_motion']);
        $this->assertSame('parked', $raised['calc']['threshold_applied']);
        // Recomputed from the logged terms: parked grace never undercuts the in-motion threshold.
        $this->assertSame(
            max($raised['calc']['parked_minutes'], $raised['calc']['in_motion_minutes']),
            $raised['calc']['threshold_minutes'],
        );
        $this->assertSame($rawEvent->payload_json['threshold_minutes'], $raised['calc']['threshold_minutes']);
        $this->assertGreaterThanOrEqual($raised['calc']['threshold_minutes'], $raised['calc']['silent_minutes']);
        // No GPS fix on file.
        $this->assertNull($raised['calc']['location_age_s']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_an_old_moving_fix_does_not_count_as_in_motion(): void
    {
        $asset = $this->makeAsset(['device_last_connected_at' => now()->subMinutes(30)]);

        // Moving fix from long before the device dropped (it parked since).
        AssetLocationSnapshot::factory()->create([
            'asset_id' => $asset->id,
            'speed' => 60.0,
            'recorded_at' => now()->subHours(3),
        ]);

        $this->runJob();

        $this->assertSame(0, $this->rawCount());
    }

    public function test_assets_without_a_connectivity_reading_are_not_watched(): void
    {
        // Unpaired/deactivated gateway: GPS frozen for months, no heartbeat.
        $this->makeAsset([
            'last_seen_at' => now()->subDays(90),
            'device_last_connected_at' => null,
            'device_connectivity_polled_at' => null,
        ]);

        $this->runJob();

        $this->assertSame(0, $this->rawCount());
    }

    public function test_a_stale_connectivity_reading_never_raises_events(): void
    {
        // We stopped hearing from the provider (API outage, revoked token):
        // that is not the fleet going offline.
        $this->moving($this->makeAsset([
            'device_last_connected_at' => now()->subMinutes(40),
            'device_connectivity_polled_at' => now()->subMinutes(40),
        ]));

        $this->runJob();

        $this->assertSame(0, $this->rawCount());
    }

    public function test_episodes_older_than_the_backlog_cap_are_not_raised_late(): void
    {
        // Chronically dead device, or the watchdog was down for days.
        $this->makeAsset(['device_last_connected_at' => now()->subHours(DetectOfflineAssetsJob::MAX_EPISODE_AGE_HOURS + 1)]);

        $this->runJob();

        $this->assertSame(0, $this->rawCount());
    }

    public function test_one_event_per_silence_episode_no_matter_how_many_ticks(): void
    {
        $asset = $this->moving($this->makeAsset());

        $this->runJob();
        $this->runJob();

        $this->assertSame(1, $this->rawCount());

        $this->assertCount(1, $this->systemLogEntries('assets.offline.raised'));
        $skipped = $this->systemLogEntries('assets.offline.skipped');
        $this->assertCount(1, $skipped);
        $this->assertSame('debug', $skipped[0]['level']);
        $this->assertSame('already_raised', $skipped[0]['context']['reason']);
        $this->assertSame($asset->id, $skipped[0]['context']['input']['asset_id']);
        $this->assertSame($this->teamId, $skipped[0]['context']['input']['team_id']);
        $this->assertSame(
            RawEvent::withoutGlobalScopes()->sole()->deduplication_key,
            $skipped[0]['context']['result']['deduplication_key'],
        );

        $sweeps = $this->systemLogEntries('assets.offline_sweep.completed');
        $this->assertSame(1, $sweeps[1]['context']['result']['already_raised_count']);
        $this->assertSame(0, $sweeps[1]['context']['result']['raised_count']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_new_silence_episode_raises_a_new_event(): void
    {
        $asset = $this->moving($this->makeAsset());

        $this->runJob();

        // The device connected again… and dropped again mid-trip.
        $asset->forceFill(['device_last_connected_at' => now()->subMinutes(20)])->save();
        $this->moving($asset);

        $this->runJob();

        $this->assertSame(2, $this->rawCount());
    }

    public function test_devices_within_their_threshold_stay_silent(): void
    {
        $this->moving($this->makeAsset(['device_last_connected_at' => now()->subMinutes(5)]));

        $this->runJob();

        $this->assertSame(0, $this->rawCount());
    }

    public function test_tenant_setting_overrides_the_in_motion_threshold(): void
    {
        TenantSetting::factory()->create([
            'team_id' => $this->teamId,
            'setting_key' => DetectOfflineAssetsJob::SETTING_KEY,
            'setting_group' => SettingGroup::Operational,
            'value_json' => ['value' => 60],
            'value_type' => SettingValueType::Number,
        ]);

        $this->moving($this->makeAsset(['device_last_connected_at' => now()->subMinutes(30)]));

        $this->runJob();

        $this->assertSame(0, $this->rawCount());
    }

    public function test_tenant_parked_setting_overrides_the_parked_grace(): void
    {
        $this->parkedSetting(30);

        $this->makeAsset(['device_last_connected_at' => now()->subMinutes(40)]);

        $this->runJob();

        $this->assertSame(1, $this->rawCount());
    }

    public function test_parked_grace_never_undercuts_the_in_motion_threshold(): void
    {
        $this->parkedSetting(5);

        $this->makeAsset(['device_last_connected_at' => now()->subMinutes(10)]);

        $this->runJob();

        $this->assertSame(0, $this->rawCount());
    }

    public function test_zero_parked_setting_disables_only_parked_alerts(): void
    {
        $this->parkedSetting(0);

        $this->makeAsset(['device_last_connected_at' => now()->subHours(10)]);
        $this->moving($this->makeAsset(['device_last_connected_at' => now()->subMinutes(20)]));

        $this->runJob();

        $this->assertSame(1, $this->rawCount());
        $this->assertTrue(RawEvent::withoutGlobalScopes()->sole()->payload_json['was_in_motion']);
    }

    public function test_per_asset_override_beats_the_tenant_threshold(): void
    {
        $this->moving($this->makeAsset([
            'device_last_connected_at' => now()->subMinutes(8),
            'metadata_json' => ['offline_alert_minutes' => 5],
        ]));

        $this->runJob();

        $this->assertSame(1, $this->rawCount());

        $raised = $this->assertSystemLogged('assets.offline.raised');
        $this->assertSame('asset_override', $raised['calc']['threshold_source']);
        $this->assertSame(5, $raised['calc']['threshold_minutes']);
        $this->assertSame(5, $raised['calc']['in_motion_minutes']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_zero_threshold_disables_the_watchdog(): void
    {
        $this->makeAsset([
            'device_last_connected_at' => now()->subHours(10),
            'metadata_json' => ['offline_alert_minutes' => 0],
        ]);

        $this->runJob();

        $this->assertSame(0, $this->rawCount());

        $sweep = $this->assertSystemLogged('assets.offline_sweep.completed');
        $this->assertSame(1, $sweep['result']['scanned_count']);
        $this->assertSame(1, $sweep['result']['disabled_count']);
        $this->assertSame(0, $sweep['result']['raised_count']);
        $this->assertSystemNotLogged('assets.offline.raised');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_inactive_and_maintenance_assets_are_ignored(): void
    {
        $this->makeAsset(['status' => AssetStatus::Inactive, 'device_last_connected_at' => now()->subHours(5)]);
        $this->makeAsset(['status' => AssetStatus::Maintenance, 'device_last_connected_at' => now()->subHours(5)]);

        $this->runJob();

        $this->assertSame(0, $this->rawCount());
    }

    public function test_reconnected_device_resolves_its_offline_episode(): void
    {
        $type = $this->offlineType();

        $asset = $this->makeAsset([
            'last_seen_at' => now()->subHours(3),
            'device_last_connected_at' => now()->subMinute(),
        ]);

        // The raise path persists the frozen heartbeat on the raw event: the
        // device went silent 20 minutes before the watchdog raised the event.
        $rawEvent = RawEvent::factory()->create([
            'team_id' => $this->teamId,
            'event_type_raw' => 'device_offline',
            'payload_json' => [
                'eventType' => 'device_offline',
                'last_connected_at' => now()->subMinutes(80)->toIso8601String(),
            ],
        ]);

        $event = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'raw_event_id' => $rawEvent->id,
            'asset_id' => $asset->id,
            'event_type_id' => $type->id,
            'occurred_at' => now()->subHour(),
            'payload_normalized_json' => ['event_type_code' => 'device_offline'],
        ]);

        $this->runJob();

        $fresh = $event->fresh();
        $this->assertTrue($fresh->payload_normalized_json['is_resolved']);
        $this->assertSame(
            $asset->device_last_connected_at->toIso8601String(),
            $fresh->payload_normalized_json['external_resolved_at'],
        );

        Queue::assertPushed(
            ApplyExternalResolutionJob::class,
            fn (ApplyExternalResolutionJob $job) => $job->normalizedEventId === $event->id,
        );

        // Re-running never re-resolves the same episode.
        $this->runJob();
        Queue::assertPushed(ApplyExternalResolutionJob::class, 1);

        $resolved = $this->systemLogEntries('assets.offline.resolved');
        $this->assertCount(1, $resolved);
        $context = $resolved[0]['context'];
        $this->assertSame($this->teamId, $context['input']['team_id']);
        $this->assertSame($event->id, $context['input']['normalized_event_id']);
        $this->assertSame($asset->id, $context['input']['asset_id']);
        $this->assertSame('heartbeat', $context['calc']['proof_of_life_source']);
        $proofOfLife = $asset->fresh()->device_last_connected_at;
        // Recomputed from persisted state: the real silence runs from the
        // episode's frozen heartbeat to the proof of life…
        $this->assertSame(
            (int) Carbon::parse($rawEvent->fresh()->payload_json['last_connected_at'])->diffInMinutes($proofOfLife),
            $context['calc']['silent_minutes'],
        );
        $this->assertGreaterThanOrEqual(78, $context['calc']['silent_minutes']);
        // …and the span since the watchdog raised the event is logged apart.
        $this->assertSame(
            (int) $event->fresh()->occurred_at->diffInMinutes($proofOfLife),
            $context['calc']['raised_to_recovery_minutes'],
        );
        $this->assertLessThan($context['calc']['silent_minutes'], $context['calc']['raised_to_recovery_minutes']);
        $this->assertTrue($context['result']['job_requested']);

        $sweeps = $this->systemLogEntries('assets.offline_sweep.completed');
        $this->assertSame(1, $sweeps[0]['context']['result']['resolved_count']);
        $this->assertSame(0, $sweeps[1]['context']['result']['resolved_count']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_episode_raised_before_the_connectivity_feed_resolves_on_a_new_gps_fix(): void
    {
        $type = $this->offlineType();

        $asset = $this->makeAsset([
            'last_seen_at' => now()->subMinutes(2),
            'device_last_connected_at' => null,
            'device_connectivity_polled_at' => null,
        ]);

        $event = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'asset_id' => $asset->id,
            'event_type_id' => $type->id,
            'occurred_at' => now()->subHour(),
            'payload_normalized_json' => ['event_type_code' => 'device_offline'],
        ]);

        $this->runJob();

        $this->assertTrue($event->fresh()->payload_normalized_json['is_resolved']);

        $resolved = $this->assertSystemLogged('assets.offline.resolved', fn (array $c) => $c['input']['normalized_event_id'] === $event->id);
        $this->assertSame('gps_fix', $resolved['calc']['proof_of_life_source']);
        $this->assertSame($asset->id, $resolved['input']['asset_id']);
        // Pre-feed episode: its raw event carries no frozen heartbeat, so the
        // real silence is unknown; only the span since the raise is logged.
        $this->assertArrayNotHasKey('last_connected_at', RawEvent::withoutGlobalScopes()->findOrFail($event->raw_event_id)->payload_json);
        $this->assertArrayHasKey('silent_minutes', $resolved['calc']);
        $this->assertNull($resolved['calc']['silent_minutes']);
        $this->assertSame(
            (int) $event->fresh()->occurred_at->diffInMinutes($asset->fresh()->last_seen_at),
            $resolved['calc']['raised_to_recovery_minutes'],
        );
        $this->assertNoSensitiveDataLogged();
    }

    public function test_unrecovered_episode_is_not_resolved(): void
    {
        $type = $this->offlineType();

        $asset = $this->makeAsset([
            'last_seen_at' => now()->subHours(3),
            'device_last_connected_at' => now()->subHours(2),
        ]);

        $event = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'asset_id' => $asset->id,
            'event_type_id' => $type->id,
            'occurred_at' => now()->subHour(),
            'payload_normalized_json' => ['event_type_code' => 'device_offline'],
        ]);

        $this->runJob();

        $this->assertArrayNotHasKey('is_resolved', $event->fresh()->payload_normalized_json);
        Queue::assertNotPushed(ApplyExternalResolutionJob::class);
    }

    public function test_each_tenant_is_inspected_with_its_own_thresholds_and_events(): void
    {
        $teamB = User::factory()->create()->currentTeam;

        // Tenant A raises the in-motion threshold; tenant B keeps the default.
        TenantSetting::factory()->create([
            'team_id' => $this->teamId,
            'setting_key' => DetectOfflineAssetsJob::SETTING_KEY,
            'setting_group' => SettingGroup::Operational,
            'value_json' => ['value' => 60],
            'value_type' => SettingValueType::Number,
        ]);

        $this->moving($this->makeAsset(['device_last_connected_at' => now()->subMinutes(20)]));
        $assetB = $this->moving($this->makeAsset([
            'team_id' => $teamB->id,
            'device_last_connected_at' => now()->subMinutes(20),
        ]));

        $this->runJob();

        $rawEvent = RawEvent::withoutGlobalScopes()->sole();
        $this->assertSame($teamB->id, $rawEvent->team_id);
        $this->assertSame($assetB->id, $rawEvent->payload_json['internal']['asset_id']);

        // Each per-asset line carries its own asset's tenant, and only it.
        $raised = $this->systemLogEntries('assets.offline.raised');
        $this->assertCount(1, $raised);
        $this->assertSame($teamB->id, $raised[0]['context']['input']['team_id']);
        $this->assertSame($assetB->id, $raised[0]['context']['input']['asset_id']);

        // The platform summary carries counts only, never a tenant id.
        $sweep = $this->systemLogEntries('assets.offline_sweep.completed');
        $this->assertCount(1, $sweep);
        $this->assertStringNotContainsString('team_id', json_encode($sweep[0]['context']));
        $this->assertStringNotContainsString('asset_id', json_encode($sweep[0]['context']));
        $this->assertSame(2, $sweep[0]['context']['result']['scanned_count']);
        $this->assertSame(1, $sweep[0]['context']['result']['raised_count']);
        $this->assertSame(1, $sweep[0]['context']['result']['within_threshold_count']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_phantom_speed_on_a_parked_unit_keeps_the_parked_threshold(): void
    {
        $asset = $this->makeAsset([
            'device_last_connected_at' => now()->subMinutes(30),
            'stopped_since' => now()->subHours(5),
            'last_moving_at' => now()->subHours(5),
        ]);

        // Pico fantasma de GPS con el tracto estacionado en el patio.
        AssetLocationSnapshot::factory()->create([
            'asset_id' => $asset->id,
            'speed' => 6.4,
            'recorded_at' => $asset->device_last_connected_at->copy()->subMinute(),
        ]);

        $this->runJob();

        // 30 min en silencio estacionado no llega al umbral de estacionado.
        $this->assertSame(0, $this->rawCount());
    }
}
