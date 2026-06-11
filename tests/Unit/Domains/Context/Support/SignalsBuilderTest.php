<?php

namespace Tests\Unit\Domains\Context\Support;

use App\Domains\Context\Enums\GeofenceCategory;
use App\Domains\Context\Enums\GeofenceMatchType;
use App\Domains\Context\Enums\IncidentRelationType;
use App\Domains\Context\Support\SignalsBuilder;
use PHPUnit\Framework\TestCase;

class SignalsBuilderTest extends TestCase
{
    public function test_empty_context_returns_all_signals_with_safe_defaults(): void
    {
        $signals = SignalsBuilder::build([]);

        $this->assertFalse($signals['is_in_sensitive_geofence']);
        $this->assertFalse($signals['has_open_incident']);
        $this->assertFalse($signals['has_prior_similar_incident']);
        $this->assertFalse($signals['same_type_recent_recurrence']);
        $this->assertFalse($signals['driver_has_recent_risk_events']);
        $this->assertFalse($signals['camera_unavailable']);
        $this->assertFalse($signals['gps_signal_weak']);
        $this->assertFalse($signals['outside_operating_hours']);
        $this->assertFalse($signals['asset_recently_stopped']);
        $this->assertFalse($signals['asset_in_motion']);
        $this->assertFalse($signals['driver_unresolved_previous_alert']);
        $this->assertFalse($signals['has_visual_evidence']);
        $this->assertFalse($signals['has_audio_evidence']);
        $this->assertFalse($signals['video_pending']);
        $this->assertFalse($signals['media_delayed']);
        $this->assertTrue($signals['no_media_available']);
        $this->assertFalse($signals['visual_confirmation_possible']);
        $this->assertFalse($signals['external_resolved']);
        $this->assertFalse($signals['parked_at_base']);
        $this->assertFalse($signals['repeated_panic_24h']);
    }

    public function test_is_in_sensitive_geofence_flags_risk_zone_with_inside_match(): void
    {
        $signals = SignalsBuilder::build([
            'geofence_matches' => [
                ['category' => GeofenceCategory::RiskZone, 'match_type' => GeofenceMatchType::Inside],
            ],
        ]);

        $this->assertTrue($signals['is_in_sensitive_geofence']);
    }

    public function test_is_in_sensitive_geofence_accepts_string_values(): void
    {
        $signals = SignalsBuilder::build([
            'geofence_matches' => [
                ['category' => 'border', 'match_type' => 'entry'],
            ],
        ]);

        $this->assertTrue($signals['is_in_sensitive_geofence']);
    }

    public function test_is_in_sensitive_geofence_ignores_non_sensitive_category(): void
    {
        $signals = SignalsBuilder::build([
            'geofence_matches' => [
                ['category' => GeofenceCategory::ClientSite, 'match_type' => GeofenceMatchType::Inside],
            ],
        ]);

        $this->assertFalse($signals['is_in_sensitive_geofence']);
    }

    public function test_is_in_sensitive_geofence_ignores_exit_match(): void
    {
        $signals = SignalsBuilder::build([
            'geofence_matches' => [
                ['category' => GeofenceCategory::RiskZone, 'match_type' => GeofenceMatchType::Exit],
            ],
        ]);

        $this->assertFalse($signals['is_in_sensitive_geofence']);
    }

    public function test_has_open_incident_true_when_incidents_present(): void
    {
        $signals = SignalsBuilder::build([
            'incidents' => [['id' => 1]],
        ]);

        $this->assertTrue($signals['has_open_incident']);
    }

    public function test_prior_similar_incident_rows_do_not_count_as_open(): void
    {
        $signals = SignalsBuilder::build([
            'incidents' => [['id' => 1, 'relation' => IncidentRelationType::PriorSimilarIncident->value]],
        ]);

        $this->assertFalse($signals['has_open_incident']);
        $this->assertTrue($signals['has_prior_similar_incident']);
    }

    public function test_open_and_prior_incident_rows_set_both_flags(): void
    {
        $signals = SignalsBuilder::build([
            'incidents' => [
                ['id' => 1],
                ['id' => 2, 'relation' => IncidentRelationType::PriorSimilarIncident->value],
            ],
        ]);

        $this->assertTrue($signals['has_open_incident']);
        $this->assertTrue($signals['has_prior_similar_incident']);
    }

    public function test_same_type_recent_recurrence_flag(): void
    {
        $signals = SignalsBuilder::build([
            'recent_history' => ['recent_same_type_count' => 3],
        ]);

        $this->assertTrue($signals['same_type_recent_recurrence']);
    }

