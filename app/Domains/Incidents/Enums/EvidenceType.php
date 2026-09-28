<?php

namespace App\Domains\Incidents\Enums;

enum EvidenceType: string
{
    case Image = 'image';
    case Video = 'video';
    case Audio = 'audio';
    case Document = 'document';
    case EventSnapshot = 'event_snapshot';
    case TelemetrySnapshot = 'telemetry_snapshot';
    case AiExplanation = 'ai_explanation';
    case ExternalFile = 'external_file';
    // GPS points around the incident, frozen from the telematics history so
    // the retention purge never removes what the incident relies on.
    case LocationTrail = 'location_trail';
}
