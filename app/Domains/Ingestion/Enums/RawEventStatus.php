<?php

namespace App\Domains\Ingestion\Enums;

enum RawEventStatus: string
{
    case Received = 'received';
    case DuplicateDetected = 'duplicate_detected';
    case PendingProcessing = 'pending_processing';
    case Processing = 'processing';
    case Processed = 'processed';
    case Failed = 'failed';
    case Discarded = 'discarded';
    case InvalidSignature = 'invalid_signature';
    case Malformed = 'malformed';
}
