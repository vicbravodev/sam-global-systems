<?php

namespace Tests\Feature\Domains\Context;

use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetExternalReference;
use App\Domains\Assets\Models\AssetLocationSnapshot;
use App\Domains\Context\Actions\BuildEventContext;
use App\Domains\Context\Actions\FetchLiveLocationForEvent;
use App\Domains\Context\Events\EventContextBuilt;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Normalization\Models\EventSeverity;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\TenantConfig\Enums\SettingGroup;
use App\Domains\TenantConfig\Enums\SettingValueType;
use App\Domains\TenantConfig\Models\TenantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class FetchLiveLocationForEventTest extends TestCase
{
    use AssertsSystemLog;
    use RefreshDatabase;

    private int $teamId;

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([EventContextBuilt::class]);

        $user = User::factory()->create();
        $this->teamId = $user->currentTeam->id;
        $this->asset = Asset::factory()->create(['team_id' => $this->teamId]);
    }

    public function test_critical_event_with_stale_location_fetches_live_position(): void
    {
        $this->makeStaleLocation();
        $this->makeSamsaraIntegration();
        $this->fakeLiveLocationResponse();

        $snapshot = app(BuildEventContext::class)->execute($this->makeEvent(severityCode: 'critical'));

        $this->assertSame('live_fetch', $snapshot->location_snapshot_json['source']);
        $this->assertEqualsWithDelta(19.4326077, $snapshot->location_snapshot_json['latitude'], 0.0000001);
        $this->assertEqualsWithDelta(-99.133208, $snapshot->location_snapshot_json['longitude'], 0.0000001);
        $this->assertFalse($snapshot->telemetry_snapshot_json['position_stale']);

        // The fresh position is persisted so it also feeds the fleet map.
        $this->assertSame(2, AssetLocationSnapshot::where('asset_id', $this->asset->id)->count());

        Http::assertSent(fn ($request) => str_contains($request->url(), '/fleet/vehicles/locations')
            && str_contains($request->url(), 'vehicleIds=veh-1'));
    }

    public function test_non_critical_event_never_fetches(): void
    {
        $this->makeStaleLocation();
        $this->makeSamsaraIntegration();
        Http::fake();

        $snapshot = app(BuildEventContext::class)->execute($this->makeEvent(severityCode: 'high'));

        Http::assertNothingSent();
        $this->assertSame('asset_latest_location', $snapshot->location_snapshot_json['source']);
        $this->assertFalse($snapshot->telemetry_snapshot_json['position_stale']);
    }

    public function test_fresh_location_within_staleness_threshold_skips_fetch(): void
    {
        AssetLocationSnapshot::factory()->create([
            'asset_id' => $this->asset->id,
            'recorded_at' => now()->subSeconds(10),
        ]);
        $this->makeSamsaraIntegration();
        Http::fake();

        $snapshot = app(BuildEventContext::class)->execute($this->makeEvent(severityCode: 'critical'));

        Http::assertNothingSent();
        $this->assertSame('asset_latest_location', $snapshot->location_snapshot_json['source']);
    }

    public function test_tenant_staleness_setting_is_respected(): void
    {
        $this->makeStaleLocation(minutesAgo: 10);
        $this->makeSamsaraIntegration();
        Http::fake();

        TenantSetting::factory()->create([
            'team_id' => $this->teamId,
            'setting_key' => FetchLiveLocationForEvent::SETTING_KEY,
            'setting_group' => SettingGroup::Operational,
            'value_json' => ['value' => 3600],
            'value_type' => SettingValueType::Number,
        ]);

        app(BuildEventContext::class)->execute($this->makeEvent(severityCode: 'critical'));

        Http::assertNothingSent();
    }

    public function test_event_with_inline_payload_gps_skips_fetch(): void
    {
        $this->makeStaleLocation();
        $this->makeSamsaraIntegration();
        Http::fake();

        $event = $this->makeEvent(severityCode: 'critical', payload: [
            'event_type' => 'panic_button',
            'location' => ['latitude' => 19.0, 'longitude' => -98.0],
        ]);

        $snapshot = app(BuildEventContext::class)->execute($event);

        Http::assertNothingSent();
        $this->assertSame('event_payload', $snapshot->location_snapshot_json['source']);
    }

    public function test_provider_timeout_falls_back_to_latest_location_with_stale_flag(): void
    {
        $this->makeStaleLocation();
        $this->makeSamsaraIntegration();
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

        $snapshot = app(BuildEventContext::class)->execute($this->makeEvent(severityCode: 'critical'));

        $this->assertSame('asset_latest_location', $snapshot->location_snapshot_json['source']);
        $this->assertTrue($snapshot->telemetry_snapshot_json['position_stale']);
        $this->assertTrue($snapshot->signals_json['gps_signal_weak']);
        $this->assertSame(1, AssetLocationSnapshot::where('asset_id', $this->asset->id)->count());
    }

    public function test_integration_of_another_tenant_is_never_used(): void
    {
        $this->makeStaleLocation();

        $otherUser = User::factory()->create();
        $this->makeSamsaraIntegration(teamId: $otherUser->currentTeam->id);
        Http::fake();

        $snapshot = app(BuildEventContext::class)->execute($this->makeEvent(severityCode: 'critical'));

        Http::assertNothingSent();
        $this->assertSame('asset_latest_location', $snapshot->location_snapshot_json['source']);
        $this->assertTrue($snapshot->telemetry_snapshot_json['position_stale']);
    }

    public function test_logs_skip_when_event_has_no_asset(): void
    {
        $event = NormalizedEvent::factory()->create(['team_id' => $this->teamId, 'asset_id' => null]);

        app(FetchLiveLocationForEvent::class)->execute($event);

        $c = $this->assertSystemLogged('context.live_location.skipped', fn (array $c) => $c['reason'] === 'no_asset');
        $this->assertSame($event->id, $c['input']['normalized_event_id']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_logs_skip_when_severity_is_not_critical(): void
    {
        $event = $this->makeEvent(severityCode: 'high');

        app(FetchLiveLocationForEvent::class)->execute($event);

        $c = $this->assertSystemLogged('context.live_location.skipped', fn (array $c) => $c['reason'] === 'not_critical');
        $this->assertSame('high', $c['input']['severity_code']);
    }

    public function test_logs_skip_when_payload_has_gps_without_coordinates(): void
    {
        $event = $this->makeEvent(severityCode: 'critical', payload: [
            'event_type' => 'panic_button',
            'location' => ['latitude' => 19.123456, 'longitude' => -98.654321],
        ]);

        app(FetchLiveLocationForEvent::class)->execute($event);

        $this->assertSystemLogged('context.live_location.skipped', fn (array $c) => $c['reason'] === 'payload_has_gps');
        $json = json_encode($this->systemLogEntries());
        $this->assertStringNotContainsString('19.123456', $json);
        $this->assertStringNotContainsString('98.654321', $json);
    }

    public function test_logs_skip_when_latest_location_is_fresh(): void
    {
        $this->travelTo(now()->startOfSecond());
        AssetLocationSnapshot::factory()->create([
            'asset_id' => $this->asset->id,
            'recorded_at' => now()->subSeconds(10),
        ]);

        app(FetchLiveLocationForEvent::class)->execute($this->makeEvent(severityCode: 'critical'));

        $c = $this->assertSystemLogged('context.live_location.skipped', fn (array $c) => $c['reason'] === 'latest_location_fresh');
        $this->assertSame(10, $c['calc']['latest_age_seconds']);
        $this->assertSame(60, $c['calc']['staleness_threshold_seconds']);
    }

    public function test_logs_fetched_with_ages_and_no_coordinates(): void
    {
        $this->travelTo(now()->startOfSecond());
        $this->makeStaleLocation(minutesAgo: 10);
        $this->makeSamsaraIntegration();
        $this->fakeLiveLocationResponse();

        $result = app(FetchLiveLocationForEvent::class)->execute($this->makeEvent(severityCode: 'critical'));

        $this->assertSame('live_fetch', $result['location']['source']);
        $this->assertFalse($result['position_stale']);
        $c = $this->assertSystemLogged('context.live_location.fetched');
        $this->assertSame(600, $c['calc']['latest_age_seconds']);
        $this->assertSame(60, $c['calc']['staleness_threshold_seconds']);
        $this->assertSame(0, $c['calc']['fix_age_seconds']);
        $this->assertFalse($c['result']['position_stale']);
        $this->assertTrue($c['result']['snapshot_updated']);
        $this->assertNoSensitiveDataLogged();
        $json = json_encode($this->systemLogEntries());
        $this->assertStringNotContainsString('19.4326077', $json);
        $this->assertStringNotContainsString('99.133208', $json);
    }

    public function test_logs_failed_when_provider_returns_nothing(): void
    {
        $this->makeStaleLocation();
        $this->makeSamsaraIntegration();
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

        app(FetchLiveLocationForEvent::class)->execute($this->makeEvent(severityCode: 'critical'));

        $c = $this->assertSystemLogged('context.live_location.failed', fn (array $c) => $c['reason'] === 'provider_returned_nothing');
        $this->assertSame(1, $c['calc']['references']);
        $this->assertSame(1, $c['calc']['integrations_tried']);
        $this->assertSame(60, $c['calc']['staleness_threshold_seconds']);
        $this->assertTrue($c['result']['position_stale']);
        $this->assertSame($this->asset->id, $c['input']['asset_id']);
    }

    public function test_logs_failed_without_active_integration(): void
    {
        $this->makeStaleLocation();
        $otherUser = User::factory()->create();
        $this->makeSamsaraIntegration(teamId: $otherUser->currentTeam->id);
        Http::fake();

        app(FetchLiveLocationForEvent::class)->execute($this->makeEvent(severityCode: 'critical'));

        $c = $this->assertSystemLogged('context.live_location.failed', fn (array $c) => $c['reason'] === 'no_active_integration');
        $this->assertSame(1, $c['calc']['references']);
        $this->assertSame(0, $c['calc']['integrations_tried']);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function makeEvent(string $severityCode, ?array $payload = null): NormalizedEvent
    {
        $severity = EventSeverity::query()->firstOrCreate(
            ['code' => $severityCode],
            ['label' => ucfirst($severityCode), 'level' => $severityCode === 'critical' ? 4 : 3, 'color' => '#ef4444'],
        );

        return NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'asset_id' => $this->asset->id,
            'event_severity_id' => $severity->id,
            'occurred_at' => now(),
            'payload_normalized_json' => $payload ?? ['event_type' => 'panic_button'],
        ]);
    }

    private function makeStaleLocation(int $minutesAgo = 10): AssetLocationSnapshot
    {
        return AssetLocationSnapshot::factory()->create([
            'asset_id' => $this->asset->id,
            'recorded_at' => now()->subMinutes($minutesAgo),
        ]);
    }

    private function makeSamsaraIntegration(?int $teamId = null): TenantIntegration
    {
        $provider = IntegrationProvider::factory()->samsara()->create();

        $integration = TenantIntegration::factory()->active()->create([
            'team_id' => $teamId ?? $this->teamId,
            'provider_id' => $provider->id,
            'credentials_encrypted' => '',
        ]);

        IntegrationCredential::factory()->create([
            'tenant_integration_id' => $integration->id,
            'key' => 'api_token',
            'value_encrypted' => 'sk-test-token',
        ]);

        AssetExternalReference::factory()->create([
            'asset_id' => $this->asset->id,
            'provider_id' => $provider->id,
            'external_id' => 'veh-1',
        ]);

        return $integration;
    }

    private function fakeLiveLocationResponse(): void
    {
        Http::fake([
            'api.samsara.com/fleet/vehicles/locations*' => Http::response([
                'data' => [
                    [
                        'id' => 'veh-1',
                        'location' => [
                            'latitude' => 19.4326077,
                            'longitude' => -99.133208,
                            'speed' => 0.0,
                            'heading' => 90,
                            'time' => now()->toIso8601String(),
                        ],
                    ],
                ],
            ], 200),
        ]);
    }
}