    public function test_driver_recent_risk_events_flag(): void
    {
        $signals = SignalsBuilder::build([
            'driver' => ['has_recent_risk_events' => true, 'has_unresolved_alerts' => true],
        ]);

        $this->assertTrue($signals['driver_has_recent_risk_events']);
        $this->assertTrue($signals['driver_unresolved_previous_alert']);
    }

    public function test_camera_unavailable_flag(): void
    {
        $offline = SignalsBuilder::build(['asset' => ['camera_status' => 'offline']]);
        $obstructed = SignalsBuilder::build(['asset' => ['camera_status' => 'obstructed']]);
        $ok = SignalsBuilder::build(['asset' => ['camera_status' => 'available']]);

        $this->assertTrue($offline['camera_unavailable']);
        $this->assertTrue($obstructed['camera_unavailable']);
        $this->assertFalse($ok['camera_unavailable']);
    }

    public function test_gps_signal_weak_by_accuracy_and_staleness(): void
    {
        $weak = SignalsBuilder::build(['telemetry' => ['gps_accuracy_meters' => 120.0]]);
        $stale = SignalsBuilder::build(['telemetry' => ['gps_accuracy_meters' => 5.0, 'position_stale' => true]]);
        $good = SignalsBuilder::build(['telemetry' => ['gps_accuracy_meters' => 5.0]]);

        $this->assertTrue($weak['gps_signal_weak']);
        $this->assertTrue($stale['gps_signal_weak']);
        $this->assertFalse($good['gps_signal_weak']);
    }

    public function test_asset_motion_signals(): void
    {
        $moving = SignalsBuilder::build(['telemetry' => ['speed_kph' => 40.0]]);
        $stopped = SignalsBuilder::build(['telemetry' => ['speed_kph' => 0.0]]);

        $this->assertTrue($moving['asset_in_motion']);
        $this->assertFalse($moving['asset_recently_stopped']);
        $this->assertFalse($stopped['asset_in_motion']);
        $this->assertTrue($stopped['asset_recently_stopped']);
    }

    public function test_asset_recently_stopped_uses_recent_speeds_when_available(): void
    {
        $signals = SignalsBuilder::build([
            'telemetry' => ['speed_kph' => 50.0, 'recent_speeds' => [60.0, 55.0, 0.0]],
        ]);

        $this->assertTrue($signals['asset_recently_stopped']);
        $this->assertTrue($signals['asset_in_motion']);
    }

    public function test_recent_speeds_all_nonzero_does_not_flag_stopped(): void
    {
        $signals = SignalsBuilder::build([
            'telemetry' => ['speed_kph' => 50.0, 'recent_speeds' => [60.0, 55.0, 50.0]],
        ]);

        $this->assertFalse($signals['asset_recently_stopped']);
    }

    public function test_outside_operating_hours_passthrough(): void
    {
        $signals = SignalsBuilder::build(['outside_operating_hours' => true]);

        $this->assertTrue($signals['outside_operating_hours']);
    }

    public function test_visual_evidence_flag_from_media(): void
    {
        $signals = SignalsBuilder::build([
            'media' => [
                ['type' => 'video', 'retrieval_status' => 'available'],
            ],
        ]);

        $this->assertTrue($signals['has_visual_evidence']);
        $this->assertFalse($signals['no_media_available']);
    }

    public function test_audio_evidence_flag_from_media(): void
    {
        $signals = SignalsBuilder::build([
            'media' => [
                ['type' => 'audio', 'retrieval_status' => 'available'],
            ],
        ]);

        $this->assertTrue($signals['has_audio_evidence']);
    }

    public function test_video_pending_and_media_delayed(): void
    {
        $pending = SignalsBuilder::build(['media' => [['type' => 'video', 'retrieval_status' => 'processing']]]);
        $delayed = SignalsBuilder::build(['media' => [['type' => 'video', 'retrieval_status' => 'delayed']]]);

        $this->assertTrue($pending['video_pending']);
        $this->assertTrue($delayed['media_delayed']);
    }

    public function test_no_media_available_true_when_all_not_available(): void
    {
        $signals = SignalsBuilder::build([
            'media' => [
                ['type' => 'video', 'retrieval_status' => 'not_available'],
            ],
        ]);

        $this->assertTrue($signals['no_media_available']);
    }

    public function test_visual_confirmation_possible_when_camera_present_and_no_media_yet(): void
    {
        $signals = SignalsBuilder::build([
            'asset' => ['has_camera' => true, 'camera_status' => 'available'],
            'media' => [],
        ]);

        $this->assertTrue($signals['visual_confirmation_possible']);
    }

    public function test_visual_confirmation_not_possible_when_camera_offline(): void
    {
        $signals = SignalsBuilder::build([
            'asset' => ['has_camera' => true, 'camera_status' => 'offline'],
        ]);

        $this->assertFalse($signals['visual_confirmation_possible']);
    }

