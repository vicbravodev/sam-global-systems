<?php

namespace Database\Seeders;

use App\Domains\Integrations\Enums\IntegrationProviderStatus;
use App\Domains\Integrations\Enums\IntegrationProviderType;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Normalization\Models\EventCategory;
use App\Domains\Normalization\Models\EventMappingRule;
use App\Domains\Normalization\Models\EventSeverity;
use App\Domains\Normalization\Models\EventType;
use Illuminate\Database\Seeder;

class NormalizationSeeder extends Seeder
{
    public function run(): void
    {
        $categories = $this->seedCategories();
        $severities = $this->seedSeverities();
        $eventTypes = $this->seedEventTypes($categories, $severities);
        $this->seedSamsaraMappingRules($eventTypes);
    }

    /**
     * @return array<string, EventCategory>
     */
    private function seedCategories(): array
    {
        $definitions = [
            ['code' => 'safety', 'name' => 'Safety', 'description' => 'Safety-related events involving driver or vehicle safety risks'],
            ['code' => 'emergency', 'name' => 'Emergency', 'description' => 'Critical emergency events requiring immediate response'],
            ['code' => 'compliance', 'name' => 'Compliance', 'description' => 'Regulatory and policy compliance violations'],
            ['code' => 'operational', 'name' => 'Operational', 'description' => 'General operational events for fleet monitoring'],
            ['code' => 'maintenance', 'name' => 'Maintenance', 'description' => 'Equipment and device maintenance events'],
        ];

        $categories = [];
        foreach ($definitions as $def) {
            $categories[$def['code']] = EventCategory::firstOrCreate(
                ['code' => $def['code']],
                ['name' => $def['name'], 'description' => $def['description']],
            );
        }

        return $categories;
    }

    /**
     * @return array<string, EventSeverity>
     */
    private function seedSeverities(): array
    {
        $definitions = [
            ['code' => 'low', 'label' => 'Low', 'level' => 1, 'color' => '#22c55e', 'response_sla_seconds' => null],
            ['code' => 'medium', 'label' => 'Medium', 'level' => 2, 'color' => '#f59e0b', 'response_sla_seconds' => 3600],
            ['code' => 'high', 'label' => 'High', 'level' => 3, 'color' => '#f97316', 'response_sla_seconds' => 900],
            ['code' => 'critical', 'label' => 'Critical', 'level' => 4, 'color' => '#ef4444', 'response_sla_seconds' => 300],
        ];

        $severities = [];
        foreach ($definitions as $def) {
            $severities[$def['code']] = EventSeverity::firstOrCreate(
                ['code' => $def['code']],
                [
                    'label' => $def['label'],
                    'level' => $def['level'],
                    'color' => $def['color'],
                    'response_sla_seconds' => $def['response_sla_seconds'],
                ],
            );
        }

        return $severities;
    }

