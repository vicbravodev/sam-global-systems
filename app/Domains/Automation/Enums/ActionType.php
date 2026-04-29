<?php

namespace App\Domains\Automation\Enums;

enum ActionType: string
{
    case SendEmail = 'send_email';
    case SendWhatsapp = 'send_whatsapp';
    case SendSms = 'send_sms';
    case SendPush = 'send_push';
    case CreateTicket = 'create_ticket';
    case AssignIncident = 'assign_incident';
    case Escalate = 'escalate';
    case UpdateAssetState = 'update_asset_state';
    case RequestHumanReview = 'request_human_review';
    case CallWebhook = 'call_webhook';
}
