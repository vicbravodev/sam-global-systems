<?php

namespace Database\Seeders\Showcase\Support;

use Carbon\CarbonImmutable;

/**
 * Mezcla de eventos de la flota simulada y cómo reacciona el sistema a
 * cada tipo. Los pesos imitan una flota de carga regional: mucho exceso de
 * velocidad y frenado brusco, pocas emergencias reales, y un botón de
 * pánico que se aprieta por error casi un tercio de las veces.
 */
final class ShowcaseEventCatalog
{
    /**
     * código => [peso, etiqueta del proveedor, forma del payload]
     *
     * @var array<string, array{0: int|float, 1: string, 2: string}>
     */
    public const TYPES = [
        // safety (Samsara las clasifica; SAM no gasta IA en ellas)
        'speeding' => [14, 'Speeding', 'safety'],
        'harsh_braking' => [10, 'Braking', 'safety'],
        'harsh_acceleration' => [5, 'Acceleration', 'safety'],
        'harsh_turn' => [4, 'HarshTurn', 'safety'],
        'following_distance' => [6, 'FollowingDistance', 'safety'],
        'driver_distraction' => [5, 'GenericDistraction', 'safety'],
        'mobile_usage' => [4, 'MobileUsage', 'safety'],
        'driver_fatigue' => [3, 'Drowsy', 'safety'],
        'lane_departure' => [3, 'LaneDeparture', 'safety'],
        'forward_collision_warning' => [3, 'ForwardCollisionWarning', 'safety'],
        'rolling_stop' => [2, 'RollingStop', 'safety'],
        'near_collision' => [1.2, 'NearCollison', 'safety'],
        'severe_speeding' => [2, 'SevereSpeeding', 'safety'],
        // compliance
        'no_seatbelt' => [4, 'NoSeatbelt', 'safety'],
        'smoking_drinking' => [1, 'Smoking', 'safety'],
        'camera_obstructed' => [3, 'ObstructedCamera', 'safety'],
        'tampering' => [0.6, 'HighSpeedSuddenDisconnect', 'safety'],
        'hos_violation' => [1, 'HosViolation', 'safety'],
        // operational (monitores internos de SAM)
        'vehicle_idle' => [5, 'Idling', 'safety'],
        'geofence_exit' => [3, 'geofence_exit', 'internal'],
        'geofence_entry' => [3, 'geofence_entry', 'internal'],
        'after_hours_movement' => [1.5, 'after_hours_movement', 'internal'],
        'suspicious_stop' => [1.2, 'suspicious_stop', 'internal'],
        'unsafe_parking' => [1, 'UnsafeParking', 'safety'],
        // emergency
        'panic_button' => [1.6, 'AlertIncident', 'alert'],
        'collision' => [0.5, 'Crash', 'safety'],
        // maintenance
        'device_offline' => [3, 'device_offline', 'internal'],
    ];

    /**
     * @return array<string, float>
     */
    public static function weights(bool $includeOffline): array
    {
        $weights = [];

        foreach (self::TYPES as $code => [$weight]) {
            if ($code === 'device_offline' && ! $includeOffline) {
                continue;
            }

            $weights[$code] = $weight;
        }

        return $weights;
    }