    public function test_visual_confirmation_not_possible_when_no_camera(): void
    {
        $signals = SignalsBuilder::build([
            'asset' => ['has_camera' => false],
        ]);

        $this->assertFalse($signals['visual_confirmation_possible']);
    }

    public function test_visual_confirmation_not_possible_when_visual_already_available(): void
    {
        $signals = SignalsBuilder::build([
            'asset' => ['has_camera' => true, 'camera_status' => 'available'],
            'media' => [['type' => 'image', 'retrieval_status' => 'available']],
        ]);

        $this->assertFalse($signals['visual_confirmation_possible']);
    }

    public function test_external_resolved_only_for_explicit_true(): void
    {
        $this->assertTrue(SignalsBuilder::build(['event' => ['is_resolved' => true]])['external_resolved']);
        $this->assertFalse(SignalsBuilder::build(['event' => ['is_resolved' => false]])['external_resolved']);
        $this->assertFalse(SignalsBuilder::build(['event' => ['is_resolved' => null]])['external_resolved']);
        $this->assertFalse(SignalsBuilder::build(['event' => ['is_resolved' => 'yes']])['external_resolved']);
    }

    public function test_parked_at_base_requires_base_geofence_and_zero_speed(): void
    {
        $insideBase = [
            'geofence_matches' => [
                ['category' => GeofenceCategory::Base, 'match_type' => GeofenceMatchType::Inside],
            ],
        ];

        $this->assertTrue(SignalsBuilder::build([
            ...$insideBase,
            'telemetry' => ['speed_kph' => 0.0],
        ])['parked_at_base']);

        // Distribution centers count as the fleet's base too.
        $this->assertTrue(SignalsBuilder::build([
            'geofence_matches' => [
                ['category' => 'distribution_center', 'match_type' => 'inside'],
            ],
            'telemetry' => ['speed_kph' => 0.5],
        ])['parked_at_base']);

        // Moving inside the base is not parked.
        $this->assertFalse(SignalsBuilder::build([
            ...$insideBase,
            'telemetry' => ['speed_kph' => 12.0],
        ])['parked_at_base']);

        // Unknown speed never counts as parked.
        $this->assertFalse(SignalsBuilder::build($insideBase)['parked_at_base']);

        // Parked inside a non-base geofence is not "at base".
        $this->assertFalse(SignalsBuilder::build([
            'geofence_matches' => [
                ['category' => GeofenceCategory::RiskZone, 'match_type' => GeofenceMatchType::Inside],
            ],
            'telemetry' => ['speed_kph' => 0.0],
        ])['parked_at_base']);
    }

    public function test_repeated_panic_24h_requires_more_than_one(): void
    {
        $this->assertFalse(SignalsBuilder::build([
            'recent_history' => ['repeated_panic_count_24h' => 1],
        ])['repeated_panic_24h']);

        $this->assertTrue(SignalsBuilder::build([
            'recent_history' => ['repeated_panic_count_24h' => 2],
        ])['repeated_panic_24h']);
    }

    public function test_harsh_driving_near_event_passthrough(): void
    {
        $this->assertFalse(SignalsBuilder::build([])['harsh_driving_near_event']);

        $this->assertTrue(SignalsBuilder::build([
            'recent_history' => ['harsh_driving_near_event' => true],
        ])['harsh_driving_near_event']);
    }

    public function test_gps_lost_in_motion_requires_stale_position_while_moving(): void
    {
        // Stale + moving = possible jamming.
        $this->assertTrue(SignalsBuilder::build([
            'telemetry' => ['position_stale' => true, 'speed_kph' => 60.0],
        ])['gps_lost_in_motion']);

        // Stale but parked: weak GPS, not jamming.
        $this->assertFalse(SignalsBuilder::build([
            'telemetry' => ['position_stale' => true, 'speed_kph' => 0.0],
        ])['gps_lost_in_motion']);

        // Moving with fresh position: nothing lost.
        $this->assertFalse(SignalsBuilder::build([
            'telemetry' => ['position_stale' => false, 'speed_kph' => 60.0],
        ])['gps_lost_in_motion']);

        // Unknown speed never counts.
        $this->assertFalse(SignalsBuilder::build([
            'telemetry' => ['position_stale' => true],
        ])['gps_lost_in_motion']);
    }

    public function test_nearby_safety_activity_requires_at_least_one_event(): void
    {
        $this->assertFalse(SignalsBuilder::build([
            'recent_history' => ['nearby_safety_events_count' => 0],
        ])['nearby_safety_activity']);

        $this->assertTrue(SignalsBuilder::build([
            'recent_history' => ['nearby_safety_events_count' => 3],
        ])['nearby_safety_activity']);
    }
}
