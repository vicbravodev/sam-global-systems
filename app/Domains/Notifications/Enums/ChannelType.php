<?php

namespace App\Domains\Notifications\Enums;

enum ChannelType: string
{
    case Email = 'email';
    case Sms = 'sms';
    case Push = 'push';
    case Whatsapp = 'whatsapp';
    case Web = 'web';
    case Slack = 'slack';
    case Webhook = 'webhook';
    case Voice = 'voice';
}
