<?php

namespace App\Domains\Notifications\Enums;

use App\Contracts\HasLabel;
use App\Domains\Incidents\Jobs\PlaceVerificationCallJob;

enum ChannelType: string implements HasLabel
{
    case Email = 'email';
    case Sms = 'sms';
    case Push = 'push';
    case Whatsapp = 'whatsapp';
    case Web = 'web';
    case Slack = 'slack';
    case Webhook = 'webhook';
    case Voice = 'voice';

    /**
     * Messaging channels carry a per-message provider fee (Twilio) and bill on
     * their own meter; the rest stay on the generic outbound_notifications
     * meter. Distinct from `voice_calls`, which meters incident DTMF
     * verification calls ({@see PlaceVerificationCallJob}).
     */
    public function usageMeterCode(): string
    {
        return match ($this) {
            self::Sms => 'sms_messages',
            self::Whatsapp => 'whatsapp_messages',
            self::Voice => 'voice_notification_calls',
            default => 'outbound_notifications',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Correo',
            self::Sms => 'SMS',
            self::Push => 'Push',
            self::Whatsapp => 'WhatsApp',
            self::Web => 'Web',
            self::Slack => 'Slack',
            self::Webhook => 'Webhook',
            self::Voice => 'Voz',
        };
    }
}