    /**
     * @param  array<string, EventCategory>  $categories
     * @param  array<string, EventSeverity>  $severities
     * @return array<string, EventType>
     */
    private function seedEventTypes(array $categories, array $severities): array
    {
        $definitions = [
            // Emergency
            ['code' => 'panic_button', 'name' => 'Panic Button', 'category' => 'emergency', 'severity' => 'critical'],
            ['code' => 'collision', 'name' => 'Collision', 'category' => 'emergency', 'severity' => 'critical'],
            ['code' => 'rollover_protection', 'name' => 'Rollover Protection', 'category' => 'emergency', 'severity' => 'critical'],

            // Safety
            ['code' => 'harsh_braking', 'name' => 'Harsh Braking', 'category' => 'safety', 'severity' => 'medium'],
            ['code' => 'speeding', 'name' => 'Speeding', 'category' => 'safety', 'severity' => 'medium'],
            ['code' => 'severe_speeding', 'name' => 'Severe Speeding', 'category' => 'safety', 'severity' => 'high'],
            ['code' => 'driver_fatigue', 'name' => 'Driver Fatigue', 'category' => 'safety', 'severity' => 'high'],
            ['code' => 'driver_distraction', 'name' => 'Driver Distraction', 'category' => 'safety', 'severity' => 'high'],
            ['code' => 'forward_collision_warning', 'name' => 'Forward Collision Warning', 'category' => 'safety', 'severity' => 'high'],
            ['code' => 'harsh_acceleration', 'name' => 'Harsh Acceleration', 'category' => 'safety', 'severity' => 'medium'],
            ['code' => 'harsh_turn', 'name' => 'Harsh Turn', 'category' => 'safety', 'severity' => 'medium'],
            ['code' => 'lane_departure', 'name' => 'Lane Departure', 'category' => 'safety', 'severity' => 'medium'],
            ['code' => 'following_distance', 'name' => 'Following Distance', 'category' => 'safety', 'severity' => 'medium'],
            ['code' => 'near_collision', 'name' => 'Near Collision', 'category' => 'safety', 'severity' => 'high'],
            ['code' => 'aggressive_driving', 'name' => 'Aggressive Driving', 'category' => 'safety', 'severity' => 'high'],
            ['code' => 'rolling_stop', 'name' => 'Rolling Stop', 'category' => 'safety', 'severity' => 'medium'],
            ['code' => 'ran_red_light', 'name' => 'Ran Red Light', 'category' => 'safety', 'severity' => 'high'],
            ['code' => 'mobile_usage', 'name' => 'Mobile Usage', 'category' => 'safety', 'severity' => 'high'],
            ['code' => 'yaw_control', 'name' => 'Yaw Control', 'category' => 'safety', 'severity' => 'high'],
            ['code' => 'reversing', 'name' => 'Reversing', 'category' => 'safety', 'severity' => 'low'],
            ['code' => 'u_turn', 'name' => 'U-Turn', 'category' => 'safety', 'severity' => 'medium'],
            ['code' => 'did_not_yield', 'name' => 'Did Not Yield', 'category' => 'safety', 'severity' => 'high'],
            ['code' => 'railroad_crossing_violation', 'name' => 'Railroad Crossing Violation', 'category' => 'safety', 'severity' => 'high'],
            ['code' => 'other_violation', 'name' => 'Other Violation', 'category' => 'safety', 'severity' => 'medium'],

            // Compliance
            ['code' => 'camera_obstructed', 'name' => 'Camera Obstructed', 'category' => 'compliance', 'severity' => 'high'],
            ['code' => 'tampering', 'name' => 'Tampering', 'category' => 'compliance', 'severity' => 'critical'],
            ['code' => 'no_seatbelt', 'name' => 'No Seatbelt', 'category' => 'compliance', 'severity' => 'medium'],
            ['code' => 'hos_violation', 'name' => 'HOS Violation', 'category' => 'compliance', 'severity' => 'high'],
            ['code' => 'smoking_drinking', 'name' => 'Smoking or Drinking', 'category' => 'compliance', 'severity' => 'low'],
            ['code' => 'policy_violation', 'name' => 'Policy Violation', 'category' => 'compliance', 'severity' => 'medium'],
            ['code' => 'unauthorized_passenger', 'name' => 'Unauthorized Passenger', 'category' => 'compliance', 'severity' => 'medium'],

            // Operational
            ['code' => 'geofence_exit', 'name' => 'Geofence Exit', 'category' => 'operational', 'severity' => 'low'],
            ['code' => 'geofence_entry', 'name' => 'Geofence Entry', 'category' => 'operational', 'severity' => 'low'],
            ['code' => 'vehicle_idle', 'name' => 'Vehicle Idle', 'category' => 'operational', 'severity' => 'low'],
            ['code' => 'unsafe_parking', 'name' => 'Unsafe Parking', 'category' => 'operational', 'severity' => 'low'],
            ['code' => 'driving_context', 'name' => 'Driving Context', 'category' => 'operational', 'severity' => 'low'],
            ['code' => 'defensive_driving', 'name' => 'Defensive Driving', 'category' => 'operational', 'severity' => 'low'],

            // Maintenance
            ['code' => 'device_offline', 'name' => 'Device Offline', 'category' => 'maintenance', 'severity' => 'medium'],

            // Internal monitors (Roadmap V2-C2/C3)
            ['code' => 'after_hours_movement', 'name' => 'After-Hours Movement', 'category' => 'operational', 'severity' => 'high'],
            ['code' => 'suspicious_stop', 'name' => 'Suspicious Stop', 'category' => 'operational', 'severity' => 'high'],
        ];

        $eventTypes = [];
        foreach ($definitions as $def) {
            $eventTypes[$def['code']] = EventType::firstOrCreate(
                ['code' => $def['code']],
                [
                    'name' => $def['name'],
                    'category_id' => $categories[$def['category']]->id,
                    'default_severity_id' => $severities[$def['severity']]->id,
                    'is_active' => true,
                ],
            );
        }

        return $eventTypes;
    }

