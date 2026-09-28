<?php

namespace App\Domains\Notifications\Channels;

use App\Domains\Notifications\Support\PlatformTwilioConfig;
use Twilio\Rest\Client;

/**
 * Construye el cliente Twilio de la cuenta de PLATAFORMA (env TWILIO_*).
 * No hay credenciales por canal ni por tenant.
 */
class TwilioClientFactory
{
    public function make(): Client
    {
        return new Client(
            (string) PlatformTwilioConfig::accountSid(),
            (string) PlatformTwilioConfig::authToken(),
        );
    }
}
