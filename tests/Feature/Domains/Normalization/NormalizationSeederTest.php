<?php

namespace Tests\Feature\Domains\Normalization;

use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Normalization\Models\EventCategory;
use App\Domains\Normalization\Models\EventMappingRule;
use App\Domains\Normalization\Models\EventSeverity;
use App\Domains\Normalization\Models\EventType;
use Database\Seeders\NormalizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NormalizationSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_data_creates_categories_and_severities(): void
    {
        $this->seed(NormalizationSeeder::class);

        $expectedCategories = ['safety', 'emergency', 'compliance', 'operational', 'maintenance'];
        foreach ($expectedCategories as $code) {
            $exists = EventCategory::where('code', $code)->exists();
            $this->assertTrue($exists, "EventCategory with code '{$code}' should exist after seeding");
        }

        $this->assertCount(
            count($expectedCategories),
            EventCategory::all(),
            'NormalizationSeeder should create exactly 5 event categories',
        );

        $expectedSeverities = [
            ['code' => 'low', 'level' => 1],
            ['code' => 'medium', 'level' => 2],
            ['code' => 'high', 'level' => 3],
            ['code' => 'critical', 'level' => 4],
        ];

        foreach ($expectedSeverities as $sev) {
            $exists = EventSeverity::where('code', $sev['code'])->where('level', $sev['level'])->exists();
            $this->assertTrue($exists, "EventSeverity with code '{$sev['code']}' and level {$sev['level']} should exist after seeding");
        }

        $this->assertCount(
            4,
            EventSeverity::all(),
            'NormalizationSeeder should create exactly 4 event severities',
        );

        $expectedEventTypes = [
            'panic_button', 'collision', 'rollover_protection',
            'harsh_braking', 'speeding', 'driver_fatigue', 'driver_distraction',
            'forward_collision_warning', 'harsh_acceleration', 'harsh_turn',
            'lane_departure', 'following_distance', 'near_collision',
            'aggressive_driving', 'rolling_stop', 'ran_red_light',
            'mobile_usage', 'yaw_control', 'reversing', 'u_turn',
            'camera_obstructed', 'tampering', 'no_seatbelt', 'hos_violation', 'smoking_drinking',
            'geofence_exit', 'geofence_entry', 'vehicle_idle', 'unsafe_parking',
            'device_offline',
        ];

        foreach ($expectedEventTypes as $code) {
            $exists = EventType::where('code', $code)->exists();
            $this->assertTrue($exists, "EventType with code '{$code}' should exist after seeding");
        }

        $this->assertCount(
            count($expectedEventTypes),
            EventType::all(),
            'NormalizationSeeder should create exactly '.count($expectedEventTypes).' canonical event types',
        );

        $panicType = EventType::where('code', 'panic_button')->first();
        $this->assertEquals(
            'emergency',
            $panicType->category->code,
            'panic_button event type should belong to the emergency category',
        );

        $this->assertEquals(
            'critical',
            EventSeverity::find($panicType->default_severity_id)->code,
            'panic_button event type should have critical as default severity',
        );
    }

    public function test_seed_data_creates_samsara_mapping_rules(): void
    {
        IntegrationProvider::factory()->samsara()->create();

        $this->seed(NormalizationSeeder::class);

        $samsara = IntegrationProvider::where('code', 'samsara')->first();

        $alertIncidentRuleCount = EventMappingRule::where('provider_id', $samsara->id)
            ->where('external_event_type', 'AlertIncident')
            ->count();

        $this->assertGreaterThanOrEqual(
            3,
            $alertIncidentRuleCount,
            'NormalizationSeeder should create at least 3 AlertIncident mapping rules for Samsara (Panic Button, Camera Obstructed, Tampering)',
        );

        $behaviorLabelRuleCount = EventMappingRule::where('provider_id', $samsara->id)
            ->where('external_event_type', '!=', 'AlertIncident')
            ->count();

        $this->assertGreaterThanOrEqual(
            30,
            $behaviorLabelRuleCount,
            'NormalizationSeeder should create at least 30 behavior label mapping rules for Samsara safety events',
        );

        $maxSpeedRule = EventMappingRule::where('provider_id', $samsara->id)
            ->where('external_event_type', 'MaxSpeed')
            ->first();

        $this->assertNotNull(
            $maxSpeedRule,
            'A mapping rule for MaxSpeed behavior label should exist for Samsara',
        );

        $this->assertEquals(
            'speeding',
            $maxSpeedRule->mappedEventType->code,
            'MaxSpeed behavior label should map to the speeding canonical event type',
        );
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(NormalizationSeeder::class);
        $firstRunCount = EventType::count();

        $this->seed(NormalizationSeeder::class);
        $secondRunCount = EventType::count();

        $this->assertEquals(
            $firstRunCount,
            $secondRunCount,
            'Running NormalizationSeeder twice should not duplicate event types — firstOrCreate ensures idempotency',
        );
    }
}