    /**
     * @param  array<string, EventType>  $eventTypes
     */
    private function seedSamsaraMappingRules(array $eventTypes): void
    {
        // Create the provider if no other seeder has yet — the mapping rules
        // must exist regardless of seeder ordering (a fresh --seed used to
        // skip them silently because SamsaraTestSeeder runs after this one).
        $samsara = IntegrationProvider::query()->firstOrCreate(
            ['code' => 'samsara'],
            [
                'name' => 'Samsara',
                'type' => IntegrationProviderType::Telematics,
                'status' => IntegrationProviderStatus::Active,
                'capabilities_json' => ['gps', 'diagnostics', 'driver_behavior'],
            ],
        );

        // AlertIncident rules (webhook, with conditions)
        $alertIncidentRules = [
            ['conditions' => ['data.conditions.0.description' => 'Panic Button'], 'type' => 'panic_button', 'priority' => 10],
            ['conditions' => ['data.conditions.0.description' => 'Camera Obstructed'], 'type' => 'camera_obstructed', 'priority' => 10],
            ['conditions' => ['data.conditions.0.description' => 'Tampering'], 'type' => 'tampering', 'priority' => 10],
        ];

        foreach ($alertIncidentRules as $rule) {
            if (! isset($eventTypes[$rule['type']])) {
                continue;
            }

            // Match on scalar columns only — never put the jsonb
            // external_conditions_json in the WHERE clause (Postgres can't bind
            // an array against jsonb and throws). Each AlertIncident rule maps
            // to a distinct event type, so (provider, external_event_type,
            // mapped_event_type_id) is a stable unique key.
            EventMappingRule::firstOrCreate(
                [
                    'provider_id' => $samsara->id,
                    'external_event_type' => 'AlertIncident',
                    'mapped_event_type_id' => $eventTypes[$rule['type']]->id,
                ],
                [
                    'external_conditions_json' => $rule['conditions'],
                    'priority' => $rule['priority'],
                    'is_active' => true,
                ],
            );
        }

        // Safety Event behavior label rules (stream API, direct match).
        // Covers the FULL official enum of GET /safety-events/stream
        // (SafetyEventV2BehaviorLabels) — Samsara warns the list changes over
        // time, so unmapped labels still land as `unmapped` for triage, but
        // every label documented as of 2026-06 has an explicit translation.
        $behaviorLabelMappings = [
            'MaxSpeed' => 'speeding',
            'Speeding' => 'speeding',
            'HeavySpeeding' => 'speeding',
            'ModerateSpeeding' => 'speeding',
            'LightSpeeding' => 'speeding',
            'SevereSpeeding' => 'severe_speeding',
            'Crash' => 'collision',
            'Braking' => 'harsh_braking',
            'Acceleration' => 'harsh_acceleration',
            'HarshTurn' => 'harsh_turn',
            'Drowsy' => 'driver_fatigue',
            'GenericDistraction' => 'driver_distraction',
            'EdgeDistractedDriving' => 'driver_distraction',
            'MobileUsage' => 'mobile_usage',
            'ForwardCollisionWarning' => 'forward_collision_warning',
            'LaneDeparture' => 'lane_departure',
            'FollowingDistance' => 'following_distance',
            'FollowingDistanceModerate' => 'following_distance',
            'FollowingDistanceSevere' => 'following_distance',
            'NoSeatbelt' => 'no_seatbelt',
            'ObstructedCamera' => 'camera_obstructed',
            'Idling' => 'vehicle_idle',
            'NearCollison' => 'near_collision',
            'NearPedestrianCollision' => 'near_collision',
            'VulnerableRoadUserCollisionWarning' => 'near_collision',
            'RollingStop' => 'rolling_stop',
            'RanRedLight' => 'ran_red_light',
            'HosViolation' => 'hos_violation',
            'AggressiveDriving' => 'aggressive_driving',
            'Smoking' => 'smoking_drinking',
            'Eating' => 'smoking_drinking',
            'EatingDrinking' => 'smoking_drinking',
            'Drinking' => 'smoking_drinking',
            'RolloverProtection' => 'rollover_protection',
            'Reversing' => 'reversing',
            'UTurn' => 'u_turn',
            'YawControl' => 'yaw_control',
            'UnsafeParking' => 'unsafe_parking',
            'BluetoothHeadset' => 'driver_distraction',
            'LateResponse' => 'driver_distraction',
            'GenericTailgating' => 'following_distance',
            'LeftTurn' => 'harsh_turn',
            'DidNotYield' => 'did_not_yield',
            'EdgeRailroadCrossingViolation' => 'railroad_crossing_violation',
            // HarshImpact is a confirmed high-G impact — treat as collision.
            'HarshImpact' => 'collision',
            // Camera/gateway dropping at highway speed is a tamper signal.
            'HighSpeedSuddenDisconnect' => 'tampering',
            'RearCollisionWarning' => 'near_collision',
            'VehicleInBlindSpotWarning' => 'near_collision',
            'UnsafeManeuver' => 'aggressive_driving',
            'Passenger' => 'unauthorized_passenger',
            'PolicyViolationMask' => 'policy_violation',
            'ProtectiveEquipment' => 'policy_violation',
            'OtherViolation' => 'other_violation',
            // Events re-labeled Invalid by safety admins still flow through
            // (low-stakes type) so the audit trail stays complete.
            'Invalid' => 'other_violation',
            // Road-condition context labels and positive recognitions are not
            // violations: low-severity operational types keep them out of the
            // incident funnel while preserving the data.
            'ContextConstructionOrWorkZone' => 'driving_context',
            'ContextSnowyOrIcy' => 'driving_context',
            'ContextVulnerableRoadUser' => 'driving_context',
            'ContextWet' => 'driving_context',
            'OperationalEvent' => 'driving_context',
            'DefensiveDriving' => 'defensive_driving',
        ];

        foreach ($behaviorLabelMappings as $label => $typeCode) {
            if (! isset($eventTypes[$typeCode])) {
                continue;
            }

            EventMappingRule::firstOrCreate(
                [
                    'provider_id' => $samsara->id,
                    'external_event_type' => $label,
                ],
                [
                    'mapped_event_type_id' => $eventTypes[$typeCode]->id,
                    'priority' => 0,
                    'is_active' => true,
                ],
            );
        }
    }
}
