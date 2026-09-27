<?php

namespace Tests\Feature\Domains\AI;

use App\Domains\AI\Actions\BuildAIInputContext;
use App\Domains\AI\Data\TenantAIProfileData;
use App\Domains\Assets\Models\Asset;
use App\Domains\Context\Enums\RiskLevel;
use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Context\Models\OperationalContextProfile;
use App\Domains\Drivers\Enums\RiskLevel as DriverRiskLevel;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverRiskProfile;
use App\Domains\Normalization\Models\EventCategory;
use App\Domains\Normalization\Models\EventSeverity;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

class BuildAIInputContextTest extends TestCase
{
    use AssertsTenantIsolation, RefreshDatabase;

    public function test_event_block_carries_type_category_severity_and_local_time(): void
    {
        $team = Team::factory()->create(['timezone' => null]);
        $event = $this->makeEvent($team, occurredAt: Carbon::parse('2026-09-25 03:30:00', 'UTC'));

        $payload = $this->build($event)->toArray();

        $this->assertSame('panic_button', $payload['normalized_event']['type_code']);
        $this->assertSame('Botón de pánico', $payload['normalized_event']['type_name']);
        $this->assertSame('emergency', $payload['normalized_event']['category']);
        $this->assertSame('critical', $payload['normalized_event']['severity']);
        // 03:30 UTC → 21:30 del día anterior en Ciudad de México (UTC-6).
        $this->assertSame('2026-09-24 21:30:00', $payload['normalized_event']['occurred_at_local']);
        $this->assertSame('America/Mexico_City', $payload['normalized_event']['local_timezone']);
        $this->assertSame('thursday', $payload['normalized_event']['local_day_of_week']);
    }

    public function test_team_timezone_is_used_when_set(): void
    {
        $team = Team::factory()->create(['timezone' => 'America/Tijuana']);
        $event = $this->makeEvent($team, occurredAt: Carbon::parse('2026-09-25 03:30:00', 'UTC'));

        $payload = $this->build($event)->toArray();

        $this->assertSame('America/Tijuana', $payload['normalized_event']['local_timezone']);
        $this->assertSame('2026-09-24 20:30:00', $payload['normalized_event']['occurred_at_local']);
    }

    public function test_operational_profile_comes_from_the_context_model(): void
    {
        $team = Team::factory()->create();
        $event = $this->makeEvent($team);

        OperationalContextProfile::factory()->create([
            'team_id' => $team->id,
            'normalized_event_id' => $event->id,
            'profile_code' => 'panic_on_road',
            'risk_level' => RiskLevel::High,
            'priority_score' => 82.5,
            'contextual_flags_json' => ['outside_base' => true],
        ]);

        $profile = $this->build($event)->toArray()['operational_profile'];

        $this->assertSame('panic_on_road', $profile['profile_code']);
        $this->assertSame('high', $profile['risk_level']);
        $this->assertSame(82.5, $profile['priority_score']);
        $this->assertSame(['outside_base' => true], $profile['flags']);
    }

    public function test_snapshot_context_is_projected_compactly(): void
    {
        $team = Team::factory()->create();
        $asset = Asset::factory()->create([
            'team_id' => $team->id,
            'name' => 'Tracto 12',
            'code' => 'ABC-123',
            'metadata_json' => ['has_camera' => true],
        ]);
        $driver = Driver::factory()->create([
            'team_id' => $team->id,
            'full_name' => 'Juan Pérez',
            'phone' => '+525512345678',
            'first_seen_at' => now()->subDays(40),
        ]);
        DriverRiskProfile::factory()->create([
            'driver_id' => $driver->id,
            'risk_level' => DriverRiskLevel::Medium,
            'risk_score' => 55,
            'incidents_count' => 2,
        ]);

        $event = $this->makeEvent($team, assetId: $asset->id, driverId: $driver->id);

        $snapshot = EventContextSnapshot::factory()->create([
            'team_id' => $team->id,
            'normalized_event_id' => $event->id,
            'location_snapshot_json' => ['latitude' => 19.4, 'longitude' => -99.1, 'source' => 'event_payload'],
            'geofence_snapshot_json' => [[
                'geofence_id' => 1, 'name' => 'Base Norte', 'code' => 'BN', 'category' => 'base',
                'match_type' => 'inside', 'distance_meters' => 0,
            ]],
            'telemetry_snapshot_json' => ['speed_kph' => 72.0, 'position_stale' => true, 'gps_accuracy_meters' => 15],
            'asset_snapshot_json' => ['name' => 'Tracto 12', 'code' => 'ABC-123', 'status' => 'active', 'has_camera' => true],
            'driver_snapshot_json' => [
                'driver_id' => $driver->id,
                'full_name' => 'Juan Pérez',
                'employee_code' => 'EMP-1',
                'status' => 'active',
                'recent_risk_events_count' => 3,
                'current_assignment' => ['asset_id' => $asset->id],
            ],
            'incidents_snapshot_json' => [
                ['incident_id' => 1, 'type_code' => 'panic_emergency', 'status_code' => 'open', 'relation' => null],
                ['incident_id' => 2, 'type_code' => 'panic_emergency', 'status_code' => 'closed', 'relation' => 'prior_similar_incident'],
            ],
            'recent_history_snapshot_json' => [
                'recent_events_count' => 7,
                'recent_locations' => array_fill(0, 12, ['lat' => 19.4, 'lng' => -99.1]),
            ],
        ]);

        $payload = $this->build($event, $snapshot)->toArray();

        $this->assertEquals(72, $payload['telemetry']['speed_kph']);
        $this->assertTrue($payload['telemetry']['position_stale']);
        $this->assertSame(15, $payload['telemetry']['gps_accuracy_meters']);

        $this->assertSame([[
            'name' => 'Base Norte', 'category' => 'base', 'match_type' => 'inside', 'distance_meters' => 0,
        ]], $payload['location']['geofences']);

        $this->assertSame('ABC-123', $payload['asset']['plate_or_code']);
        $this->assertTrue($payload['asset']['has_camera']);

        $this->assertSame('medium', $payload['driver']['risk_level']);
        $this->assertSame(3, $payload['driver']['recent_risk_events_count']);
        $this->assertSame(40, $payload['driver']['tenure_days']);
        $this->assertTrue($payload['driver']['has_current_assignment']);

        $this->assertSame(1, $payload['incidents']['open_related_count']);
        $this->assertSame(1, $payload['incidents']['prior_similar_count']);

        $this->assertCount(5, $payload['recent_history']['recent_locations']);

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Juan', $encoded);
        $this->assertStringNotContainsString('+525512345678', $encoded);
        $this->assertStringNotContainsString('EMP-1', $encoded);
    }