    public static function scenarioFor(int $eventId, string $typeCode, string $categoryCode, array $skipCategories): EventScenario
    {
        $r = ShowcaseRandom::forKey("scenario:{$eventId}:{$typeCode}");
        $evaluate = ! in_array($categoryCode, $skipCategories, true);

        [$classification, $decision, $incidentType, $priority, $media] = match ($typeCode) {
            'panic_button' => (function () use ($r) {
                $c = $r->weighted(['real_event' => 62, 'false_positive' => 30, 'unclear' => 8]);

                return [$c, $c === 'real_event' && $r->chance(0.55) ? 'ESCALATE' : ($c === 'unclear' ? 'REQUIRE_HUMAN_REVIEW' : 'INCIDENT'), 'panic_emergency', 'critical', $r->chance(0.85)];
            })(),
            'collision' => (function () use ($r) {
                $c = $r->chance(0.85) ? 'real_event' : 'false_positive';

                return [$c, $c === 'real_event' ? 'ESCALATE' : 'REQUIRE_HUMAN_REVIEW', 'collision', 'critical', $r->chance(0.9)];
            })(),
            'tampering' => (function () use ($r) {
                $c = $r->chance(0.55) ? 'real_event' : 'unclear';

                return [$c, $c === 'real_event' ? 'INCIDENT' : 'REQUIRE_HUMAN_REVIEW', $c === 'real_event' ? 'compliance_violation' : null, 'high', $r->chance(0.5)];
            })(),
            'camera_obstructed' => (function () use ($r) {
                $c = $r->chance(0.6) ? 'real_event' : 'noise';

                return [$c, $c === 'real_event' ? 'INCIDENT' : 'LOG_ONLY', $c === 'real_event' ? 'camera_obstructed' : null, 'medium', $r->chance(0.7)];
            })(),
            'no_seatbelt', 'smoking_drinking', 'hos_violation' => (function () use ($r, $typeCode) {
                $c = $r->chance(0.8) ? 'real_event' : 'false_positive';
                $incident = $c === 'real_event' && $typeCode === 'hos_violation' && $r->chance(0.25);

                return [$c, $incident ? 'INCIDENT' : ($c === 'real_event' ? 'ALERT' : 'IGNORE'), $incident ? 'compliance_violation' : null, 'medium', false];
            })(),
            'after_hours_movement' => (function () use ($r) {
                $c = $r->weighted(['real_event' => 50, 'unclear' => 30, 'false_positive' => 20]);

                return [$c, match ($c) {
                    'real_event' => 'INCIDENT',
                    'unclear' => 'REQUIRE_HUMAN_REVIEW',
                    default => 'LOG_ONLY',
                }, $c === 'real_event' ? 'route_deviation' : null, 'high', $r->chance(0.2)];
            })(),
            'suspicious_stop' => (function () use ($r) {
                $c = $r->weighted(['real_event' => 40, 'unclear' => 35, 'false_positive' => 25]);

                return [$c, match ($c) {
                    'real_event' => 'INCIDENT',
                    'unclear' => 'REQUIRE_HUMAN_REVIEW',
                    default => 'ALERT',
                }, $c === 'real_event' ? 'suspicious_stop' : null, 'high', $r->chance(0.3)];
            })(),
            'geofence_exit' => (function () use ($r) {
                $incident = $r->chance(0.1);

                return ['real_event', $incident ? 'INCIDENT' : 'ALERT', $incident ? 'geofence_breach' : null, 'medium', false];
            })(),
            'geofence_entry', 'vehicle_idle', 'unsafe_parking' => ['noise', $r->chance(0.7) ? 'LOG_ONLY' : 'IGNORE', null, null, false],
            // Sin IA: los opera un humano. Algunos acaban en incidente manual.
            'driver_fatigue' => ['real_event', 'LOG_ONLY', $r->chance(0.12) ? 'driver_fatigue' : null, 'high', false],
            'near_collision' => ['real_event', 'LOG_ONLY', $r->chance(0.25) ? 'safety_violation' : null, 'high', false],
            default => ['real_event', 'LOG_ONLY', null, null, false],
        };

        $confidence = match ($classification) {
            'real_event' => $r->float(0.74, 0.98),
            'false_positive' => $r->float(0.6, 0.92),
            'noise' => $r->float(0.7, 0.95),
            default => $r->float(0.42, 0.62),
        };
        $risk = match ($classification) {
            'real_event' => $priority === 'critical' ? $r->float(82, 98, 1) : $r->float(55, 85, 1),
            'unclear' => $r->float(45, 70, 1),
            default => $r->float(5, 40, 1),
        };

        return new EventScenario(
            typeCode: $typeCode,
            evaluate: $evaluate,
            classification: $classification,
            confidence: $confidence,
            riskScore: $risk,
            aiPriority: match (true) {
                $classification === 'real_event' && $priority === 'critical' => 'urgent',
                $classification === 'real_event' && $priority === 'high' => 'high',
                in_array($classification, ['unclear'], true) => 'high',
                in_array($classification, ['noise', 'duplicate'], true) => 'low',
                default => 'normal',
            },
            recommendedAction: match ($decision) {
                'ESCALATE' => 'trigger_emergency_protocol',
                'INCIDENT' => $typeCode === 'panic_button' ? 'call_driver' : 'create_incident',
                'REQUIRE_HUMAN_REVIEW' => $media ? 'request_video_review' : 'escalate_to_operator',
                'ALERT' => 'notify_supervisor',
                default => 'ignore_event',
            },
            decisionCode: $decision,
            decisionPriority: match ($priority) {
                'critical' => 'critical',
                'high' => 'high',
                'medium' => 'normal',
                default => 'low',
            },
            requiresHumanReview: $decision === 'REQUIRE_HUMAN_REVIEW' || $classification === 'unclear',
            incidentType: $incidentType,
            incidentPriority: $incidentType !== null ? ($priority ?? 'medium') : null,
            withMedia: $media,
            humanOverride: $evaluate && $r->chance(0.07),
        );
    }

