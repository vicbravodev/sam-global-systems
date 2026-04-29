<?php

namespace App\Domains\Incidents\Enums;

enum EvidenceSourceType: string
{
    case EventContext = 'event_context';
    case EventMedia = 'event_media';
    case RawEvent = 'raw_event';
    case NormalizedEvent = 'normalized_event';
    case AiEvaluation = 'ai_evaluation';
    case ManualUpload = 'manual_upload';
    case ExternalProvider = 'external_provider';
}
