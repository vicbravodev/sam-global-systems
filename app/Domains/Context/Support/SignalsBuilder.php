<?php

namespace App\Domains\Context\Support;

use App\Domains\Context\Enums\GeofenceCategory;
use App\Domains\Context\Enums\GeofenceMatchType;
use App\Domains\Context\Enums\IncidentRelationType;

class SignalsBuilder
{
    /**
     * Builds the `signals_json` boolean flag map from assembled context snapshots.
     *
     * Media-related flags (`has_visual_evidence`, `has_audio_evidence`, `video_pending`,
     * `media_delayed`, `visual_confirmation_possible`) are computed from `media_snapshot_json`
     * which lands in PR #2. In PR #1 they always return false / `no_media_available = true`.
     *
     * @param  array<string, mixed>  $context  {
     *
     * @var array<int, array<string, mixed>> $geofence_matches  Geofence match rows with `category` and `match_type`.
     * @var array<int, array<string, mixed>> $incidents  Related incident rows: open ones produced by `GetRelatedOpenIncidents` plus closed prior ones (marked `relation = prior_similar_incident`) produced by `GetPriorSimilarIncidents`.
     * @var array<string, mixed> $recent_history  Recent history snapshot counts.
     * @var array<string, mixed> $driver  Driver operational context.
     * @var array<string, mixed> $asset  Asset snapshot (for camera/operating hours signals).
     * @var array<string, mixed> $telemetry  Telemetry snapshot (speed, gps accuracy).
     * @var array<int, array<string, mixed>> $media  Media contexts (deferred, expect empty in PR #1).
     * @var bool|null $outside_operating_hours  Optional precomputed flag.
     *                }
     *
     * @return array<string, bool>
     */
    public static function build(array $context): array
    {
        $geofenceMatches = $context['geofence_matches'] ?? [];
        $incidents = $context['incidents'] ?? [];
        $recentHistory = $context['recent_history'] ?? [];
        $driver = $context['driver'] ?? [];
        $asset = $context['asset'] ?? [];
        $telemetry = $context['telemetry'] ?? [];
        $media = $context['media'] ?? [];
        $event = $context['event'] ?? [];

        return [
            'is_in_sensitive_geofence' => self::isInSensitiveGeofence($geofenceMatches),
            'has_open_incident' => self::hasOpenIncident($incidents),
            'has_prior_similar_incident' => self::hasPriorSimilarIncident($incidents),
            'same_type_recent_recurrence' => ($recentHistory['recent_same_type_count'] ?? 0) > 0,
            'driver_has_recent_risk_events' => (bool) ($driver['has_recent_risk_events'] ?? false),
            'camera_unavailable' => self::cameraUnavailable($asset),
            'gps_signal_weak' => self::gpsSignalWeak($telemetry),
            'outside_operating_hours' => (bool) ($context['outside_operating_hours'] ?? false),
            'asset_recently_stopped' => self::assetRecentlyStopped($telemetry),
            'asset_in_motion' => self::assetInMotion($telemetry),
            'driver_unresolved_previous_alert' => (bool) ($driver['has_unresolved_alerts'] ?? false),
            'has_visual_evidence' => self::hasMediaOfType($media, ['image', 'video', 'snapshot', 'clip'], 'available'),
            'has_audio_evidence' => self::hasMediaOfType($media, ['audio'], 'available'),
            'video_pending' => self::hasMediaWithStatus($media, ['requested', 'processing']),
            'media_delayed' => self::hasMediaWithStatus($media, ['delayed']),
            'no_media_available' => self::noMediaAvailable($media),
            'visual_confirmation_possible' => self::visualConfirmationPossible($asset, $media),
            'external_resolved' => ($event['is_resolved'] ?? null) === true,
            'parked_at_base' => self::parkedAtBase($geofenceMatches, $telemetry),
            'repeated_panic_24h' => ($recentHistory['repeated_panic_count_24h'] ?? 0) > 1,
            // Safety correlation (Roadmap V2-A2): harsh maneuvers around the
            // event weigh toward a real assault / forced-stop scenario.
            'harsh_driving_near_event' => (bool) ($recentHistory['harsh_driving_near_event'] ?? false),
            'nearby_safety_activity' => ($recentHistory['nearby_safety_events_count'] ?? 0) > 0,
        ];
    }