    /**
     * Payload crudo con la forma que manda el proveedor para ese canal.
     *
     * @param  array{id: string, name: string, driver_id: ?string, driver_name: ?string, lat: float, lng: float, address: string, speed: float}  $who
     * @return array<string, mixed>
     */
    public static function rawPayload(string $typeCode, string $externalId, CarbonImmutable $at, array $who, ShowcaseRandom $r): array
    {
        [, $label, $shape] = self::TYPES[$typeCode];

        return match ($shape) {
            'alert' => [
                'eventId' => $externalId,
                'eventTime' => $at->format('Y-m-d\TH:i:s.v\Z'),
                'eventType' => 'AlertIncident',
                'orgId' => 4006685,
                'webhookId' => 'showcase-webhook',
                'data' => [
                    'happenedAtTime' => $at->format('Y-m-d\TH:i:s\Z'),
                    'updatedAtTime' => $at->addSeconds(5)->format('Y-m-d\TH:i:s\Z'),
                    'isResolved' => false,
                    'configurationId' => 'showcase-panic-config',
                    'conditions' => [[
                        'triggerId' => 1034,
                        'description' => 'Panic Button',
                        'details' => ['panicButton' => ['vehicle' => [
                            'id' => $who['id'],
                            'name' => $who['name'],
                            'tags' => [['id' => '4849471', 'name' => 'MONITOREO 2']],
                        ]]],
                    ]],
                ],
            ],
            'internal' => [
                'eventType' => $typeCode,
                'time' => $at->toIso8601String(),
                'internal' => ['monitor' => match ($typeCode) {
                    'device_offline' => 'offline_watchdog',
                    'suspicious_stop' => 'stop_watch',
                    'after_hours_movement' => 'schedule_watch',
                    default => 'geofence_watch',
                }],
                'asset_name' => $who['name'],
                'location' => ['latitude' => $who['lat'], 'longitude' => $who['lng']],
                'silent_minutes' => $typeCode === 'device_offline' ? $r->int(190, 600) : null,
                'stopped_minutes' => $typeCode === 'suspicious_stop' ? $r->int(12, 55) : null,
            ],
            default => [
                'id' => $externalId,
                'asset' => ['id' => $who['id'], 'name' => $who['name']],
                'driver' => $who['driver_id'] !== null ? ['id' => $who['driver_id'], 'name' => $who['driver_name']] : null,
                'behaviorLabels' => [['label' => $label, 'source' => 'automated', 'name' => $label]],
                'eventState' => $r->weighted(['needsReview' => 55, 'needsCoaching' => 20, 'coached' => 15, 'dismissed' => 10]),
                'createdAtTime' => $at->format('Y-m-d\TH:i:s\Z'),
                'updatedAtTime' => $at->addMinutes($r->int(1, 30))->format('Y-m-d\TH:i:s\Z'),
                'location' => [
                    'latitude' => $who['lat'],
                    'longitude' => $who['lng'],
                    'address' => ['formattedAddress' => $who['address']],
                ],
                'maxAccelerationGForce' => $r->float(0.2, 0.9),
                'speedingMetadata' => in_array($typeCode, ['speeding', 'severe_speeding'], true) ? [
                    'maxSpeedKilometersPerHour' => $who['speed'],
                    'postedSpeedLimitKilometersPerHour' => $typeCode === 'severe_speeding' ? 60 : 80,
                ] : null,
            ],
        };
    }

    public static function sourceShape(string $typeCode): string
    {
        return self::TYPES[$typeCode][2] ?? 'safety';
    }
}
