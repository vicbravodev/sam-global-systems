<?php

namespace App\Domains\Integrations\Enums;

enum WebhookEventStatus: string
{
    case Received = 'received';
    case Processing = 'processing';
    case Processed = 'processed';
    case Failed = 'failed';
    case InvalidSignature = 'invalid_signature';
}