    /**
     * The asset sits inside one of the fleet's own base geofences with no
     * speed — a panic from a parked unit at home is a strong false-alarm
     * signal (Roadmap B6-P7). Requires an explicit ~zero speed reading: an
     * unknown speed never counts as parked.
     *
     * @param  array<int, array<string, mixed>>  $matches
     * @param  array<string, mixed>  $telemetry
     */
    private static function parkedAtBase(array $matches, array $telemetry): bool
    {
        $speed = $telemetry['speed_kph'] ?? null;

        if (! is_numeric($speed) || (float) $speed > 1.0) {
            return false;
        }

        foreach ($matches as $match) {
            $matchType = $match['match_type'] ?? null;
            $matchTypeValue = $matchType instanceof GeofenceMatchType ? $matchType->value : $matchType;

            if (! in_array($matchTypeValue, [GeofenceMatchType::Inside->value, GeofenceMatchType::Entry->value], true)) {
                continue;
            }

            $category = $match['category'] ?? null;
            $categoryValue = $category instanceof GeofenceCategory ? $category->value : $category;
            $geofenceCategory = is_string($categoryValue) ? GeofenceCategory::tryFrom($categoryValue) : null;

            if ($geofenceCategory !== null && $geofenceCategory->isBase()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $incidents
     */
    private static function hasOpenIncident(array $incidents): bool
    {
        foreach ($incidents as $incident) {
            if (($incident['relation'] ?? null) !== IncidentRelationType::PriorSimilarIncident->value) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $incidents
     */
    private static function hasPriorSimilarIncident(array $incidents): bool
    {
        foreach ($incidents as $incident) {
            if (($incident['relation'] ?? null) === IncidentRelationType::PriorSimilarIncident->value) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $matches
     */
    private static function isInSensitiveGeofence(array $matches): bool
    {
        foreach ($matches as $match) {
            $category = $match['category'] ?? null;
            $matchType = $match['match_type'] ?? null;

            if ($matchType !== null && ! in_array(
                $matchType instanceof GeofenceMatchType ? $matchType->value : $matchType,
                [GeofenceMatchType::Inside->value, GeofenceMatchType::Entry->value, GeofenceMatchType::NearBoundary->value],
                true,
            )) {
                continue;
            }

            $categoryValue = $category instanceof GeofenceCategory ? $category->value : $category;
            $geofenceCategory = is_string($categoryValue) ? GeofenceCategory::tryFrom($categoryValue) : null;

            if ($geofenceCategory !== null && $geofenceCategory->isSensitive()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $asset
     */
    private static function cameraUnavailable(array $asset): bool
    {
        $status = $asset['camera_status'] ?? null;

        return in_array($status, ['offline', 'obstructed', 'unavailable'], true);
    }

    /**
     * @param  array<string, mixed>  $telemetry
     */
    private static function gpsSignalWeak(array $telemetry): bool
    {
        $accuracy = $telemetry['gps_accuracy_meters'] ?? null;
        $isStale = (bool) ($telemetry['position_stale'] ?? false);

        if ($isStale) {
            return true;
        }

        return is_numeric($accuracy) && (float) $accuracy > 50.0;
    }

    /**
     * @param  array<string, mixed>  $telemetry
     */
    private static function assetRecentlyStopped(array $telemetry): bool
    {
        $recentSpeeds = $telemetry['recent_speeds'] ?? null;

        if (is_array($recentSpeeds) && count($recentSpeeds) > 0) {
            foreach ($recentSpeeds as $speed) {
                if (is_numeric($speed) && (float) $speed <= 0.1) {
                    return true;
                }
            }

            return false;
        }

        $speed = $telemetry['speed_kph'] ?? null;

        return is_numeric($speed) && (float) $speed <= 0.1;
    }

    /**
     * @param  array<string, mixed>  $telemetry
     */
    private static function assetInMotion(array $telemetry): bool
    {
        $speed = $telemetry['speed_kph'] ?? null;

        return is_numeric($speed) && (float) $speed > 0.1;
    }

    /**
     * @param  array<int, array<string, mixed>>  $media
     * @param  array<int, string>  $types
     */
    private static function hasMediaOfType(array $media, array $types, string $requiredStatus): bool
    {
        foreach ($media as $item) {
            $type = $item['type'] ?? null;
            $status = $item['retrieval_status'] ?? null;

            if (in_array($type, $types, true) && $status === $requiredStatus) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $media
     * @param  array<int, string>  $statuses
     */
    private static function hasMediaWithStatus(array $media, array $statuses): bool
    {
        foreach ($media as $item) {
            if (in_array($item['retrieval_status'] ?? null, $statuses, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $media
     */
    private static function noMediaAvailable(array $media): bool
    {
        if ($media === []) {
            return true;
        }

        foreach ($media as $item) {
            if (($item['retrieval_status'] ?? null) === 'available') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $asset
     * @param  array<int, array<string, mixed>>  $media
     */
    private static function visualConfirmationPossible(array $asset, array $media): bool
    {
        if (self::cameraUnavailable($asset)) {
            return false;
        }

        $hasCamera = (bool) ($asset['has_camera'] ?? false);

        if (! $hasCamera) {
            return false;
        }

        return ! self::hasMediaOfType($media, ['image', 'video', 'snapshot', 'clip'], 'available');
    }
}
