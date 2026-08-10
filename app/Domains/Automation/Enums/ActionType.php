<?php

namespace App\Domains\Automation\Enums;

use App\Contracts\HasLabel;

enum ActionType: string implements HasLabel
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

    public function label(): string
    {
        return match ($this) {
            self::SendEmail => 'Enviar correo',
            self::SendWhatsapp => 'Enviar WhatsApp',
            self::SendSms => 'Enviar SMS',
            self::SendPush => 'Notificación push',
            self::CreateTicket => 'Crear ticket',
            self::AssignIncident => 'Asignar incidente',
            self::Escalate => 'Escalar',
            self::UpdateAssetState => 'Actualizar estado del activo',
            self::RequestHumanReview => 'Pedir revisión humana',
            self::CallWebhook => 'Llamar webhook',
        };
    }
}
