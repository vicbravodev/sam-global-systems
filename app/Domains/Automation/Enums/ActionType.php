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

    /** Error de validación al pedir una acción diferida (ver isDeferred()). */
    public const string DEFERRED_MESSAGE = '«Crear ticket» y «Actualizar estado del activo» todavía no están disponibles: elige otra acción.';

    /**
     * Acciones sin implementación real: ExecuteAction las cierra como stub
     * `deferred_v2`. Siguen en el enum porque puede haber filas que las
     * referencien, pero no se ofrecen ni se aceptan en workflows o plantillas.
     */
    public function isDeferred(): bool
    {
        return $this === self::CreateTicket || $this === self::UpdateAssetState;
    }

    /**
     * @return list<self>
     */
    public static function configurable(): array
    {
        return array_values(array_filter(self::cases(), fn (self $type): bool => ! $type->isDeferred()));
    }

    /**
     * @return list<string>
     */
    public static function deferredValues(): array
    {
        return array_values(array_map(
            fn (self $type): string => $type->value,
            array_filter(self::cases(), fn (self $type): bool => $type->isDeferred()),
        ));
    }

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
