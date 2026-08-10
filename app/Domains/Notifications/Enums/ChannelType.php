<?php

namespace App\Domains\Notifications\Enums;

use App\Contracts\HasLabel;

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
