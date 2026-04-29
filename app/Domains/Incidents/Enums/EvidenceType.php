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
}
