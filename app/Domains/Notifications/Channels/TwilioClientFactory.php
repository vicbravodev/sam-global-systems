<?php

namespace App\Domains\Notifications\Channels;

use Twilio\Rest\Client;

/**
 * Thin factory so SMS / WhatsApp drivers can be tested with a mocked
 * Twilio client. Tests bind a fake instance against this class in the
 * container; production builds the real client from the channel config.
 */
class TwilioClientFactory
{
    /**
     * @param  array<string, mixed>  $config  Channel `config_json`. Expects
     *                                        `twilio_account_sid` (or `account_sid`) and
     *                                        `twilio_auth_token` (or `auth_token`).
     */
    public function make(array $config): Client
    {
        $sid = (string) ($config['twilio_account_sid'] ?? $config['account_sid'] ?? '');
        $token = (string) ($config['twilio_auth_token'] ?? $config['auth_token'] ?? '');

        return new Client($sid, $token);
    }
}