    public function test_tenant_profile_is_reduced_to_automation_level_and_team_id_is_not_sent(): void
    {
        $team = Team::factory()->create();
        $event = $this->makeEvent($team);

        $payload = $this->build($event)->toArray();

        $this->assertSame(['automation_level' => 'semi'], $payload['tenant_profile']);
        $this->assertArrayNotHasKey('team_id', $payload);
    }

    public function test_pii_is_redacted_recursively_and_presigned_urls_are_dropped(): void
    {
        $team = Team::factory()->create();
        $event = $this->makeEvent($team, payload: [
            'driver' => [
                'name' => 'Juan Pérez',
                'first_name' => 'Juan',
                'last_name' => 'Pérez',
                'phone_number' => '+525512345678',
                'license' => 'LIC-99',
                'id' => 'drv-1',
            ],
            'driver_name' => 'Juan Pérez',
            'media' => [['input' => 'dashcamRoadFacing', 'url' => 'https://media.samsara.com/x.mp4?sig=abc']],
            'downloadForwardVideoUrl' => 'https://media.samsara.com/y.mp4?sig=abc',
            'speed' => 88,
        ]);

        $payload = $this->build($event)->toArray()['normalized_event']['payload'];

        $this->assertSame('[redacted]', $payload['driver']['name']);
        $this->assertSame('[redacted]', $payload['driver']['first_name']);
        $this->assertSame('[redacted]', $payload['driver']['last_name']);
        $this->assertSame('[redacted]', $payload['driver']['phone_number']);
        $this->assertSame('[redacted]', $payload['driver']['license']);
        $this->assertSame('drv-1', $payload['driver']['id']);
        $this->assertSame('[redacted]', $payload['driver_name']);
        $this->assertArrayNotHasKey('url', $payload['media'][0]);
        $this->assertArrayNotHasKey('downloadForwardVideoUrl', $payload);
        $this->assertSame(88, $payload['speed']);
    }

    public function test_building_the_context_does_not_read_another_tenants_profile(): void
    {
        $victim = Team::factory()->create();
        $attacker = Team::factory()->create();

        $event = $this->makeEvent($attacker);

        // Perfil operacional mal atado: mismo evento, otro tenant.
        OperationalContextProfile::factory()->create([
            'team_id' => $victim->id,
            'normalized_event_id' => $event->id,
            'profile_code' => 'victim_profile',
        ]);

        $payload = $this->assertNoTenantLeak($attacker, fn () => $this->build($event)->toArray());

        $this->assertSame([], $payload['operational_profile']);
    }

    private function build(NormalizedEvent $event, ?EventContextSnapshot $snapshot = null)
    {
        return app(BuildAIInputContext::class)->execute(
            $event->fresh(),
            $snapshot?->fresh(),
            new TenantAIProfileData(
                teamId: $event->team_id,
                automationLevel: 'semi',
                monthlyTokenLimit: 1000,
                dailyCallLimit: 10,
                preferredModel: 'x',
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function makeEvent(
        Team $team,
        ?Carbon $occurredAt = null,
        ?int $assetId = null,
        ?int $driverId = null,
        array $payload = ['description' => 'Pánico'],
    ): NormalizedEvent {
        $category = EventCategory::query()->firstOrCreate(['code' => 'emergency'], EventCategory::factory()->raw(['code' => 'emergency']));
        $severity = EventSeverity::query()->firstOrCreate(['code' => 'critical'], EventSeverity::factory()->raw(['code' => 'critical', 'level' => 4]));
        $type = EventType::query()->firstOrCreate(
            ['code' => 'panic_button'],
            EventType::factory()->raw(['code' => 'panic_button', 'name' => 'Botón de pánico', 'category_id' => $category->id, 'default_severity_id' => $severity->id]),
        );

        return NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'asset_id' => $assetId,
            'driver_id' => $driverId,
            'event_type_id' => $type->id,
            'event_category_id' => $category->id,
            'event_severity_id' => $severity->id,
            'occurred_at' => $occurredAt ?? now(),
            'payload_normalized_json' => $payload,
        ]);
    }
}
