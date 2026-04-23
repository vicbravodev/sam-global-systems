<?php

namespace Tests\Unit\Domains\Context\Support;

use App\Domains\Context\Enums\GeofenceCategory;
use App\Domains\Context\Enums\GeofenceMatchType;
use App\Domains\Context\Support\SignalsBuilder;
use PHPUnit\Framework\TestCase;

class SignalsBuilderTest extends TestCase
{
    public function test_empty_context_returns_all_signals_with_safe_defaults(): void
    {
        $signals = SignalsBuilder::build([]);

        $this->assertFalse($signals['is_in_sensitive_geofence']);
        $this->assertFalse($signals['has_open_incident']);
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
}
