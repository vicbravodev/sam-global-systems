<?php

namespace Tests\Feature\Domains\Assets;

use App\Contracts\TenantConfig\TenantConfigResolver;
use App\Domains\Assets\Enums\AssetStatus;
use App\Domains\Assets\Jobs\DetectOfflineAssetsJob;
use App\Domains\Assets\Models\Asset;
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
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Roadmap V2-C1: the offline-asset watchdog raises one internal
 * `device_offline` event per silence episode and resolves it when the asset
 * reports again.
 */
class DetectOfflineAssetsJobTest extends TestCase
{
    use RefreshDatabase;

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
            'last_seen_at' => now()->subMinutes(30),
        ], $attributes));
    }

    private function runJob(): void
    {
        (new DetectOfflineAssetsJob)->handle(
            app(TenantConfigResolver::class),
            app(StoreRawEvent::class),
            app(QueueRawEventForProcessing::class),
        );
    }

    public function test_silent_asset_beyond_the_default_threshold_raises_an_internal_event(): void
    {
        $asset = $this->makeAsset();

        $this->runJob();

        $rawEvent = RawEvent::withoutGlobalScopes()
            ->where('team_id', $this->teamId)
            ->sole();

        $this->assertSame('device_offline', $rawEvent->event_type_raw);
        $this->assertSame($asset->id, $rawEvent->payload_json['internal']['asset_id']);
        $this->assertSame(
            sprintf('offline:%d:%d', $asset->id, $asset->last_seen_at->getTimestamp()),
            $rawEvent->deduplication_key,
        );

        Queue::assertPushed(
            ProcessRawEventJob::class,
            fn (ProcessRawEventJob $job) => $job->rawEventId === $rawEvent->id,
        );
    }

    public function test_one_event_per_silence_episode_no_matter_how_many_ticks(): void
    {
        $this->makeAsset();

        $this->runJob();
        $this->runJob();

        $this->assertSame(1, RawEvent::withoutGlobalScopes()->count());
    }

    public function test_a_new_silence_episode_raises_a_new_event(): void
    {
        $asset = $this->makeAsset();

        $this->runJob();

        // The asset reported again… and went silent again.
        $asset->forceFill(['last_seen_at' => now()->subMinutes(20)])->save();

        $this->runJob();

        $this->assertSame(2, RawEvent::withoutGlobalScopes()->count());
    }

    public function test_assets_within_their_threshold_stay_silent(): void
    {
        $this->makeAsset(['last_seen_at' => now()->subMinutes(5)]);

        $this->runJob();

        $this->assertSame(0, RawEvent::withoutGlobalScopes()->count());
    }

    public function test_tenant_setting_overrides_the_default_threshold(): void
    {
        TenantSetting::factory()->create([
            'team_id' => $this->teamId,
            'setting_key' => DetectOfflineAssetsJob::SETTING_KEY,
            'setting_group' => SettingGroup::Operational,
            'value_json' => ['value' => 60],
            'value_type' => SettingValueType::Number,
        ]);

        $this->makeAsset(['last_seen_at' => now()->subMinutes(30)]);

        $this->runJob();

        $this->assertSame(0, RawEvent::withoutGlobalScopes()->count());
    }

    public function test_per_asset_override_beats_the_tenant_threshold(): void
    {
        $this->makeAsset([
            'last_seen_at' => now()->subMinutes(8),
            'metadata_json' => ['offline_alert_minutes' => 5],
        ]);

        $this->runJob();

        $this->assertSame(1, RawEvent::withoutGlobalScopes()->count());
    }

    public function test_zero_threshold_disables_the_watchdog(): void
    {
        $this->makeAsset([
            'last_seen_at' => now()->subHours(10),
            'metadata_json' => ['offline_alert_minutes' => 0],
        ]);

        $this->runJob();

        $this->assertSame(0, RawEvent::withoutGlobalScopes()->count());
    }

    public function test_inactive_and_maintenance_assets_are_ignored(): void
    {
        $this->makeAsset(['status' => AssetStatus::Inactive, 'last_seen_at' => now()->subHours(3)]);
        $this->makeAsset(['status' => AssetStatus::Maintenance, 'last_seen_at' => now()->subHours(3)]);

        $this->runJob();

        $this->assertSame(0, RawEvent::withoutGlobalScopes()->count());
    }

    public function test_assets_that_never_reported_are_ignored(): void
    {
        $this->makeAsset(['last_seen_at' => null]);

        $this->runJob();

        $this->assertSame(0, RawEvent::withoutGlobalScopes()->count());
    }

    public function test_recovered_asset_resolves_its_offline_episode(): void
    {
        $category = EventCategory::factory()->create(['code' => 'maintenance']);
        $type = EventType::factory()->create(['code' => 'device_offline', 'category_id' => $category->id]);

        $asset = $this->makeAsset(['last_seen_at' => now()->subMinutes(2)]);

        $event = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'asset_id' => $asset->id,
            'event_type_id' => $type->id,
            'occurred_at' => now()->subHour(),
            'payload_normalized_json' => ['event_type_code' => 'device_offline'],
        ]);

        $this->runJob();

        $fresh = $event->fresh();
        $this->assertTrue($fresh->payload_normalized_json['is_resolved']);
        $this->assertNotNull($fresh->payload_normalized_json['external_resolved_at']);

        Queue::assertPushed(
            ApplyExternalResolutionJob::class,
            fn (ApplyExternalResolutionJob $job) => $job->normalizedEventId === $event->id,
        );

        // Re-running never re-resolves the same episode.
        $this->runJob();
        Queue::assertPushed(ApplyExternalResolutionJob::class, 1);
    }

    public function test_unrecovered_episode_is_not_resolved(): void
    {
        $category = EventCategory::factory()->create(['code' => 'maintenance']);
        $type = EventType::factory()->create(['code' => 'device_offline', 'category_id' => $category->id]);

        $asset = $this->makeAsset(['last_seen_at' => now()->subHours(2)]);

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
}
