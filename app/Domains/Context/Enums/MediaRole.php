<?php

namespace App\Domains\Context\Enums;

enum MediaRole: string
{
    case PrimaryEvidence = 'primary_evidence';
    case SupportingEvidence = 'supporting_evidence';
    case PreEventContext = 'pre_event_context';
    case PostEventContext = 'post_event_context';
    case DriverFacing = 'driver_facing';
    case RoadFacing = 'road_facing';
    case CabinAudio = 'cabin_audio';
}
