<?php

namespace App\Domains\Notifications\Enums;

enum RecipientType: string
{
    case User = 'user';
    case Team = 'team';
    case Queue = 'queue';
    case ExternalContact = 'external_contact';
    case WebhookEndpoint = 'webhook_endpoint';
    case SlackChannel = 'slack_channel';
}
