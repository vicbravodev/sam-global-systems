<?php

namespace App\Domains\Ingestion\Enums;

enum EventSourceType: string
{
    case Webhook = 'webhook';
    case Polling = 'polling';
    case PollingFeed = 'polling_feed';
    case BatchImport = 'batch_import';
    case ApiPull = 'api_pull';
    case MessageQueue = 'message_queue';
}
